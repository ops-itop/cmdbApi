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

function getContact($type, $value, $rankdir = "TB", $depth = "5", $show=array()) 
{
	global $iTopAPI;
	global $config;

	$arr = explode(',',$value);
	$value = implode("','", $arr);

	$data = typeRelated($iTopAPI, $config['map'][$type], $value, $rankdir, $depth, "down", $show);
	return($data);
}

// 获取配置文件中定义的关联深度和图片方向
function getDotConfig($cfg, $type)
{
	global $config;

	// 定义默认值
	$default['depth'] = "5";	
	$default['rankdir'] = "TB";	

	if(!in_array($cfg, array("depth", "rankdir")))
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

function ReadParam($arr, $key, $isConfig=true)
{
	if(isset($arr[$key]))
	{
		return($arr[$key]);
	}elseif($isConfig)
	{
		return(getDotConfig($key, $arr['type']));
	}else
	{
		return("");
	}
}

if(isset($_GET['type']) and isset($_GET['value'])) {
	$type = $_GET['type'];
	$value = $_GET['value'];
	$rankdir = ReadParam($_GET, 'rankdir');
	$depth = ReadParam($_GET, 'depth');
	$show = array_filter(explode(",", ReadParam($_GET, 'show', false)));
	die(getContact($type, $value, $rankdir, $depth, $show));
}else
{
	$data = array("code" => "1", "errmsg" => "type or value error");
	die(json_encode($data));
}
