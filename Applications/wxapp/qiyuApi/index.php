<?php 

//接收七鱼服务器请求的入口文件

header ( "Content-type:text/html;charset=utf-8" );
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__.'/receiver.php';

$obj = new Receiver();
$obj->handler();