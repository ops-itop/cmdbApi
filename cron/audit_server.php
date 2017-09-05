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
require dirname(__FILE__).'/../lib/core.function.php';
require dirname(__FILE__).'/../composer/vendor/autoload.php';
require dirname(__FILE__).'/../composer/vendor/confirm-it-solutions/php-zabbix-api/build/ZabbixApi.class.php';

define('ZBXURL', $config['zabbix']['url']);
define('ZBXUSER', $config['zabbix']['user']);
define('ZBXPWD', $config['zabbix']['password']);

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

define('MAILAPI', $config['mail']['api']);
define('MAILTO', $config['mail']['to']);
define('MAILCC', $config['mail']['cc']);
define('MAILFROM', $config['mail']['from']);

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');
$zbxAPI = new \ZabbixApi\ZabbixApi(ZBXURL, ZBXUSER, ZBXPWD);


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

// iTop 中查询所有服务器列表，仅输出需要审计的字段
function getAllServer()
{
	global $iTopAPI;
	$oql = "SELECT Server";
	$output_fields = "status,name,hostname,brand_name,model_name,cpu,ram,ip_list,vip_list,organization_name";
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

// zabbix获取所有有asset_tag的服务器
function zabbixAllHostGet()
{
	return(json_decode(json_encode(zabbixHostGet("")), true));
}

// update cmdb
function updateAssetInfo($cmdbdata, $zbxdata)
{
	global $iTopAPI;
	$cmdbServer = array(
		'name' => $cmdbdata['fields']['name'],
		'hostname' => $cmdbdata['fields']['hostname'],
		'brand_name' => $cmdbdata['fields']['brand_name'],
		'model_name' => $cmdbdata['fields']['model_name'],
		'cpu' => $cmdbdata['fields']['cpu'],
		'ram' => $cmdbdata['fields']['ram']
	);

	$zbxServer = array(
		'name' => $zbxdata['inventory']['asset_tag'],
		'hostname' => $zbxdata['host'],
		'brand_name' => $zbxdata['inventory']['vendor'],
		'model_name' => $zbxdata['inventory']['model'],
		'cpu' => $zbxdata['inventory']['tag'],
		'ram' => $zbxdata['inventory']['notes'],
	);
	
	$key = array("name" => $cmdbServer['name']);
	if(array_diff_assoc($cmdbServer, $zbxServer))
	{
		$zbxServer['brand_id'] = array("name" => $zbxServer['brand_name']);
		$zbxServer['model_id'] = array("name" => $zbxServer['model_name'], "brand_name" => $zbxServer['brand_name']);
		$ret = $iTopAPI->coreUpdate("Server", $key, $zbxServer);
		$ret = json_decode($ret, true)['message'];
		if($zbxServer['hostname'] != $cmdbServer['hostname'])
		{
			$ret = $ret . "hostname changed: " . $cmdbServer['hostname'] . " -> " . $zbxServer['hostname'];			
		}
		return($ret);
	}	
	return(null);
}

// 监控审计
function audit_monitor($data, $zbxServers)
{
	$audit_ret = array(
		"monitor" => array(),
		"updatecmdb" => array()
	);
	if(!$data)
	{
		return;
	}

	$zbxAll = array();
	foreach($zbxServers as $server)
	{
		$sn = $server['inventory']['asset_tag'];
		$zbxAll[$sn] = $server;
	}

	$exclude = excludeFilter();
	
	foreach($data as $key => $server)
	{
		if($server['fields']['status'] != "production") {
			continue;
		}
		
		// 从命令行排除一些机器
		foreach($exclude as $key => $val) {
			if(array_key_exists($key, $server['fields'])) {
				if(in_array($server['fields'][$key], $val)) continue;
			}
		}

		$sn = $server['fields']['name'];
		if(!array_key_exists($sn, $zbxAll))
		{
			$ips = $server['fields']['ip_list'];
			$intip = "";
			foreach($ips as $ip)
			{
				if($ip['type'] == "int")
				{
					$intip = $ip['ipaddress'];
				}
			}
			$audit_ret['monitor'][$sn] = $intip;
		}else  // 更新cmdb中的资产信息（以zabbix数据为准）
		{
			$updateinfo = updateAssetInfo($server, $zbxAll[$sn]);
			if($updateinfo)
			{
				$audit_ret['updatecmdb'][$sn] = $updateinfo;	
			}
		}
	}
	return $audit_ret;
}

// cmdb服务器缺失情况审计(已监控但是cmdb未录入, 已加监控但是cmdb中机器状态是下线)
function audit_cmdb($cmdbdata, $zbxServers)
{
	global $iTopAPI;
	$ret = array("missing"=>array(), "obsolete"=>array());

	// 降维处理,方便下面的循环体直接用in_array，减少循环次数
	$cmdbServers = array();
	$obsoleteServers = array();
	foreach($cmdbdata as $server)
	{
		array_push($cmdbServers, $server['fields']['name']);
		if($server['fields']['status'] == 'obsolete') {
			array_push($obsoleteServers, $server['fields']['name']);
		}
	}

	$i = 1;
	foreach($zbxServers as $server)
	{
		$sn = $server['inventory']['asset_tag'];
		if($sn == "")
		{
			$sn = "blank" . $i;
			$i++;
		}
		$hostname = $server['host'];

		if(!in_array($sn, $cmdbServers))
		{
			$ret["missing"][$sn] = $hostname;
		}
		if(in_array($sn, $obsoleteServers))
		{
			$ret["obsolete"][$sn] = $hostname;
		}
	}
	return $ret;
}

function main()
{
	$cmdbServer = getAllServer();
	$zbxServers = zabbixAllHostGet();
	$ret = audit_monitor($cmdbServer, $zbxServers);
	$csvHelper = new CSV();
	$csv_monitor = $csvHelper->arrayToCSV($ret['monitor']);
	$sum = count($ret['monitor']);
	$csv_updatecmdb = $csvHelper->arrayToCSV($ret['updatecmdb']);

	$ret_cmdb = audit_cmdb($cmdbServer, $zbxServers);
	$csv_auditcmdb = $csvHelper->arrayToCSV($ret_cmdb['missing']);
	$csv_auditcmdb_obsolete = $csvHelper->arrayToCSV($ret_cmdb['obsolete']);
	$content = "说明:\n1. 服务器唯一标识为SN(虚拟机使用UUID做为SN)\n";
	$content = $content . "2. 未加监控服务器: 以CMDB为基准，找出SN在zabbix中不存在的服务器";
	$content = $content . "\n3. CMDB信息更新情况: 以zabbix inventory信息为准，更新CMDB中服务器的主机名，CPU，型号等信息. 只显示更新失败以及主机名发生变化的服务器。需要人工关注\n";
	$content = $content . "4. 未录入CMDB服务器: 以zabbix inventory为基准，找出SN在zabbix中存在但是CMDB中不存在的服务器，需要人工录入CMDB";
	$content = $content . "\n\nCMDB信息更新情况:\n\n" . $csv_updatecmdb;
	$content = $content . "\n\n未录入CMDB服务器:\n\n" . $csv_auditcmdb;
	$content = $content . "\n\n未清监控的已下线服务器: \n\nSN,  内网IP\n" . $csv_auditcmdb_obsolete;
	$content = $content . "\n\n未加监控服务器总数: $sum \n\nSN,  内网IP\n" . $csv_monitor;
	print_r($content);

	$dt = date("Y-m-d", time());
	$subject = "CMDB-Zabbix双向审计报告-$dt";
	//$headers = "From: ". MAILFROM;
	//$headers = "MIME-Version: 1.0" . "\r\n";
	//$headers .= "Content-type:text/html;charset=iso-8859-1" . "\r\n";
	sendmail($subject,$content);
	//die(json_encode($ret));
}

function excludeFilter()
{
	$params = getopt('', array('exclude:'));
	$exclude = '';
	if(array_key_exists('exclude', $params)) {
		$exclude = $params['exclude'];
	}
	$exclude_arr = array();
	$exclude = explode('#', $exclude);
	foreach($exclude as $val) {
		$t = explode('=', $val);
		if(count($t) != 2) continue;
		$exclude_arr[$t[0]] = explode(',', $t[1]);
	}
	return($exclude_arr);
}

main();
