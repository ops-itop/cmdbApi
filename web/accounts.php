<?php
/**
 * Usage:
 * File Name: account.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-03-29 10:05:48
 **/

require 'common/init.php';

define('CACHE_HOST', $config['memcached']['host']);
define('CACHE_PORT', $config['memcached']['port']);
define('CACHE_EXPIRATION', $config['memcached']['expiration']);

/* 
 * 100 成功
 * 102 IP不存在
 */
function getServerInfo($ip)
{
	global $iTopAPI;
	$option = array(
		"depth"=>1,
		"filter"=>["Server","ApplicationSolution"],
		"output_fields"=>["Server"=>"name","ApplicationSolution"=>"contact_list_custom"]
	);
	$query = "SELECT Server AS s JOIN PhysicalIP AS ip ON ip.connectableci_id=s.id WHERE ip.ipaddress = '$ip'";
	$data = $iTopAPI->extRelated("Server",$query, "impacts", $option);
	$obj = json_decode($data, true)['objects'];
	if(!$obj)
	{
		return(102);
	}
	$server_id=0;
	$contacts=array();
	foreach($obj as $k => $v)
	{
		if($v['class'] == "Server") {
			$server_id = $v['key'];
		}
		if($v['class'] == "ApplicationSolution") {
			foreach($v['fields']['contact_list_custom'] as $key => $val) {
				$contacts[] = preg_replace("/@.*/","",$val['contact_email']);
			}
		}
	}
	
	$contacts = array_unique($contacts);

	$ret = array("server_id" => $server_id, "contacts" => $contacts);
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
 * 定义返回格式为:  allowed users|sudo users
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

function main($ip)
{
	$serverinfo = getServerInfo($ip);
	if($serverinfo == 102)
	{
		return("NOT FOUND");
	}

	$users = getUser($ip, $serverinfo);
	return($users);
}

if(isset($_GET['ip'])) {
	$ip = $_GET['ip'];
	$key = "account_" . $ip;
	// 设置缓存(无需校验IP)
	if(isset($_GET['cache']) && $_GET['cache'] == "set")
	{
		$ret = main($ip);
		die(setCache($key, $ret));
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
		$ret = getCache($key);
		if(!$ret)
		{
			$ret = main($ip);
			setCache($key, $ret);
		}
		die($ret);
	}
}else
{
	die("ERROR");
}

