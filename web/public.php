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
require 'types/default.php';
require 'types/related.php';
require 'types/app.php';
require 'types/url.php';

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');

function getContact($type, $value) 
{
	global $iTopAPI;
	global $config;

	$arr = explode(',',$value);
	$value = implode("','", $arr);

	switch($type)
	{
	case "app":
		$data = typeRelated($iTopAPI, "ApplicationSolution", $value, $config['related']['depth']['app']);
		//$data = typeApp($iTopAPI, $value);
		break;
	case "server":
		$data = typeRelated($iTopAPI,"Server", $value, $config['related']['depth']['server']);
		break;
	case "ip":
		$data = typeRelated($iTopAPI, "PhysicalIP", $value, $config['related']['depth']['server']);
		break;
	case "url":
		$data = typeRelated($iTopAPI, "Url", $value, $config['related']['depth']['url']);
		//$data = typeUrl($iTopAPI, $value);
		break;
	default:
		$data = typeRelated($iTopAPI,"FunctionalCI", $value, $config['related']['depth']['default']);
	}

	return($data);
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
