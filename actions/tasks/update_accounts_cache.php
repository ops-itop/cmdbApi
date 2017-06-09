#!/usr/bin/php
<?
/**
 * Usage: 更新账号接口缓存(api/accounts.php). 参数为需要获取服务器ID
 * lnkUserToServer, lnkContactToFunctionalCI及Server变更都需要更新缓存
 * 因此ID分别需要设置为 $this->server_id$, $this->functionalci_id$, $this->id$
 * File Name: update_accounts_cache.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-06-9 17:08:30
 **/

require dirname(__FILE__).'/../etc/config.php';

$ID = getenv("ID");
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";

function accountsSetCache($ID) {
	global $config;
	global $iTopAPI;
	$ret = "";
	$sOql = "SELECT PhysicalIP WHERE connectableci_id  = '" . $ID . "' AND type!='oob'";
	$ips = json_decode($iTopAPI->coreGet("PhysicalIP", $sOql, "ipaddress"), true)['objects'];
	if($ips)
	{
		foreach($ips as $key => $value)
		{
			$ip = $value['fields']['ipaddress'];
			$url = trim($config['rooturl'], "/") . "/accounts.php?ip=" . $ip . "&cache=set";
			$ret = $ret . " - setcache_status:" . curlGet($url);
		}	
	}
	return($ret);
}
$ret = accountsSetCache($ID);
file_put_contents($log, $config['datetime'] . " - $ID - $ret\n", FILE_APPEND);
