<?php
require_once __DIR__.'/helper.php';
require_once __DIR__.'/../redis_helper.php';
require_once __DIR__.'/../GatewayClient/Gateway.php';

use GatewayClient\Gateway;
/**
* receive request from qiyu server
*/
class Receiver {
    protected $appconfig;
    
    function __construct() {
        $this->appconfig = json_decode(file_get_contents(__DIR__.'/app_config.json'), true);
        Gateway::$registerAddress = '127.0.0.1:1238';
    }

    //七鱼请求处理方法
    public function handler(){
        $json_data = file_get_contents("php://input");
        if (!$this->checksum($_GET['checksum'], $json_data, $_GET['time'])){
            echo '';
            return ;
        }
        $event = $_GET['eventType'];
        $function = new ReflectionMethod(get_called_class(), 'event_'.strtolower($event));
        $function->invoke($this, json_decode($json_data, true));
    }

    public function event_msg($post_data){
        $redis_helper = new RedisHelper();
        $redis = $redis_helper->connect_redis('connect');
        if ($redis->zScore($post_data['msgId']) !== false) {
            //消息验重
            echo '';
            return false;
        }
        $redis->zAdd('msgId', $post_data['msgId'], $post_data['timeStamp']);
        //发送给用户
        $staff = explode('`', $redis->hGet('staffInfo', $post_data['staffId']));
        $chatContent = [
            'type' => 'say',
            'msg' => [
                [
                    'contentType' => $post_data['msgType'],
                    'content' => $post_data['content'],
                    'isMe' => false,
                    'avatar' => $staff[1]
                ]
            ]
        ];
        Gateway::sendToUid($post_data['uid'], json_encode($chatContent));
        $this->addStaffChatRecord($redis, $post_data, $staff[1]);
        
        $redis_helper->close_redis($redis);
        echo '';
    }

    public function event_session_start($post_data){
        echo '';
    }

    public function event_session_end($post_data){
        echo '';
    }

    public function event_eva_invitation($post_data){
        echo '';
    }

    public function event_user_join_queue($post_data){
        echo '';
    }

    public function event_queue_timeout($post_data){
        echo '';
    }

    public function addStaffChatRecord($redis, $post_data, $avatar){
        $record = [
            'isMe' => 'not',
            'staffId' => $post_data['staffId'],
            'msgType' => $post_data['msgType'],
            'content' => $post_data['content'],
            'staff_avatar' => $avatar
        ];
        $redis->lPush('chatRecord:'.$post_data['uid'], implode('`', $record));
        //仅保留最新的30条数据
        $redis->lTrim('chatRecord:'.$post_data['uid'], 0, 29);
        return ;
    }

    //验签
    public function checksum($checksum, $json_data, $time){
        if($checksum == Helper::getChecksum( $this->appconfig['secret'], $json_data, $time)){
            return true;
        }
        return false;
    } 
}