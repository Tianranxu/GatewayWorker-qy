<?php 

//接收七鱼服务器请求的入口文件

header ( "Content-type:text/html;charset=utf-8" );

require_once 'receiver.php';

$obj = new Receiver();
$obj->handler();