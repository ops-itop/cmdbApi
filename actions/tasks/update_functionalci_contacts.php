#!/usr/bin/php
<?
/**
 * Usage: 用于更新FunctionalCI的contacts属性。action参数 ID=$this->friendlyname$
 *        也可以单独执行， ID="$hostname" ./update_functionalci_contacts.php 可以批量更新contacts属性
 * File Name: update_functionalci_contacts.php.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-04-24 17:08:30
 **/

require dirname(__FILE__).'/../etc/config.php';


$ID = getenv("ID");
$TITLE = getenv("TITLE");
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";

$oql = "SELECT FunctionalCI AS f WHERE f.friendlyname = '" . $ID . "'";
$data = $iTopAPI->coreGet("FunctionalCI", $oql, "contacts_list");
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
	print_r($ret);
	$ret = $contacts . " " . json_encode(json_decode($ret, true)['message']);
} else {
	$ret = $data;
}
file_put_contents($log, $config['datetime'] . " - $ID - $TITLE - $ret\n", FILE_APPEND);
?>
