<?php
require_once 'helper.php';
require_once '../redis_helper.php';

use qiyu\helper\Helper;
/**
* receive request from qiyu server
*/
class Receiver {
    protected $appconfig;
    
    function __construct() {
        $this->appconfig = require(__DIR__ . 'qiyuApi/app_config.php');
    }

    //请求处理方法
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

    public function event_msg(){
        echo '';
    }

    public function event_session_start(){
        echo '';
    }

    public function event_session_end(){
        echo '';
    }

    public function event_eva_invitation(){
        echo '';
    }

    public function event_user_join_queue(){
        echo '';
    }

    public function event_queue_timeout(){
        echo '';
    }

    //验签
    public function checksum($checksum, $appsecrect, $json_data, $time){
        if($checksum == Helper::getChecksum($appsecrect, $json_data, $time)){
            return true;
        }
        return false;
    } 
}