<?php
/**
 * Usage:
 * File Name: public.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/
require 'common/init.php';
require 'types/default.php';
require 'types/related.php';
require 'types/app.php';
require 'types/url.php';

function getContact($type, $value, $rankdir = "TB", $depth = "5", $direction="down", $filter, $hide=array(), $show=array()) 
{
	global $iTopAPI;
	global $config;

	$arr = explode(',',$value);
	$value = implode("','", $arr);

	isset($config['map'][$type]) ? $type = $config['map'][$type] : die(json_encode(errorMsg("typeerror", $type)));
	$data = typeRelated($iTopAPI, $type, $value, $rankdir, $depth, $direction, $filter, $hide, $show);
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

function ReadParam($arr, $key, $default, $isConfig=true)
{
	if(isset($arr[$key]))
	{
		return($arr[$key]);
	}elseif($isConfig)
	{
		return(getDotConfig($key, $arr['type']));
	}else
	{
		return($default);
	}
}

function errorMsg($id,$arg="") {
	$data = array();
	$data["relations"] = [];
	$data["objects"] = null;
	$data["imgurl"] = "";

	switch ($id)
	{
	case "missing":
		$data["code"] = 100;
	    $data["message"] = "mandatory params missing(type or value)";
		break;
	case "typeerror":
		$data["code"] = 100;
		$data["message"] = "no definition type: $arg";
		break;
	default:
		$data["code"] = 100;
		$data["message"] = "unknown error";
	}
	return($data);
}

header("Content-Type: application/json");
if(isset($_GET['type']) and isset($_GET['value'])) {
	$type = $_GET['type'];
	$value = $_GET['value'];
	$rankdir = ReadParam($_GET, 'rankdir', "TB");
	$depth = ReadParam($_GET, 'depth', "8");
	$direction = ReadParam($_GET, 'direction', "down", false);
	$filter = ReadParam($_GET, 'filter', "Person", false);
	$hide = array_filter(explode(",", ReadParam($_GET, 'hide', $config['related']['hide'], false)));
	$show = array_filter(explode(",", ReadParam($_GET, 'show', "", false)));
	die(getContact($type, $value, $rankdir, $depth, $direction, $filter, $hide, $show));
}else
{
	die(json_encode(errorMsg("missing")));
}
