<?php
/**
 * Usage:
 * File Name: default.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/

// url监控报警需要支持第三方联系人
function GetUrlThirdContact($iTopAPI, $value) 
{
	$output = "applicationsolution_name, third_email, third_phone";
	//$output = "friendlyname, email, phone";
	$query = "SELECT Url AS url WHERE url.name IN ('$value') AND url.status = 'production'";
	$data = $iTopAPI->coreGet("Url", $query, $output);
	$data_arr = json_decode($data, true);
	$obj = $data_arr['objects'];
	$third_person = array();
	if(!$obj)
	{
		return($data);
	}

	foreach($obj as $k=>$v)
	{
		$person = array(
			'fields' => array(
				'email' => $v['fields']['third_email'],
				'phone' => $v['fields']['third_phone']
			)
		);
		$third_person['Person_third_'.$k] = $person;
	}

	return($third_person);
}

function dealData($data_arr, $rankdir)
{
	global $config;
	$strip = $config['node']['strip'];
	// dot code
	$relations = $data_arr['relations'];
	$dot = getDot($relations, $rankdir, $strip);
	$imgurl = getImgUrl($config['graph']['url'], $dot, $config['graph']['postsize']);
	//$imgurl = $config['graph']['url'] . "?cht=gv:dot&chl=" . urlencode($dot);
	$data_arr['imgurl'] = $imgurl;
	return(json_encode($data_arr));
}

function typeRelated($iTopAPI, $type, $value, $rankdir="TB", $depth="8", $direction="down", $filter="Person", $hide=array(), $show=array()) 
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
	$filter = explode(",", $filter);
	$optional = array(
		'output_fields' => $config['output_fields'],
		'show_relations' => $show,
		'hide_relations' => $hide,
		'direction' => $direction,
		'filter' => $filter,
		'depth' => $depth,
	);
	$data = $iTopAPI->extRelated($type, $query, "impacts", $optional);
	$data_arr = json_decode($data, true);
	
	// 取url第三方联系人
	if($type == "Url")
	{
		$third = GetUrlThirdContact($iTopAPI, $value);
		$data_arr['objects'] = array_merge($data_arr['objects'], $third);
	}

	return(dealData($data_arr, $rankdir));
}

