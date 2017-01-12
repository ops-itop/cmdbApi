<?php
/**
 * Usage: app可用率分级报表
 * File Name:
 * Author:  
 * Mail: 
 * Created Time: 2017-01-11 17:27:00
 **/

require dirname(__FILE__).'/../etc/config.php';
require dirname(__FILE__).'/../lib/core.function.php';
require dirname(__FILE__).'/../composer/vendor/autoload.php';

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

define('MAILAPI', $config['mail']['api']);
define('MAILTO', $config['mail']['to']);
define('MAILCC', $config['mail']['cc']);
define('MAILFROM', $config['mail']['from']);

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');

// 发邮件
function sendmail($sub, $content, $format="text")
{
	$data = array(
		"tos" => MAILTO,
		"cc" => MAILCC,
		"subject" => $sub,
		"content" => $content,
		"from" => MAILFROM,
		"format" => $format
	);
	return(http_mail(MAILAPI, $data));
}

// iTop 中查询所有的business_criticity属性
function getSLA()
{
	global $iTopAPI;
	global $config;
	$oql = "SELECT ApplicationSolution AS app WHERE app.status='production'";
	$output_fields = "name,business_criticity";
	$data = $iTopAPI->coreGet("ApplicationSolution", $oql, $output_fields);
	$data = json_decode($data, true);
	if($data['code'] != 0){
		return array();
	}
	$sla = array();
	foreach($data['objects'] as $k => $v)
	{
		$sla[$v['fields']['name']] = $config['app_sla'][$v['fields']['business_criticity']];	
	}
	return $sla;
}

function queryAvailabilityData($database, $sql)
{
	$result = $database->query($sql);
	$series = $result->getSeries();
	return($series);
}

function appSLA($app, $sla)
{
	global $config;
	// 处理带端口的app，例如 desktop.8080
	if(preg_match("/^\w+([-_]\w+\d*)*\.\d+$/", $app))
	{
		$app = explode(".", $app);
		$app = array_slice($app, 0, -1);
		$app = implode(".", $app);
	}
	if(array_key_exists($app, $sla)) {
		return($sla[$app]);
	}else {
		return($config['app_sla']['null']);
	}
}

function emailReport($orig, $sla)
{
	global $config;
	
	$report = array();
	foreach($config['app_sla'] as $k => $v)
	{
		$report[$v] = array();
	}

	foreach($orig as $k => $v)
	{
		$item = array();
		$item['app'] = $v['tags']['app'];
		$item['cluster'] = $v['tags']['cluster'];
		$item['level'] = appSLA($item['app'], $sla);
		$item['avail'] = (float)$v['values'][0][1];
		$item['status'] = "达标";
		if(!array_key_exists($item['level'], $config['app_status']))
		{
			$item['status'] = "未定级";
			$item['sla'] = "未定义";
			array_push($report[$item['level']], $item);
		}elseif($item['avail'] < $config['app_status'][$item['level']])
		{
			$item['sla'] = $config['app_status'][$item['level']];
			$item['status'] = "不达标";
			array_push($report[$item['level']], $item);
		}
	}
	return($report);
}

function genAvailabilityPoints($measurement, $orig, $sla)
{
	$points = array();

	// 更新tags和values，增加级别和日期信息
	foreach($orig as $k => $v)
	{
		$tags = $v['tags'];
		$app = $tags['app'];
		$tags['level'] = appSLA($app, $sla);
		$tags['date'] = date("Y-m-d", strtotime("-1 day"));
		$time = strtotime(date("Y-m-d") . " 00:00:00");

		// 取value
		$fields = array("avail" => (float)$v['values'][0][1]);

		$point = new InfluxDB\Point($measurement, null, $tags, $fields, $time);
		array_push($points, $point);
	}
	return $points;
}

function writeAvailabilityData($database, $points)
{
	$result = $database->writePoints($points, InfluxDB\Database::PRECISION_SECONDS);
	return $result;	
}

function main()
{
	global $config;
	$client = new InfluxDB\Client($config['influx']['host'], $config['influx']['port']);
	$database = $client->selectDB($config['influx']['db']);
	$sql = $config['influx']['query'];
	$orig = queryAvailabilityData($database, $sql);
	$measurement = $config['influx']['measurement'];

	$sla = getSLA();
	$points = genAvailabilityPoints($measurement, $orig, $sla);
	writeAvailabilityData($database, $points);
	print_r(emailReport($orig, $sla));
}

main();
