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

    public static $redis = null;
    /**
    * 当客户端连接时触发
    * 如果业务不需此回调可以删除onConnect
    * 
    * @param int $client_id 连接id
    */
    public static function onConnect($client_id) {
        // 向当前client_id发送数据 
        //Gateway::sendToClient($client_id, "Hello $client_id\n");
    }

    /**
    * 当客户端发来消息时触发
    * @param int $client_id 连接id
    * @param mixed $message 具体消息
    */
    public static function onMessage($client_id, $message) {
        require_once './qiyuApi/send.php';
        $msgData = json_decode($message, true);
        $redis_helper = new RedisHelper();
        $redis = $redis_helper->connect_redis('pconnect');
        //check user
        if (empty($msgData['token'])) {
            Gateway::closeClient($client_id);
            return false;
        }
        $userInfo = $redis->hGet('loginUser', $msgData['token']);
        if (empty($userInfo)) {
            Gateway::closeClient($client_id);
            return false;
        }
        //update token's timestamp
        $redis->zAdd('recentUser', $msgData['token'], time());

        $function = new ReflectionMethod(get_called_class(), 'event'.ucwords($msgData['type']));
        $function->invoke($this, $msgData, $userInfo, $redis);

        /*TODO 
        1.userInfo(get userInfo to send request to customer service and send current user; bind uid to client_id;add session_staff; get history in page 1)
        2.history(page) 
        3.hearbeat 
        4.say(send request to qiyu and save in db)(contentType:text,img)*/
    }

    public function eventUserInfo($msgData, $userInfo, $redis){

        return ;
    }

    public function eventSay($msgData, $userInfo, $redis){
        return ;
    }

    public function eventHistory($msgData, $userInfo, $redis){
        return ;
    }

    public function eventHeartbeat($msgData, $userInfo, $redis){
        return ;
    }

    /**
    * 当用户断开连接时触发
    * @param int $client_id 连接id
    */
    public static function onClose($client_id) {
        $redis_helper = new RedisHelper();
        $redis = $redis_helper->connect_redis('pconnect');
        $staff = $redis->hGet('session_staff', $client_id);
        if (empty($staff)) {
            return ;
        }
        $redis->hDel('session_staff', $client_id);
        //TODO send request to qiyu that user logout
    }

}
