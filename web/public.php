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

function getContact($type, $value, $direction = "TB", $depth = "5") 
{
	global $iTopAPI;
	global $config;

	$arr = explode(',',$value);
	$value = implode("','", $arr);

	switch($type)
	{
	case "app":
		$data = typeRelated($iTopAPI, "ApplicationSolution", $value, $direction, $depth);
		//$data = typeApp($iTopAPI, $value);
		break;
	case "server":
		$data = typeRelated($iTopAPI,"Server", $value, $direction, $depth);
		break;
	case "ip":
		$data = typeRelated($iTopAPI, "PhysicalIP", $value, $direction, $depth);
		break;
	case "url":
		$data = typeRelated($iTopAPI, "Url", $value, $direction, $depth);
		//$data = typeUrl($iTopAPI, $value);
		break;
	default:
		$data = typeRelated($iTopAPI,"FunctionalCI", $value, $direction, $depth);
	}

	return($data);
}

// 获取配置文件中定义的关联深度和图片方向
function getDotConfig($cfg, $type)
{
	global $config;

	// 定义默认值
	$default['depth'] = "5";	
	$default['direction'] = "TB";	

	if(!in_array($cfg, array("depth", "direction")))
	{
		$data = array("code" => "2", "errmsg" => "illegal value of \$cfg: $cfg");
		die(json_encode($data));
	}
	if(isset($config['related'][$cfg][$type]))
	{
		return($config['related'][$cfg][$type]);
	}else
	{
		if(isset($config['related'][$cfg]['default']))
		{
			return($config['related'][$cfg]['default']);
		}else
		{
			return($default[$cfg]);
		}
	}
}

if(isset($_GET['type']) and isset($_GET['value'])) {
	$type = $_GET['type'];
	$value = $_GET['value'];
	$direction = getDotConfig('direction', $type);
	if(isset($_GET['direction']))
	{
		$direction = $_GET['direction'];
	}
	$depth = getDotConfig('depth', $type);
	if(isset($_GET['depth']))
	{
		$depth = $_GET['depth'];
	}
	die(getContact($type, $value, $direction, $depth));
}else
{
	$data = array("code" => "1", "errmsg" => "type or value error");
	die(json_encode($data));
}
