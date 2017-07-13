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

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');

function getFunctionalCIId($type, $value)
{
	global $iTopAPI;
	global $config;
	if(!array_key_exists($type, $config['map']))
	{
		return(false);
	}
	$class = $config['map'][$type];
	$query = "SELECT ". $class . " WHERE name = '" . $value . "'";
	$data = json_decode($iTopAPI->coreGet($class, $query), true);
	if(array_key_exists("objects", $data) && $data['objects'] != null)
	{
		$obj = $data['objects'];
		return(reset($obj)['key']);
	}else
	{
		return(false);
	}
}

function getAlertRule($functionalci_id)
{
	global $iTopAPI;
	$query = "SELECT AlertRule AS a JOIN lnkFunctionalCIToAlertRule AS l ON l.alertrule_id=a.id WHERE l.functionalci_id='" . $functionalci_id . "'";
	$data = $iTopAPI->coreGet("AlertRule", $query);
	$obj = json_decode($data, true)['objects'];
	$rule = array();
	if($obj)
	{
		foreach($obj as $k => $v)
		{
			$rule[$v['fields']['alerttype_name']. "_".$v['fields']['method']] = array(
				'method' => $v['fields']['method'],
				'qoq_cycle' => $v['fields']['qoq_cycle'],
				'threshold' => $v['fields']['threshold'],
				'id' => $v['key']
			);
		}
	}
	return $rule;
}

// 使用缓存需要配合iTop触发器及action-shell-exec， lnkFuncationalCIToAlertRule
// 对象创建或更新删除时需要触发一个脚本去更新缓存
function setCache($key, $rule)
{
	$m = new Memcached();
	$m->addServer(CACHE_HOST, CACHE_PORT);
	$expiration = time() + (int)CACHE_EXPIRATION;
	return($m->set($key, $rule, $expiration));
}

function getCache($key)
{
	$m = new Memcached();
	$m->addServer(CACHE_HOST, CACHE_PORT);
	return($m->get($key));
}

function main($type, $value)
{
	$id = getFunctionalCIId($type, $value);
	$rule = json_encode(getAlertRule($id));
	return($rule);
}

if(isset($_GET['type']) && isset($_GET['value'])) {
	$type = $_GET['type'];
	$value = $_GET['value'];
	$key = "alertrule_" . $type . "_" . $value;
	// 设置缓存
	if(isset($_GET['cache']) && $_GET['cache'] == "set")
	{
		$rule = main($type, $value);
		die(setCache($key, $rule));
	}
	if(isset($_GET['cache']) && $_GET['cache'] == "false")
	{
		$rule = main($type, $value);
		die($rule);
	}else
	{
		// 首先获取缓存内容
		$rule = getCache($key);
		if(!$rule)
		{
			$rule = main($type, $value);
			setCache($key, $rule);
		}
		die($rule);
	}
}else
{
	die(json_encode(array()));
}
