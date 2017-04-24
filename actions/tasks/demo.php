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

$ID = getenv("ID");
$TITLE = getenv("TITLE");

$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";
echo $log;
sleep(1);
file_put_contents($log, "$ID $TITLE\n", FILE_APPEND);
?>
 
 
 
