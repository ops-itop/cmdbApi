#!/usr/bin/php
<?php
/**
 * Usage: 更新报警规则接口缓存(api/alert.php). 接口参数为type和value
 * lnkFunctionalCIToAlertRule, AlertRule更新时都需要更新缓存
 * 设置变量 ID=$this->id$, CLASS=(lnkFunctionalCIToAlertRule|AlertRule)
 * File Name: update_alertrule_cache.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-07-11 17:24:30
 **/

require dirname(__FILE__).'/../etc/config.php';
$map = array_flip($config['map']);

$ID = getenv("ID");
$CLASS = getenv("CLASS");
$DEBUG = getenv("DEBUG");
$script = explode("/", $argv[0]);
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end($script) . ".log";

// 可能是缓存原因，接口返回数据没有变化，导致用户删除自己负责的app时未更新contacts字段, 所以这里等几秒
if(!$DEBUG)
{
	sleep($config['update']['delay']);
}

function SetCache($type, $value) {
	global $config;
	$url = trim($config['rooturl'], "/") . "/alert.php?type=" . $type . "&value=" . $value . "&cache=set";
	return " - $type:$value:setcache_status:" . curlGet($url);
}

function FunctionalCIs($app) {
	global $iTopAPI;
	global $map;
	$ret = "";
	$query = "SELECT ApplicationSolution WHERE name='" . $app . "'";
	$data = json_decode($iTopAPI->coreGet("ApplicationSolution", $query), true)['objects'];
	if(!$data) return $ret;
	$data = reset($data);
	$cis = $data['fields']['functionalcis_list'];
	foreach($cis as $k => $v) {
		$type = $map[$v['functionalci_id_finalclass_recall']];
		$value = $v['functionalci_name'];
		if($type == "server") {
			$query = "SELECT PhysicalIP WHERE connectableci_name='" . $value . "' AND type!='oob'";
			$data = json_decode($iTopAPI->coreGet("PhysicalIP", $query),true)['objects'];
			if(!$data) return $ret;
			foreach($data as $key => $ip) {
				$ret = $ret . SetCache("ip", $ip['fields']['ipaddress']);
			}
		} else {
			$ret = $ret . SetCache($type, $value);
		}
	}	
	return $ret;
}

function alertruleLnkSetCache($ID) {
	global $config;
	global $iTopAPI;
	global $map;
	$ret = "";
	$data = json_decode($iTopAPI->coreGet("lnkFunctionalCIToAlertRule", $ID), true)['objects'];
	if(!$data) {
		return "getAlertRule_Failed";
	}
	$data = reset($data);
	$type = $map[$data['fields']['functionalci_id_finalclass_recall']];
	$value = $data['fields']['functionalci_name'];
	$ret = $ret . SetCache($type, $value);
	if($type == "app") {
		$ret = $ret . FunctionalCIs($value);
	}
	return $ret;
}

function alertruleSetCache($ID) {
	global $config;
	global $iTopAPI;
	global $map;
	$ret = "";
	$data = json_decode($iTopAPI->coreGet("AlertRule", $ID), true)['objects'];
	if(!$data) {
		return "getAlertRule_Failed";
	}
	$data = reset($data);
	$cis = $data['fields']['functionalcis_list'];
	foreach($cis as $k => $v) {
		$type = $map[$v['functionalci_id_finalclass_recall']];
		$value = $v['functionalci_name'];
		$ret = $ret . SetCache($type, $value);
		if($type == "app") {
			$ret = $ret . FunctionalCIs($value);
		}
	}
	return($ret);
}

switch ($CLASS) {
	case "AlertRule": $ret = alertruleSetCache($ID);break;
	case "lnkFunctionalCIToAlertRule": $ret = alertruleLnkSetCache($ID);break;
	default: $ret = "Missing CLASS";
}
file_put_contents($log, $config['datetime'] . " - $CLASS-$ID - $ret\n", FILE_APPEND);
