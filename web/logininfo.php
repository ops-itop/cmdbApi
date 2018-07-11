<?php
/**
 * Usage:
 * File Name: logininfo.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-03-29 10:05:48
 * 用于登录机器时打印机器信息
 * 机器上部署 /etc/profile.d/login.sh 访问此接口
 **/

require 'common/init.php';

define('HEAD',"\n##################### Server Info ######################\n");
define('WARN',"WARNING!!此机器未登记业务信息，有被下线风险");
define('TIP',"如果您认为以上信息有误，请及时联系运维修正错误");
define('TAIL',"\n########################################################\n");

function red($text) {
	return "\033[31m     $text \033[0m";
}

function blink($text) {
	return "\033[5;33m     $text \033[0m";
}

function skyblue($text) {
	return "\033[36m     $text \033[0m";
}

function yellow($text) {
	return "\033[33m     $text \033[0m";
}

function white($text) {
	return "\033[37m     $text \033[0m";
}

function errorInfo() {
	$info = HEAD . skyblue("获取业务信息失败，请联系运维") .  TAIL;
	return $info;
}

function getApps($relations) {
	$apps = [];
	foreach($relations as $k => $v) {
		if(preg_match("/^Server::.*/", $k)) {
			foreach($v as $key => $val) {
				if(preg_match('/^ApplicationSolution::.*/', $val['key'])) {
					$item = explode('::', $val['key'])[2];
					$item = explode('.', $item)[1];
					$apps[] = $item;
				}
			}
		}
	}
	return $apps;
}

function getServerLoginInfo($ip)
{
	global $iTopAPI;
	$query = "SELECT Server AS s JOIN PhysicalIP AS ip ON ip.connectableci_id=s.id WHERE ip.ipaddress = '$ip'";	

	$optional = array(
		'output_fields' => array("Server"=>"status,location_name","ApplicationSolution"=>"name"),
		'show_relations' => ["Server","ApplicationSolution","Location","Person"],
		'hide_relations' => [],
		'direction' => 'both',
		'filter' => ["Server","ApplicationSolution","Person"],
		'depth' => 2,
	);
	$data = $iTopAPI->extRelated("Server", $query, "impacts", $optional);

	$data = json_decode($data, true);
	$obj = $data['objects'];
	if(!$obj)
	{
		return(errorInfo());
	}
	
	$relations = $data['relations'];
	$apps = getApps($relations);
	$contacts = [];
	$location = "";
	$status = "";
	$map_status=array("production"=>"使用中","stock"=>"库存(此状态随时可能被下线)",
		"obsolete"=>"废弃(此状态随时可能被重装或关机)","implementation"=>"上线中");
	foreach($obj as $k => $v) {
		if($v['class'] == "Person") {
			$contacts[] = $v['fields']['friendlyname'];
		}
		if($v['class'] == "Server") {
			$location = $v['fields']['location_name'];
			$status = $map_status[$v['fields']['status']];
		}
	}

	if(!$apps) {
		$apps = blink("业  务：" . WARN);
	} else {
		$apps = implode(",", $apps);
		$apps = red("业  务：" . $apps);
	}
	$contacts = implode(",", $contacts);
	$info = HEAD;
	$info .= $apps . "\n";
	$info .= skyblue("机  房：" . $location) . "\n";
	$info .= skyblue("状  态：" . $status) . "\n";
	$info .= white("联系人：" . $contacts) . "\n";
	$info .= "\n" . yellow(TIP) . TAIL;
	return($info);
}


if(isset($_GET['ip'])) {
	$ip = $_GET['ip'];
	$key = "logininfo_" . $ip;

	if(isset($_GET['cache']) && $_GET['cache'] == "set")
	{
		$ret = getServerLoginInfo($ip);
		die(setCache($key, $ret));
	}

	if(isset($_GET['cache']) && $_GET['cache'] == "false")
	{
		die(getServerLoginInfo($ip));
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
	die(errorInfo());
}

