<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

require_once __DIR__.'/redis_helper.php';
require_once __DIR__.'/qiyuApi/sender.php';

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

use \GatewayWorker\Lib\Gateway;

class Events{
    public static $db = null;

    public static $redis = null;
    /**
     * worker启动时触发
     */
    public static function onWorkerStart($businessWorker){
        $dbConfig = json_decode(file_get_contents(__DIR__.'/db_config.json'), true)['mysql'];
        self::$db = new Workerman\MySQL\Connection($dbConfig['host'], $dbConfig['port'], $dbConfig['user'], $dbConfig['password'], $dbConfig['db_name']);
        $redis_helper = new RedisHelper();
        self::$redis = $redis_helper->connect_redis('connect');
    }

    /**
    * 当客户端连接时触发
    * 如果业务不需此回调可以删除onConnect
    * 
    * @param int $client_id 连接id
    */
    public static function onConnect($client_id) {
        // 向当前client_id发送数据 
        Gateway::sendToClient($client_id, json_encode(['hello' => $client_id]));
    }

    /**
    * 当客户端发来消息时触发
    * @param int $client_id 连接id
    * @param mixed $message 具体消息
    */
    public static function onMessage($client_id, $message) {
        $msgData = json_decode($message, true);
        //check user
        if (empty($msgData['token'])) {
            Gateway::closeClient($client_id);
            return false;
        }
        $user_str = self::$redis->hGet('loginUser', $msgData['token']);
        if (empty($user_str)) {
            Gateway::closeClient($client_id);
            return false;
        }
        $userLoginInfo = explode('`', $user_str);
        $userLoginInfo['client_id'] = $client_id;
        self::$redis->hSet('clientid2uid', $client_id, $userLoginInfo[0]);
        //update token's timestamp
        self::$redis->zAdd('recentUser', time(), $msgData['token']);

        $functionName = 'event'.ucwords($msgData['type']);
        self::$functionName($msgData, $userLoginInfo);
    }

    //初始登录+分配客服
    public static function eventUserInfo($msgData, $userLoginInfo){
        $uid = $userLoginInfo[0];
        //bind uid to client_id
        $clientIds = Gateway::getClientIdByUid($uid);
        if (!empty($clientIds)) {
            //bind one uid to one clientId at the same time
            foreach ($clientIds as $clientId) {
                Gateway::closeClient($clientId);
            }
        }
        Gateway::bindUid($userLoginInfo['client_id'], $uid);

        //get userInfo
        $user = self::getUserInfo($uid);
        
        //send userInfo to user(with chat record if there is)
        $userInfo = [
            'type' => 'userInfo',
            'user' => $user,
            'record' => self::getRecordByPage($uid, 1, $msgData['msg']['limit'])
        ];
        Gateway::sendToClient($userLoginInfo['client_id'], json_encode($userInfo));

        //apply staff
        $sender = new Sender();
        $msgContent = [
            'uid' => $uid,
            'staffType' => 1
        ];
        $staffResult = $sender->applyStaff($msgContent);
        if ($staffResult['code'] == 200) {
            Gateway::sendToClient($userLoginInfo['client_id'], json_encode([
                'type' => 'notice',
                'msg' => 'session_start',
                'staffId' => $staffResult['staffId']
            ]));
            if (isset($staffResult['message'])) {
                $chat = [
                            'isMe' => false,
                            'staffId' => $staffResult['staffId'],
                            'contentType' => 'TEXT',
                            'content' => $staffResult['message'],
                            'avatar' => $staffResult['staffIcon']
                ];
                $chatContent = [
                    'type' => 'say',
                    'msg' => [$chat]
                ];
                Gateway::sendToClient($userLoginInfo['client_id'], json_encode($chatContent));
                self::addChatRecord($chat, $uid);
            }
        }elseif ($staffResult['code'] == 14005) {
            Gateway::sendToClient($userLoginInfo['client_id'], json_encode([
                'type' => 'notice',
                'msg' => 'leave_msg'
            ]));
        }
        return ;
    }

    //发送消息
    public static function eventSay($msgData, $userLoginInfo){
        $sender = new sender();
        $msgContent = [
            'uid' => $userLoginInfo[0],
            'msgType' => $msgData['msg']['contentType'],
            'content' => $msgData['msg']['content']
        ];
        $result = $sender->pushMsg($msgContent);
        $chat = [
                    'isMe' => 'is',
                    'uid' => $userLoginInfo[0],
                    'contentType' => $msgData['msg']['contentType'],
                    'content' => $msgData['msg']['content']
        ];
        self::addChatRecord($chat, $userLoginInfo[0]);
        return ;
    }

    //历史消息
    public static function eventHistory($msgData, $userLoginInfo){
        $history = [
            'type' => 'history',
            'historyType' => $msgData['historyType'],
            'record' => self::getRecordByPage($userLoginInfo[0], $msgData['msg']['page'], $msgData['msg']['limit'])
        ];
        Gateway::sendToClient($userLoginInfo['client_id'], json_encode($history));
        return ;
    }

    //切换客服
    public static function eventSwitchStaff($msgData, $userLoginInfo){
        //check switch times
        $user = self::$db->select('uid,switch_times,switch_staffs')->from('users')->where('uid= :uid')->bindValues(['uid'=>$userLoginInfo[0]])->query()[0];
        if ($user['switch_times'] >= 3) {
            Gateway::sendToClient($userLoginInfo['client_id'], json_encode([
                'type' => 'switchStaff',
                'code' => 233,
                'msg' => 'switch times exceeded'
            ]));
            return ;
        }

        //get online staff (maybe get it from redis first)
        $sender = new sender();
        $result = $sender->getOnlineStaff(['groupIds' => []]);
        if ($result['code'] == 200) {
            if ($user['switch_staffs']) {
                $outStaffIds = explode(',', $user['switch_staffs']);
            }
            $outStaffIds[] = $msgData['staffId'];
            $staffId = self::getStaffId($result['list'], $outStaffIds);
        }

        //apply staff
        if (!empty($staffId)) {
            $msgContent = [
                'uid' => $userLoginInfo[0],
                'staffType' => 1,
                'staffId' => $staffId
            ];
            $staffResult = $sender->applyStaff($msgContent);
            if ($staffResult['code'] == 200) {
                $strOutStaffIds = implode(',', $outStaffIds);
                self::$db->query("UPDATE users SET switch_times=switch_times+1,switch_staffs={$strOutStaffIds} WHERE uid={$userLoginInfo[0]}");
            }
        }else{
            Gateway::sendToClient($userLoginInfo['client_id'], json_encode([
                'type' => 'switchStaff',
                'code' => 404,
                'msg' => 'no available staff'
            ]));
        }
        return ;
    }

    //投诉客服
    public static function eventComplain($msgData, $userLoginInfo){
        self::$db->insert('complain')->cols([
            'select_content' => $msgData['select_content'],
            'content' => $msgData['content'],
            'staff_id' => $msgData['staffId'],
            'uid' => $userLoginInfo[0],
            'create_at' => time()
        ])->query();
        return ;
    }

    //心跳，用于维持连接
    public static function eventHeartbeat($msgData, $userLoginInfo){
        Gateway::sendToClient($userLoginInfo['client_id'], '{"msg":"hehe"}');
        return ;
    }

    public static function getRecordByPage($uid, $page, $limit){
        $chats = self::$redis->lRange('chatRecord:'.$uid, $limit*($page-1), $page*$limit-1);
        if (empty($chats)) {
            return '';
        }
        foreach ($chats as $chat) {
            $chat_arr = explode('`', $chat);
            $records[] = [
                'isMe' => ($chat_arr[0] == 'not') ? false : true,
                'contentType' => $chat_arr[2],
                'content' => $chat_arr[3],
                'avatar' => ($chat_arr[0] == 'not') ? $chat_arr[4] : '',
            ];
        }
        return $records;
    }

    public static function addChatRecord($record, $uid){
        self::$redis->lPush('chatRecord:'.$uid, implode('`', $record));
        //仅保留最新的30条数据
        self::$redis->lTrim('chatRecord:'.$uid, 0, 29);
        return ;
    }

    public static function getStaffId($onlineStaffList, $outStaffIds){
        foreach ($onlineStaffList as $staff) {
            if ($staff['role'] == 0  
            && (!in_array($staff['staffId'], $outStaffIds))
            && $staff['status'] == 1) {
                $idList[] = $staff['staffId'];
            }
        }
        if (!isset($idList)) {
            return ;
        }
        return $idList[array_rand($idList)];
    }

    public static function getUserInfo($uid){
        //get user answer and question
        $answers = [];
        $userAnswer = self::$db->query("select ua.uid,ua.question_id,ua.answer,q.content,q.option,q.sort from user_answer as ua, questions as q where q.status=1 and q.question_id=ua.question_id and ua.uid=".$uid." order by q.sort");
        foreach ($userAnswer as $user) {
            $options = json_decode($user['option'], true);
            foreach ($options as $option) {
                foreach ($option as $key => $content) {
                    if ($user['answer'] == $key) {
                        $answers[] = [$user['sort'] => $content];
                    }
                }
           }
        }
        $userInfo = [
            'isMe' => true,
            'contentType' => 'INFO_CARD',
            'content' => $answers,
        ];
        return $userInfo;
    }

    /**
    * 当用户断开连接时触发
    * @param int $client_id 连接id
    */
    public static function onClose($client_id) {
        // send request to qiyu that user logout
        $uid = self::$redis->hget('clientid2uid', $client_id);
        self::$redis->hDel('clientid2uid', $client_id);
        $msgContent = [
            'uid' => $uid,
            'msgType' => 'TEXT',
            'content' => '#系统消息#用户已离开会话'
        ];
        $sender = new sender();
        $result = $sender->pushMsg($msgContent);
    }
}
