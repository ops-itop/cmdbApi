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

define('CACHE_HOST', $config['memcached']['host']);
define('CACHE_PORT', $config['memcached']['port']);
define('CACHE_EXPIRATION', $config['memcached']['expiration']);


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
		" AND l.status='enabled'" . 
		" AND l.user_status='enabled' AND (l.expiration > NOW() OR l.expiration <= '$timestamp_0')";
	$data = $iTopAPI->coreGet("lnkUserToServer", $query, "user_name,user_status,sudo");
	//die($data);
	$lnks = json_decode($data, true)['objects'];

	$ret = array("users"=>array(), "sudo"=>array());
	if($lnks)
	{
		foreach($lnks as $k => $v)
		{
			array_push($ret['users'], $v['fields']['user_name']);
			if($v['fields']['sudo'] == "yes")
			{
				array_push($ret['sudo'], $v['fields']['user_name']);
			}	
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

// 使用缓存需要配合iTop触发器及action-shell-exec， lnkContactToFunctionalCI对象创建或者工单
// 审批通过时，需要触发一个脚本去更新缓存。对象删除暂时不能通过触发器，考虑每小时定时任务
// 或者开发一个trigger-ondelete插件
// Server的pam开关有变化时，也需要触发操作，需要trigger-onupdate插件
function setCache($ip, $value)
{
	$m = new Memcached();
	$m->addServer(CACHE_HOST, CACHE_PORT);
	$expiration = time() + (int)CACHE_EXPIRATION;
	return($m->set($ip, $value, $expiration));
}

function getCache($ip)
{
	$m = new Memcached();
	$m->addServer(CACHE_HOST, CACHE_PORT);
	return($m->get($ip));
}

function main($ip)
{
	$serverinfo = getServerInfo($ip);
	if($serverinfo == 101)
	{
		return(PAM_OFF . "#|");
	}
	if($serverinfo == 102)
	{
		return("NOT FOUND");
	}

	$users = getUser($ip, $serverinfo);
	return(ACCOUNTS_OK . "#" . $users);
}

if(isset($_GET['ip'])) {
	$ip = $_GET['ip'];
	// 设置缓存(无需校验IP)
	if(isset($_GET['cache']) && $_GET['cache'] == "set")
	{
		$ret = main($ip);
		die(setCache($ip, $ret));
	}
	if(!$config['accounts']['debug'])
	{
		if(!checkIP($ip))
		{
			die("Permission denied");
		}
	}

	if(isset($_GET['cache']) && $_GET['cache'] == "false")
	{
		die(main($ip));
	}else
	{
		// 首先获取缓存内容
		$ret = getCache($ip);
		if(!$ret)
		{
			$ret = main($ip);
			setCache($ip, $ret);
		}
		die($ret);
	}
}else
{
	die("ERROR");
}

