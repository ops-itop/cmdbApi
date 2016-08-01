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

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');

function getContact($type, $value) 
{
	global $iTopAPI;

	$arr = explode(',',$value);
	$value = implode("','", $arr);

	$output = "friendlyname, email, phone";
	switch($type)
	{
	case "app":
		$query = "SELECT Person AS p JOIN lnkContactToApplicationSolution AS l ON l.contact_id=p.id WHERE l.applicationsolution_name IN ('$value') AND p.status='active' AND p.notify='yes'";
		$data = $iTopAPI->coreGet("Person", $query, $output);
		break;
	default:
		$output = "contacts_list,applicationsolution_list";
		$query = "SELECT FunctionalCI AS f WHERE f.name IN ('$value')";
		$data = $iTopAPI->coreGet("FunctionalCI", $query, $output);
		$result = $data['objects'];
		if($result){
			foreach($result as $k=>$v){
				$contacts = $v['fields']['contacts_list'];
				$apps = $v['fields']['applicationsolution_list'];
			}
		}
		$c_array = array();
		foreach($contacts as $k=>$v){
			array_push($c_array,$v['contact_id']);
		}

		$app_array = array();
		foreach($apps as $k=>$v){
			array_push($app_array,$v['applicationsolution_id']);
		}

		$c_str = implode("','", $c_array);
		$app_str = implode("','", $app_array);
		$query = "SELECT Person AS p JOIN lnkContactToApplicationSolution AS l ON l.contact_id=p.id " . 
			"WHERE l.applicationsolution_id IN ('$app_str') AND p.status='active' AND p.notify='yes' " .
			"UNION SELECT Person AS p WHERE p.id IN ('$c_str') AND p.status='active' AND p.notify='yes'";
		$output = "friendlyname, email, phone";
		$data = $iTopAPI->coreGet("Person", $query, $output);
	}

	return(json_encode($data));
}

if(isset($_GET['type']) and isset($_GET['value'])) {
	$type = $_GET['type'];
	$value = $_GET['value'];
	die(getContact($type, $value));
}else
{
	$data = array("code" => "1", "errmsg" => "type or value error");
	die(json_encode($data));
}
