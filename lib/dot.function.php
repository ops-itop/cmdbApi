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
	//$node = $name . '[label="' . $label . '", shape=' . $shape . ', image="' . $image . '", labelloc=' . $labelloc . ']';
	$label = '<<table border="0" cellborder="0">' . 
		'<tr><td><img src="' . $image . '"/></td></tr>' . 
		'<tr><td>' . $label . '</td></tr></table>>';
	$node = $name . '[label=' . $label . ', shape=' . $shape . ']';
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

function _getDot($nodes, $edges, $rankdir="TB")
{
	if(!in_array($rankdir , array("TB", "LR")))
	{
		$rankdir = "TB";
	}
	$head = "digraph G{rankdir=" . $rankdir . ";";
	$nodes_str = implode(";", $nodes);
	$edges_str = implode(";", $edges);
	$tail = "}";
	return $head . $nodes_str . ";" . $edges_str . ";" . $tail;
}

function getDot($relations, $rankdir)
{
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
	$dot = _getDot($nodes, $edges, $rankdir);
	return($dot);
}
