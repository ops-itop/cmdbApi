<?php
/**
 * Usage:
 * File Name: default.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/

define("__ROOT__",dirname(__FILE__));
require __ROOT__ . '/../../lib/core.function.php';

function dealData($data, $rankdir)
{
	global $config;
	$strip = $config['node']['strip'];
	// dot code
	$data_arr = json_decode($data, true);
	$relations = $data_arr['relations'];
	$dot = getDot($relations, $rankdir, $strip);
	$imgurl = getImgUrl($config['graph']['url'], $dot, $config['graph']['postsize']);
	//$imgurl = $config['graph']['url'] . "?cht=gv:dot&chl=" . urlencode($dot);
	$data_arr['imgurl'] = $imgurl;
	return(json_encode($data_arr));
}

function typeRelated($iTopAPI, $type, $value, $rankdir="TB", $depth="8", $direction="down", $hide=array(), $show=array()) 
{
	global $config;
	if($type == "Person") {
		$query = "SELECT Person AS p WHERE p.login IN ('$value')";
		$direction = "up";
	}elseif($type == "PhysicalIP") {
		$query = "SELECT Server AS s JOIN $type AS ip ON ip.connectableci_id=s.id " .
					"WHERE ip.ipaddress IN ('$value')";
	}else
	{
		$name = "name";
		if($type == "Server") {
			$name = "hostname";	
		}
		$query = "SELECT $type AS f WHERE f.$name IN ('$value')";
	}
	if(intval($depth)<1)
	{
		$depth = "0";
	}
	$optional = array(
		'output_fields' => array("Person" => "friendlyname,email,phone"),
		'show_relations' => $show,
		'hide_relations' => $hide,
		'direction' => $direction,
		'depth' => $depth,
	);
	$data = $iTopAPI->extRelated($type, $query, "impacts", $optional);
	return(dealData($data, $rankdir));
}
