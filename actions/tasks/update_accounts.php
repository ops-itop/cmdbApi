#!/usr/bin/php
<?
/**
 * Usage: 用于更新lnkUserToServer状态，并更新accounts.php的缓存。action参数 ID=$this->ref$
 * File Name: update_accounts.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-04-24 17:08:30
 **/

require dirname(__FILE__).'/../etc/config.php';

$ID = getenv("ID");
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";

$oql = "SELECT UserRequest AS t WHERE t.ref = '" . $ID . "'";
$data = $iTopAPI->coreGet("UserRequest", $oql, "functionalcis_list, caller_id");
$obj = json_decode($data, true)['objects'];

$server_arr = array(); // 工单涉及服务器列表
$msg = array();

// 更新lnkUserToServer状态
if($obj)
{
	$comment = "update from action-shell-exec";
	foreach($obj as $k => $v)
	{
		$t1 = microtime(true);
		$caller_id = $v['fields']['caller_id'];
		foreach($v['fields']['functionalcis_list'] as $key => $item)
		{
			if($item['impact_code'] == "manual" && $item['functionalci_id_finalclass_recall'] == "Server")
			{
				$server_arr[] = $item['functionalci_id'];
				$aOql = "SELECT lnkUserToServer WHERE user_contactid = " . $caller_id . 
					" AND server_id = " . $item['functionalci_id'];
				$ret = $iTopAPI->coreUpdate('lnkUserToServer', $aOql, array("status"=>"enabled"), $comment);
				$aMsg = json_decode($ret, true)['message'];
				if($aMsg == "")
				{
					$aMsg = "Success";
				}
				$msg[] = "服务器 ". $item['functionalci_id_friendlyname'] . ": " . $aMsg;
			}
		}
		$t2 = microtime(true);
		$spt = "更新账号状态耗时: " . (string)($t2 - $t1) . "</p>";
		$public_log = "<p>账号更新结果:<br>" . implode("<br>", $msg) . "</p><p>" . $spt;
		$iTopAPI->coreUpdate('UserRequest', $oql, array("public_log"=>$public_log), $comment);
	}
	$ret =  implode(",", $msg);
} else {
	$ret = $data;
}

// 更新accounts.php 接口缓存
$servers = implode("','", $server_arr);
$sOql = "SELECT PhysicalIP WHERE connectableci_id IN ('" . $servers . "') AND type!='oob'";
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

file_put_contents($log, $config['datetime'] . " - $ID - $ret\n", FILE_APPEND);
?>
