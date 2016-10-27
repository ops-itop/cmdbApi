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

function runOQL($class, $key, $isGet="get", $show = array(), $depth = "5", $direction="down") 
{
	global $iTopAPI;
	global $config;

	#$key = array("name" => "cmdb");
	#$key = json_encode($key);
	#print_r($key);
	if (!$key) {
		$data = array("code" => "1", "errmsg" => "type error");
		die(json_encode($data));
	}

	switch($isGet)
	{
	case "get":
		$data = $iTopAPI->coreGet($class, $key);
		break;
	case "extrelated":
		$data = $iTopAPI->extRelated($class, $key, "impacts");
		break;
	case "related":
		$data = $iTopAPI->coreRelated($class, $key, "20", $direction);
		break;
	default:
		$data = $iTopAPI->coreGet($class, $key);
	}
	return($data);
}

function export_csv($class, $key)
{
	global $config;
	$depth = "2";
	if(isset($config['related']['depth']['export_csv']))
	{
		$depth = $config['related']['depth']['export_csv'];
	}
	$data = json_decode(runOQL($class, $key, "extrelated", array(), $depth), true);
	$persons = $data['objects'];
	$relations = $data['relations'];
	$csv_array = array();
	foreach($relations as $k => $relation)
	{
		$obj = explode("::", $k);
		$obj_type = $obj[0];
		$obj_value = $obj[2];
		if($obj_type == "RDS")
		{
			$rds = $obj_value;
			$apps = array();
			$contacts = array();
			foreach($relation as $relation_key)
			{
				$relation_key_obj  = explode("::", $relation_key['key']);
				if($relation_key_obj[0] == "ApplicationSolution")
				{
					$app = $relation_key_obj[2];
					array_push($apps, $app);
					$relation_app_obj = $relations[$relation_key['key']];
					foreach($relation_app_obj as $relation_app)
					{
						$obj_person = explode("::", $relation_app['key']);
						if($obj_person[0] != "Person")
						{
							continue;
						}
						$person_key = $obj_person[0] . "::" . $obj_person[1];
						$person = $persons[$person_key]['fields'];
						$contact = str_replace(")", "|".$person['phone'].")", $person['friendlyname']);
						array_push($contacts, $contact);
					}
				}
			}
			$app_str = implode(";", array_unique($apps));
			$contact_str = implode(";", array_unique($contacts));
			$csv = $rds . "," . $app_str . "," . $contact_str;
			array_push($csv_array, $csv);
		}	
	}
	$csv_str = implode("\n", $csv_array);
	return($csv_str);
}

if(isset($argv[1]) and isset($argv[2])){
	if(isset($argv[3]))
	{
		if($argv[3] == "extrelated")
		{
			die(runOQL($argv[1], $argv[2], $argv[3]));
		}
		if($argv[3] == "csv")
		{
			print_r(export_csv($argv[1], $argv[2]));
		}else
		{
			die(runOQL($argv[1], $argv[2], $argv[3]));
		}
	}else
	{
		die(runOQL($argv[1], $argv[2]));
	}
}else
{
	$data = array("code" => "1", "errmsg" => "nothing to do");
	die(json_encode($data));
}
