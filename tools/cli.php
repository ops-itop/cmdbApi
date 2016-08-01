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

function runOQL($class, $key) 
{
	global $iTopAPI;

	#$key = array("name" => "cmdb");
	#$key = json_encode($key);
	#print_r($key);
	if (!$key) {
		$data = array("code" => "1", "errmsg" => "type error");
		die(json_encode($data));
	}
	$data = $iTopAPI->coreGet($class, $key);
	return(json_encode($data));
}

if(isset($argv[1]) and isset($argv[2])){

	die(runOQL($argv[1], $argv[2]));
}else
{
	$data = array("code" => "1", "errmsg" => "nothing to do");
	die(json_encode($data));
}
