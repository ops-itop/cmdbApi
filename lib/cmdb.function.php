<?php
/**
 * Usage:
 * File Name: cmdb.function.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2018-06-14 16:33:51
 **/

/**
 * FunctionalCI的联系人取链接的APP的联系人
 * $fid: functionalci id
 * $iTopAPI: itop api对象
 */
function GetFunctionalCIContacts($fid, $iTopAPI) {
	$contacts_arr = [];
	$optional = array(
		"depth"=>1,
		"filter"=>["ApplicationSolution"],
		"output_fields"=>["ApplicationSolution"=>"contact_list_custom"]
	);
	$data = $iTopAPI->extRelated("FunctionalCI", $fid, "impacts", $optional);
	$obj = json_decode($data, true)['objects'];
	if(!$obj) return $contacts_arr;

	foreach($obj as $k => $v) {
		foreach($v['fields']['contact_list_custom'] as $key => $val) {
			$contacts_arr[] = preg_replace('/@.*/s', '', $val['contact_email']);
		}
	}
	$contacts_arr = array_unique($contacts_arr);
	return $contacts_arr;
}


/**
 * 获取所有APP
 */
function getApps($iTopAPI)
{
	$oql = "SELECT ApplicationSolution AS app WHERE app.status='production'";
	$data = $iTopAPI->coreGet("ApplicationSolution", $oql, "name");
	$apps = json_decode($data, true)['objects'];
	$arr = [];
	foreach($apps as $key => $val) {
		$arr[$val['key']] = $val['fields']['name'];
	}
	return $arr;
}

/**
 * 更新accounts.php缓存
 */
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

