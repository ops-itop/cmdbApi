#!/usr/bin/php
<?
/**
 * Usage:
 * File Name: demo.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-04-21 17:08:30
 **/

require dirname(__FILE__).'/../etc/config.php';

$THIS_HOSTNAME = getenv("THIS_HOSTNAME");
$THIS_NAME = getenv("THIS_NAME");

$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";
echo $log;
sleep(1);
file_put_contents($log, "$THIS_HOSTNAME $THIS_NAME\n", FILE_APPEND);
?>
 
 
 
