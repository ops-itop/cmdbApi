<?php
/**
 * Usage: 根据cmdb记录的服务器情况审计zabbix监控完整度。根据zabbix inventory信息更新cmdb中服务器资产信息
 * File Name: audit_server.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-10-11 10:47:33
 **/

require dirname(__FILE__).'/../etc/config.php';
require dirname(__FILE__).'/../lib/csv.class.php';
require dirname(__FILE__).'/../composer/vendor/autoload.php';
require dirname(__FILE__).'/../composer/vendor/confirm-it-solutions/php-zabbix-api/build/ZabbixApi.class.php';

define('ZBXURL', $config['zabbix']['url']);
define('ZBXUSER', $config['zabbix']['user']);
define('ZBXPWD', $config['zabbix']['password']);

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

define('MAILTO', $config['mail']['to']);
define('MAILFROM', $config['mail']['from']);

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');
$zbxAPI = new \ZabbixApi\ZabbixApi(ZBXURL, ZBXUSER, ZBXPWD);

// iTop 中查询所有服务器列表，仅输出需要审计的字段
function getAllServer()
{
	global $iTopAPI;
	$oql = "SELECT Server AS s WHERE s.status='production'";
	$output_fields = "name,hostname,brand_name,model_name,cpu,ram,ip_list,vip_list";
	$data = $iTopAPI->coreGet("Server", $oql, $output_fields);
	$data = json_decode($data, true);
	return $data['objects'];
}

// zabbix查询host接口
function zabbixHostGet($name)
{
	global $zbxAPI;
	$param = array(
		"output" => array("host","inventory"),
		"selectInventory" => array("asset_tag", "vendor", "model", "tag", "notes"),
		"searchInventory" => array("asset_tag" => $name)
	);
	$data = $zbxAPI->hostGet($param);
	return($data);
}

function audit_monitor($data)
{
	$audit_ret = array();
	if(!$data)
	{
		return;
	}
	foreach($data as $key => $server)
	{
		$sn = $server['fields']['name'];
		$zbxhost = zabbixHostGet($sn);
		if(!$zbxhost)
		{
			$audit_ret[$sn] = "error";
		}
	}
	return $audit_ret;
}

function main()
{
	$cmdbServer = getAllServer();
	$ret = audit_monitor($cmdbServer);
	$csvHelper = new CSV();
	$csv = $csvHelper->arrayToCSV($ret);
	print_r($csv);

	$dt = date("Y-m-d", time());
	$subject = "监控审计报告-$dt";
	$headers = "From: ". MAILFROM;
	//$headers = "MIME-Version: 1.0" . "\r\n";
	//$headers .= "Content-type:text/html;charset=iso-8859-1" . "\r\n";
	mail(MAILTO,$subject,$csv,$headers);
	//die(json_encode($ret));
}

main();
