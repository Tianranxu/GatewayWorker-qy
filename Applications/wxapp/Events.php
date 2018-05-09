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
require_once './qiyuApi/sender.php';

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

use \GatewayWorker\Lib\Gateway;

/**
 * 主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * onConnect 和 onClose 如果不需要可以不用实现并删除
 */
class Events{
    public static $db = null;

    /**
     * worker启动时触发
     */
    public static function onWorkerStart($businessWorker){
        $dbConfig = require('./db_config')['mysql'];
        self::$db = new Workerman\MySQL\Connection($dbConfig['host'], $dbConfig['port'], $dbConfig['user'], $dbConfig['password'], $dbConfig['db_name']);
    }

    /**
    * 当客户端连接时触发
    * 如果业务不需此回调可以删除onConnect
    * 
    * @param int $client_id 连接id
    */
    public static function onConnect($client_id) {
        // 向当前client_id发送数据 
        Gateway::sendToClient($client_id, "Hello $client_id\n");
    }

    /**
    * 当客户端发来消息时触发
    * @param int $client_id 连接id
    * @param mixed $message 具体消息
    */
    public static function onMessage($client_id, $message) {
        $msgData = json_decode($message, true);
        $redis_helper = new RedisHelper();
        $redis = $redis_helper->connect_redis('pconnect');
        //check user
        if (empty($msgData['token'])) {
            Gateway::closeClient($client_id);
            return false;
        }
        $userLoginInfo = explode('`', $redis->hGet('loginUser', $msgData['token']));
        if (empty($userLoginInfo)) {
            Gateway::closeClient($client_id);
            return false;
        }
        $userLoginInfo['client_id'] = $client_id;
        //update token's timestamp
        $redis->zAdd('recentUser', $msgData['token'], time());

        $functionName = 'event'.ucwords($msgData['type']);
        self::$functionName($msgData, $userLoginInfo, $redis);

        /*TODO 
        1.userInfo(bind uid to client_id; get userInfo to send current user(with history in page 1) send request to customer service; add client_staff;)
        2.history(page) 
        3.hearbeat 
        4.say(send request to qiyu and save in redis)(contentType:text,img)
        5.user behavior
        */
    }

    public static function eventUserInfo($msgData, $userLoginInfo, $redis){
        $uid = $userLoginInfo[0];
        //bind uid to client_id
        $clientIds = Gateway::getClientIdByUid($uid);
        if (!empty($clientIds)) {
            foreach ($clientIds as $clientId) {
                Gateway::closeClient($clientId);
            }
        }
        Gateway::bindUid($userLoginInfo['client_id'], $uid);

        //get userInfo
        $user = self::$db->select('uid,user_type,avatarUrl')->from('users')->where('uid= :uid')->bindValues(array('uid'=>$uid))->query();
        
        //send userInfo to user(with chat record if there is)
        $userInfo = [
            'type' => 'userInfo',
            'user' => $user,
            'record' => self::getRecordByPage($uid, 1, $msgData['limit'], $redis)
        ];
        Gateway::sendToClient($userLoginInfo['client_id'], json_encode($userInfo));

        //apply staff
        $sender = new Sender();
        $msgContent = [
            'uid' => $uid,
            'staffType' => 1
        ];
        $staffResult = $sender->applyStaff($msgContent);
        if ($staffResult['code'] == '200') {
            $redis->hSet('client_staff', $userLoginInfo['client_id'], $staffResult['staffId']); //or robotId
            $chat = [
                        'isMe' => false,
                        'staffId' => $post_data['staffId'],
                        'contentType' => 'TEXT',
                        'content' => $staffResult['message'],
                        'avatar' => $staffResult['staffIcon']
            ];
            $chatContent = [
                'type' => 'say',
                'msg' => [$chat]
            ];
            Gateway::sendToClient($userLoginInfo['client_id'], json_encode($chatContent));
            self::addChatRecord($redis, $chat, $uid);
        }
        return ;
    }

    public static function eventSay($msgData, $userLoginInfo, $redis){
        $sender = new sender();
        $msgContent = [
            'uid' => $userLoginInfo[0],
            'msgType' => $msgData['contentType'],
            'content' => $msgData['content']
        ];
        $sender->pushMsg($msgContent);
        $chat = [
                    'isMe' => 'is',
                    'uid' => $userLoginInfo[0],
                    'contentType' => $msgData['contentType'],
                    'content' => $msgData['content']
        ];
        self::addChatRecord($redis, $chat, $userLoginInfo[0]);
        return ;
    }

    public static function eventHistory($msgData, $userLoginInfo, $redis){
        $history = [
            'type' => 'history',
            'record' => self::getRecordByPage($userLoginInfo, $msgData['page'], $msgData['limit'], $redis)
        ];
        Gateway::sendToClient($userLoginInfo['client_id'], json_encode($history));
        return ;
    }

    public static function eventHeartbeat($msgData, $userLoginInfo, $redis){
        return ;
    }

    public static function getRecordByPage($uid, $page, $limit, $redis){
        $chats = $redis->lRange('chatRecord:'.$uid, $limit*($page-1), $page*$limit-1);
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

    public static function addChatRecord($redis, $record, $uid){
        $redis->lPush('chatRecord:'.$uid, implode('`', $record));
        //仅保留最新的30条数据
        $redis->lTrim('chatRecord:'.$uid, 0, 29);
        return ; 
    }

    /**
    * 当用户断开连接时触发
    * @param int $client_id 连接id
    */
    public static function onClose($client_id) {
        $redis_helper = new RedisHelper();
        $redis = $redis_helper->connect_redis('pconnect');
        $staff = $redis->hGet('client_staff', $client_id);
        if (empty($staff)) {
            return ;
        }
        $redis->hDel('client_staff', $client_id);
        //TODO send request to qiyu that user logout
    }

}
