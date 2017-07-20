#!/usr/bin/php
<?
/**
 * Usage: 用于更新Server的contacts属性,Server和其他FunctionalCI不一样，是直接取contacts_list作为联系人。触发器作用
 * 于lnkContactToFunctionalCI, action参数 ID=$this->functionalci_id$
 * 也可以单独执行， ID="$id" ./update_server_contacts.php 可以批量更新contacts属性
 * File Name: update_server_contacts.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-04-24 17:08:30
 **/

require dirname(__FILE__).'/../etc/config.php';


$ID = getenv("ID");
$TITLE = getenv("TITLE");
$DEBUG = getenv("DEBUG");
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";

$oql = "SELECT Server AS f WHERE f.id = '" . $ID . "'";

// 可能是缓存原因，接口返回数据没有变化，导致用户删除自己负责的app时未更新contacts字段, 所以这里等几秒
if(!$DEBUG)
{
	sleep($config['update']['delay']);
}

$data = $iTopAPI->coreGet("Server", $oql, "contacts_list");
$obj = json_decode($data, true)['objects'];
if($obj)
{
	$contacts_arr = array();
	foreach($obj as $k => $v)
	{
		foreach($v['fields']['contacts_list'] as $key => $person)
		{
			$contacts_arr[] = preg_replace('/@.*/s', '', $person['contact_email']);
		}
	}
	$contacts = implode(",", $contacts_arr);
	$comment = "update contacts to $contacts from action-shell-exec";
	$ret = $iTopAPI->coreUpdate("FunctionalCI", $oql, array("contacts"=>$contacts),$comment);
	$ret = json_decode($ret, true);
	$ret = $contacts . " code:" . $ret['code'] . " message:" . $ret['message'];
} else {
	$ret = $data;
}
file_put_contents($log, $config['datetime'] . " - $ID - $TITLE - $ret\n", FILE_APPEND);
?>
