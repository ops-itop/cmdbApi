#!/usr/bin/php
<?
/**
 * Usage: 用于更新FunctionalCI(Server, MiddleWare,Domain等contacts属性取下游App的contacts)的contacts属性。
 * 触发器作用于lnkContactToApplicationSolution， action参数: ID=>$this->applicationsolution_id$
 * 也作用于lnkApplicationSolutionToFunctionalCI, app依赖变更时也需要更新联系人， action参数: ID=>$this->applicationsolution_id$
 * 也可以单独执行， ID="$id" ./update_functionalci_contacts.php 可以批量更新contacts属性
 * File Name: update_functionalci_contacts.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-04-24 17:08:30
 **/

require dirname(__FILE__).'/../etc/config.php';


$ID = getenv("ID");
$TITLE = getenv("TITLE");
$DEBUG = getenv("DEBUG");
$script = explode("/", $argv[0]);
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end($script) . ".log";

// 可能是缓存原因，接口返回数据没有变化，导致用户删除自己负责的app时未更新contacts字段, 所以这里等几秒
if(!$DEBUG)
{
	sleep($config['update']['delay']);
}

function getData($ID) {
	global $iTopAPI;
	// 取app上游的一级关联，并排除Server和集群,App(上游app的联系人不受下游app影响),机柜等
	$hide_relations = array("Rack");
	$filter = array("ApplicationSolution");
	$output_fields = array("ApplicationSolution"=>"contact_list_custom"); // 顺便取到app的联系人
	$optional = array("filter"=>$filter,"hide_relations"=>$hide_relations,"depth"=>1, 
		"direction"=>"up","output_fields"=>$output_fields);

	$data = json_decode($iTopAPI->extRelated("ApplicationSolution", $ID, "impacts", $optional), true);
	return $data;
}

function getContacts($data,$ID) {
	// 更新app的联系人
	$contacts_arr = array();
	if($data['objects'])
	{
		$app_contact = $data['objects']["ApplicationSolution::$ID"]['fields']['contact_list_custom'];
		foreach($app_contact as $k => $person)
		{
			if($person['contact_id_finalclass_recall'] == "Person")
				$contacts_arr[] = preg_replace('/@.*/s', '', $person['contact_email']);
		}
	}

	return $contacts_arr;
}

function doUpdate($fid,$contacts_arr)
{
	global $iTopAPI;
	// contacts字段最多支持255个字符，因此这里取contacts_arr的前5个作为contacts字段，多余的忽略
	if(count($contacts_arr) > 10)
	{
		$contacts_arr = array_slice($contacts_arr, 0, 5);
	}
	$contacts = implode(",", $contacts_arr);
	$comment = "update contacts to $contacts from action-shell-exec";
	$ret = $iTopAPI->coreUpdate("FunctionalCI", $fid, array("contacts"=>$contacts),$comment);
	$ret = json_decode($ret, true);
	$ret = $fid . " - " . $contacts . " code:" . $ret['code'] . " message:" . $ret['message'];
	return($ret);
}

function updateContacts($ID) {
	global $TITLE;
	global $iTopAPI;
	global $log;
	global $config;
	$result = array();
	$data = getData($ID);
	$contacts_arr = getContacts($data, $ID);
	// 更新APP联系人
	$result[] = "ApplicationSolution:" . doUpdate($ID,$contacts_arr);
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
				if($recall == "Server") {
					$ret = accountsSetCache($fid);
					$accountlog = str_replace(".log", "-accountcache.log", $log);
					file_put_contents($accountlog, $config['datetime'] . " - $fid - $ret\n", FILE_APPEND);
				}
				$upContacts = GetFunctionalCIContacts($fid, $iTopAPI);
				$result[] = $recall . ":" . doUpdate($fid, $upContacts);
			}
		}
	}else{
		$result[] = "noUpstream";	
	}
	$ret = implode(" # ", $result);
	file_put_contents($log, $config['datetime'] . " - $ID - $TITLE - $ret\n", FILE_APPEND);
}

if($ID == "all") {
	$ids = getApps($iTopAPI);
	foreach($ids as $k => $v) {
		print("update:$k:$v\n");
		updateContacts($k);
	}
} else {
	updateContacts($ID);
}

?>
