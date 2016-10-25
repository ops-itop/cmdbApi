<?php
/**
 * Usage:
 * File Name: default.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/
function getNode($key)
{
	$arr = explode("::", $key);
	$image = "images/" . strtolower($arr[0]) . ".png";
	$name = $arr[0] . $arr[1];
	$label = $arr[0] . "::" . $arr[2];
	$shape = "none";
	$labelloc = "b";
	$node = $name . '[label="' . $label . '", shape=' . $shape . ', image="' . $image . '", labelloc=' . $labelloc . ']';
	return $node;
}

function getEdge($src, $dest)
{
	$src_arr = explode("::", $src);
	$src_name = $src_arr[0] . $src_arr[1];
	$dest_arr = explode("::", $dest);
	$dest_name = $dest_arr[0] . $dest_arr[1];
	$edge = $src_name . "->" . $dest_name;
	return $edge;
}

function getDot($nodes, $edges, $direction="TB")
{
	if(!in_array($direction , array("TB", "LR")))
	{
		$direction = "TB";
	}
	$head = "digraph G{rankdir=" . $direction . ";";
	$nodes_str = implode(";", $nodes);
	$edges_str = implode(";", $edges);
	$tail = "}";
	return $head . $nodes_str . ";" . $edges_str . ";" . $tail;
}

function typeRelated($iTopAPI, $type, $value, $direction="TB", $depth="8") 
{
	global $config;
	if($type == "PhysicalIP") {
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
	$output = "friendlyname,email,phone";
	$hide = $config['related']['hide'];
	if(intval($depth)<1)
	{
		$depth = "0";
	}
	$data = $iTopAPI->extRelatedPerson($type, $query, $output, $hide, $depth);

	// dot code
	$data_arr = json_decode($data, true);
	$relations = $data_arr['relations'];
	$nodes = array();
	$edges = array();
	foreach($relations as $key => $value)
	{
		$node = getNode($key);
		if(!in_array($node, $nodes))
		{
			array_push($nodes, $node);
		}
		foreach($value as $v)
		{
			$node = getNode($v['key']);
			if(!in_array($node, $nodes))
			{
				array_push($nodes, $node);
			}
			$edge = getEdge($key, $v['key']);
			if(!in_array($edge, $edges))
			{
				array_push($edges, $edge);
			}
		}
	}
	$dot = getDot($nodes, $edges, $direction);
	$imgurl = $config['graph']['url'] . "?cht=gv:dot&chl=" . $dot;
	$data_arr['imgurl'] = $imgurl;
	return(json_encode($data_arr));
}
