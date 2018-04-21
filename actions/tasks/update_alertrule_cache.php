#!/usr/bin/php
<?
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
$script = explode("/", $argv[0]);
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end($script) . ".log";

function SetCache($type, $value) {
	global $config;
	$url = trim($config['rooturl'], "/") . "/alert.php?type=" . $type . "&value=" . $value . "&cache=set";
	return " - setcache_status:" . curlGet($url);
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
	}
	return($ret);
}

switch ($CLASS) {
	case "AlertRule": $ret = alertruleSetCache($ID);break;
	case "lnkFunctionalCIToAlertRule": $ret = alertruleLnkSetCache($ID);break;
	default: $ret = "Missing CLASS";
}
file_put_contents($log, $config['datetime'] . " - $CLASS-$ID - $ret\n", FILE_APPEND);
