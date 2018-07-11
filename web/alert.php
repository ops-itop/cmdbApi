<?php
/**
 * Usage:
 * File Name: account.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-03-29 10:05:48
 **/

require 'common/init.php';

function getFunctionalCIId($type, $value)
{
	global $iTopAPI;
	global $config;
	if(!array_key_exists($type, $config['map']))
	{
		return false;
	}
	$class = $config['map'][$type];
	$query = "SELECT ". $class . " WHERE name = '" . $value . "'";

	if($class == 'PhysicalIP') {
		$query = "SELECT ApplicationSolution AS app JOIN lnkApplicationSolutionToFunctionalCI AS l ON l.applicationsolution_id=app.id JOIN Server AS s ON l.functionalci_id=s.id JOIN PhysicalIP AS ip ON ip.connectableci_id=s.id WHERE ip.ipaddress='" . $value . "'";
		$class = "ApplicationSolution";
	}
	$data = json_decode($iTopAPI->coreGet($class, $query), true);
	if(array_key_exists("objects", $data) && $data['objects'] != null)
	{
		$obj = reset($data['objects']);
		$key = $obj['key'];
		$org_id = $obj['fields']['org_id'];
		return(array('key'=>$key, 'org_id'=>$org_id));
	}else
	{
		return false;
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

function main($type, $value)
{
	$ciinfo = getFunctionalCIId($type, $value);
	if(!$ciinfo) {
		$result = array('org_id'=>null, 'rules'=>array());
		return(json_encode($result));
	}

	$id = $ciinfo['key'];
	$org_id = $ciinfo['org_id'];
	$rules = getAlertRule($id);

	$result = array('org_id'=>$org_id, 'rules'=>$rules);
	return(json_encode($result));
}

if(isset($_GET['type']) && isset($_GET['value'])) {
	$type = $_GET['type'];
	$value = $_GET['value'];
	$key = "alertrule_" . $type . "_" . $value;
	// 设置缓存
	if(isset($_GET['cache']) && $_GET['cache'] == "set")
	{
		$result = main($type, $value);
		die(setCache($key, $result));
	}
	if(isset($_GET['cache']) && $_GET['cache'] == "false")
	{
		$result = main($type, $value);
		setCache($key, $result);
		die($result);
	}else
	{
		// 首先获取缓存内容
		$result = getCache($key);
		if(!$result)
		{
			$result = main($type, $value);
			setCache($key, $result);
		}
		die($result);
	}
}else
{
	die(json_encode(array('org_id'=>null, "rules"=>array())));
}
