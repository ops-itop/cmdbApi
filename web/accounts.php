<?php
/**
 * Usage:
 * File Name: account.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-03-29 10:05:48
 **/

require '../etc/config.php';
require '../composer/vendor/autoload.php';

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

// 状态
define('PAM_OFF', "PAM_OFF");
define('ACCOUNTS_OK', "ACCOUNTS_OK");

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');

/* 
 * 100 成功
 * 101 未开启PAM
 * 102 IP不存在
 */
function getServerInfo($ip)
{
	global $iTopAPI;
	$query = "SELECT Server AS s JOIN PhysicalIP AS ip ON ip.connectableci_id=s.id WHERE ip.ipaddress = '$ip'";
	$data = $iTopAPI->coreGet("Server", $query);
	$obj = json_decode($data, true)['objects'];
	if(!$obj)
	{
		return(102);
	}
	foreach($obj as $k => $v)
	{
		$server = $v;
	}
	if($server['fields']['use_pam'] != "yes")
	{
		return(101);
	}

	$contacts = array();
	foreach($server['fields']['contacts_list'] as $k => $v)
	{
		$person = preg_replace("/@.*/","",$v['contact_email']);
		if($person!="")
		{
			array_push($contacts, $person);
		}
	}
	$ret = array("server_id" => $server['key'], "contacts" => $contacts);
	return($ret);
}


function getUser($ip, $serverinfo)
{
	global $iTopAPI;

	$timestamp_0 = date('Y-m-d H:i:s', 86400);
	$query = "SELECT lnkUserToServer AS l WHERE l.server_id=" . $serverinfo['server_id'] . 
		" AND l.user_status='enabled' AND (l.expiration > NOW() OR l.expiration <= '$timestamp_0')";
	$data = $iTopAPI->coreGet("lnkUserToServer", $query, "user_name,user_status,sudo");
	$lnks = json_decode($data, true)['objects'];

	$ret = array("users"=>array(), "sudo"=>array());
	foreach($lnks as $k => $v)
	{
		array_push($ret['users'], $v['fields']['user_name']);
		if($v['fields']['sudo'] == "yes")
		{
			array_push($ret['sudo'], $v['fields']['user_name']);
		}	
	}
	$ret = array("users" => array_unique(array_merge($ret['users'], $serverinfo['contacts'])), 
		"sudo" => array_unique(array_merge($ret['sudo'], $serverinfo['contacts'])));
	$ret = implode("|", array(implode(",", $ret['users']), implode(",",$ret['sudo'])));
	return($ret);
}

/*
 * 定义返回格式为:  状态#allowed users|sudo users
 * 状态包含: PAM_OFF ACCOUNTS_OK
 * allowed users及sudo users以逗号分隔
 */

// 验证IP，只允许访问自己的数据, 
// 如果使用代理，需要配置 proxy_set_header     X-Forwarded-For $proxy_add_x_forwarded_for;
function checkIP($ip_para)
{
	$ip = getenv("HTTP_X_FORWARDED_FOR");
	if(!$ip)
	{
		$ip = $_SERVER["REMOTE_ADDR"];
	}
	if($ip_para == $ip)
	{
		return(true);
	}
	return(false);
}

if(isset($_GET['ip'])) {
	$ip = $_GET['ip'];
	if(!$config['accounts']['debug'])
	{
		if(!checkIP($ip))
		{
			die("Permission denied");
		}
	}
	$serverinfo = getServerInfo($ip);
	if($serverinfo == 101)
	{
		die(PAM_OFF . "#|");
	}
	if($serverinfo == 102)
	{
		die("ERROR");
	}

	$users = getUser($ip, $serverinfo);
	die(ACCOUNTS_OK . "#" . $users);
}else
{
	die("ERROR");
}

