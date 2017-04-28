#!/usr/bin/php
<?
/**
 * Usage: 新增或删除lnkContactToFunctionalCI时，更新accounts.php的缓存。action参数 ID=$this->functionalci_id$
 *        修改Server use_pam属性时，更新accounts.php缓存，action参数 ID=$this->id$
 * File Name: update_accounts_fromLnk.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-04-28 18:08:30
 **/

require dirname(__FILE__).'/../etc/config.php';

$ID = getenv("ID");
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";

// 更新accounts.php 接口缓存
$sOql = "SELECT PhysicalIP WHERE connectableci_id = $ID AND type!='oob'";
$ips = json_decode($iTopAPI->coreGet("PhysicalIP", $sOql, "ipaddress"), true)['objects'];
$ret = "";
if($ips)
{
	foreach($ips as $key => $value)
	{
		$ip = $value['fields']['ipaddress'];
		$ret = $ip;
		$url = trim($config['rooturl'], "/") . "/accounts.php?ip=" . $ip . "&cache=set";
		$ret = $ret . " - setcache_status:" . curlGet($url);
	}	
}

file_put_contents($log, $config['datetime'] . " - $ID - $ret\n", FILE_APPEND);
?>
