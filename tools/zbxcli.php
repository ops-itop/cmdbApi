<?php
/**
 * Usage:
 * File Name: public.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/
require '../etc/config.php';
require '../composer/vendor/autoload.php';
require '../composer/vendor/confirm-it-solutions/php-zabbix-api/build/ZabbixApi.class.php';

define('ZBXURL', $config['zabbix']['url']);
define('ZBXUSER', $config['zabbix']['user']);
define('ZBXPWD', $config['zabbix']['password']);

$api = new \ZabbixApi\ZabbixApi(ZBXURL, ZBXUSER, ZBXPWD);

try
{
	if(isset($argv[1])){
		$assettag = $argv[1];
		$data = $api->hostGet(array(
			"output"=>array("host","inventory"),
			"selectInventory"=>array("asset_tag","vendor","model","tag","notes"),
			"searchInventory"=>array("asset_tag"=>$assettag)
		));
		die(json_encode($data));
	}else
	{
		$data = array("code" => "1", "errmsg" => "nothing to do");
		die(json_encode($data));
	}
}
catch(Exception $e)
{
	echo $e->getMessage();
}

