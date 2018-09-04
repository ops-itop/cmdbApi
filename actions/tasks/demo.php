#!/usr/bin/php
<?php
/**
 * Usage:
 * File Name: demo.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-04-21 17:08:30
 **/

require dirname(__FILE__).'/../etc/config.php';

$ID = getenv("ID");
$TITLE = getenv("TITLE");

$script = explode("/", $argv[0]);
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end($script) . ".log";
echo $log;
sleep(1);
file_put_contents($log, "$ID $TITLE\n", FILE_APPEND);
?>
 
 
 
