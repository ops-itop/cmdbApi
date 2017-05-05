#!/usr/bin/php
<?
/**
 * Usage: 用于更新FunctionalCI(除Server外, MiddleWare,Domain等contacts属性取下游App的contacts)的contacts属性。
 * 触发器作用于lnkContactToApplicationSolution， action参数: ID=>$this->applicationsolution_id$
 * 也可以单独执行， ID="$id" ./update_functionalci_contacts.php 可以批量更新contacts属性
 * File Name: update_functionalci_contacts.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-04-24 17:08:30
 **/

require dirname(__FILE__).'/../etc/config.php';


$ID = getenv("ID");
$TITLE = getenv("TITLE");
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";

// 取app上游的一级关联，并排除Server和集群,App(上游app的联系人不受下游app影响),机柜等
$hide_relations = array("Rack", "Server");
$filter = array("ApplicationSolution");
$output_fields = array("ApplicationSolution"=>"contact_list_custom"); // 顺便取到app的联系人
$optional = array("filter"=>$filter,"hide_relations"=>$hide_relations,"depth"=>1, 
	"direction"=>"up","output_fields"=>$output_fields);
$data = json_decode($iTopAPI->extRelated("ApplicationSolution", $ID, "impacts", $optional), true);

$result = array();
// 更新app的联系人
if($data['objects'])
{
	$contacts_arr = array();
	$app_contact = $data['objects']["ApplicationSolution::$ID"]['fields']['contact_list_custom'];
	foreach($app_contact as $k => $person)
	{
		if($person['contact_id_finalclass_recall'] == "Person")
			$contacts_arr[] = preg_replace('/@.*/s', '', $person['contact_email']);
	}
	$result[] = "ApplicationSolution:" . doUpdate($ID,$contacts_arr);
}else{
	$result[] = $data;
}

// 更新上游配置项的联系人
if($data['relations'])
{
	foreach($data['relations'] as $k => $v)
	{
		$item = explode("::", $k);
		$recall = $item[0];
		$fid = $item[1];
		// 排除App(上游app的联系人不受下游app影响)
		if($recall != "ApplicationSolution")
		{
			$result[] = $recall . ":" . updateUpstream($fid);
		}
	}
}else{
	$result[] = $data;	
}

function updateUpstream($fid)
{
	global $iTopAPI;
	$data = $iTopAPI->extRelated("FunctionalCI", $fid, "impacts", array("depth"=>2));
	$obj = json_decode($data, true)['objects'];
	$contacts_arr = array();
	if($obj)
	{
		foreach($obj as $k => $v)
		{
			$contacts_arr[] = preg_replace('/@.*/s', '', $v['fields']['email']);	
		}
	}
	// contacts字段最多支持255个字符，因此这里取contacts_arr的前10个作为contacts字段，多余的忽略
	$contacts_arr = array_slice($contacts_arr, 0, 10);
	return(doUpdate($fid, $contacts_arr));
}

function doUpdate($fid,$contacts_arr)
{
	global $iTopAPI;
	$contacts = implode(",", $contacts_arr);
	$comment = "update contacts to $contacts from action-shell-exec";
	$ret = $iTopAPI->coreUpdate("FunctionalCI", $fid, array("contacts"=>$contacts),$comment);
	$ret = json_decode($ret, true);
	$ret = $fid . " - " . $contacts . " code:" . $ret['code'] . " message:" . $ret['message'];
	return($ret);
}

$ret = implode(" # ", $result);
file_put_contents($log, $config['datetime'] . " - $ID - $TITLE - $ret\n", FILE_APPEND);
?>
