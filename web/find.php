<?php
/**
 * Usage:
 * File Name: public.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/
require 'common/init.php';

if(isset($_GET['type']) and isset($_GET['value'])) {
	if(isset($_GET['key'])){
		$key = $_GET['key'];
	}else {
		$key = "name";
	}
	$type = $_GET['type'];
	$value = $_GET['value'];
	$query = "SELECT " . $config['map'][$type] . " AS t WHERE t.$key='$value'";
	$data = $iTopAPI->coreGet("FunctionalCI", $query);
	$data = json_decode($data, true);
	if($data['objects'] != null){
		die("1");
	}
	die("0");
}else
{
	$data = array("code" => "1", "errmsg" => "type or value error");
	die(json_encode($data));
}
