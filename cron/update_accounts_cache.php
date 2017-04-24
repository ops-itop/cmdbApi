#!/usr/bin/php
<?
/**
 * Usage: 计划任务，全量更新accounts.php的缓存，防止其他手段更新失败
 * File Name: update_accounts.php
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

$oql = "SELECT PhysicalIP AS ip JOIN Server AS s ON ip.connectableci_id=s.id WHERE ip.type='int' AND s.status='production'";
$data = $iTopAPI->coreGet("PhysicalIP", $oql, "ipaddress");
$ips = json_decode($data, true)['objects'];

// 更新accounts.php 接口缓存
if($ips)
{
	foreach($ips as $key => $value)
	{
		$ip = $value['fields']['ipaddress'];
		$url = trim($config['rooturl'], "/") . "/accounts.php?ip=" . $ip . "&cache=set";
		$ret = curlGet($url);
		print_r("$ip" . " - " . $ret . "\n");
		sleep(1);
	}	
}
?>
