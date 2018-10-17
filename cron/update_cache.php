#!/usr/bin/php
<?
/**
 * Usage: 计划任务，全量更新accounts.php, alert.php的缓存，防止其他手段更新失败
 * File Name: update_cache.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-04-24 17:08:30
 **/
require dirname(__FILE__).'/../etc/config.php';
require dirname(__FILE__).'/../lib/core.function.php';
require dirname(__FILE__).'/../composer/vendor/autoload.php';

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');

function getIPs()
{
	global $iTopAPI;
	$oql = "SELECT PhysicalIP AS ip JOIN Server AS s ON ip.connectableci_id=s.id WHERE ip.type='int' AND s.status='production'";
	$data = $iTopAPI->coreGet("PhysicalIP", $oql, "ipaddress");
	$ips = json_decode($data, true)['objects'];
	return $ips;
}

function getApps()
{
	global $iTopAPI;
	$oql = "SELECT ApplicationSolution AS app WHERE app.status='production'";
	$data = $iTopAPI->coreGet("ApplicationSolution", $oql, "name");
	$apps = json_decode($data, true)['objects'];
	return $apps;
}

$ips = getIPs();
$apps = getApps();
// 更新accounts.php 接口及alert.php接口 logininfo.php接口 ip类型的缓存
if($ips)
{
	foreach($ips as $key => $value)
	{
		$ip = $value['fields']['ipaddress'];
		$url_accounts = trim($config['rooturl'], "/") . "/accounts.php?ip=" . $ip . "&cache=set";
		$url_alert = trim($config['rooturl'], "/") . "/alert.php?type=ip&value=" . $ip . "&cache=set";
		$url_logininfo = trim($config['rooturl'], "/") . "/logininfo.php?ip=" . $ip . "&cache=set";
		$ret_accounts = curlGet($url_accounts);
		$ret_alert = curlGet($url_alert);
		$ret_logininfo = curlGet($url_logininfo);
		print_r("accounts - " . $ip . " - " . $ret_accounts . "\n");
		print_r("alert - " . $ip . " - " . $ret_alert . "\n");
		print_r("logininfo - " . $ip . " - " . $ret_logininfo . "\n");
		//sleep(1);
	}	
}
if($apps)
{
	foreach($apps as $key => $value)
	{
		$app = $value['fields']['name'];
		$url_alert = trim($config['rooturl'], "/") . "/alert.php?type=app&value=" . $app . "&cache=set";
		$ret_alert = curlGet($url_alert);
		print_r("alert - " . $app . " - " . $ret_alert . "\n");
	}
}
?>
