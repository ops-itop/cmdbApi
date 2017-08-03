#!/usr/bin/php
<?php
/**
 * Usage: 工单机器人，用于指派工单，创建工单申请的资源，根据工单状态更新资源状态等
 * File Name: ticket_robot.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-05-24 13:10:27
 **/

// 写日志
function writeLog($ret)
{
	global $ID;
	global $sClass;
	global $log;
	global $config;
	file_put_contents($log , $config['datetime'] . " - $ID - $sClass - $ret \n", FILE_APPEND);
}

require dirname(__FILE__).'/../etc/config.php';

$ID = getenv("ID");
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";

$times = 0; // 递归次数
function GetData($ID)
{
	global $times;
	global $iTopAPI;
	global $config;
	$data = json_decode($iTopAPI->coreGet("Ticket", $ID), true);
	if($data['code'] != 0 || !$data['objects'])
	{
		sleep($config['ticket']['delay']); // 可能是缓存的原因，生产环境获取不到工单，这里等待几秒
		$ret = "times:" . $times . " " . $data['message'];
		$sClass = "Ticket";
		writeLog($ret);
		if($times > 2) // 最多重试三次
		{
			die();
		}
		$data = GetData($ID);
		$times += 1;
	}
	return($data);
}

$data = GetData($ID);
$Ticket = reset($data['objects']);
$sClass = $Ticket['fields']['finalclass'];
$data = json_decode($iTopAPI->coreGet($sClass, $ID), true);

$ret = null;

if($data['code'] != 0 || !$data['objects'])
{
	$ret = $data['message'];
	writeLog($ret);
	die();
}

$Ticket = reset($data['objects']);
$ticketStatus = $Ticket['fields']['status'];

// $Ticket 保存了工单的所有信息
// print_r($Ticket);
//die();

$ret = array();
if($ticketStatus == "resolved") {
	
	// 任意操作
}

$ret = "times: " . $times . " " . implode(" - ", $ret);
writeLog($ret);
