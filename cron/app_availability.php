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
	$oql = "SELECT ApplicationSolution AS app WHERE app.status='production'";
	$output_fields = "name,business_criticity";
	$data = $iTopAPI->coreGet("ApplicationSolution", $oql, $output_fields);
	$data = json_decode($data, true);
	return $data['objects'];
}

function queryAvailabilityData()
{
	global $config;
	$client = new InfluxDB\Client($config['influx']['host'], $config['influx']['port']);
	$database = $client->selectDB($config['influx']['db']);
	$result = $database->query($config['influx']['query']);
	$series = $result->getSeries();
	print_r($series);
}
queryAvailabilityData();
