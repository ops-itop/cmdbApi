<?php
/**
 * Usage:
 * File Name: api.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-08-03 10:05:48
 **/

require '../etc/config.php';
require '../composer/vendor/autoload.php';

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);
define('SK', $config['api']['sk']);

$err = array(
	"0" => "ok",
	"101" => "missing params:action",
	"102" => "missing params:id, user_satisfaction or user_comment",
	"103" => "missing params:id",
	"110" => "action error:reopen or close",
	"120" => "itop api error",
	"130" => "missing params sign or sign error",
);

// 校验签名
function checkSign($action, $params, $sign) {
	if(!isset($params['id'])) die(errMsg("103"));
	$_sign = sha1(md5($action . $params['id'] . SK));
	if($_sign == $sign){
		return True;
	}
	return False;
}

function errMsg($code) {
	global $err;
	$msg = array("code" => $code, "message" => $err[$code]);
	return json_encode($msg);
}

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');

function closeTicket($params) {
	global $iTopAPI;
	if(!isset($params['user_satisfaction']) || !isset($params['user_comment']) || !isset($params['id']))
	{
		die(errMsg("102"));
	}

	$data = $iTopAPI->coreApply_stimulus("UserRequest", (int)$params['id'], array(
		'user_satisfaction' => $params['user_satisfaction'],
		'user_comment' => $params['user_comment']
	), 'ev_close');
	$ret = json_decode($data, true);
	if($ret['code'] == 0) {
		die(errMsg("0"));
	}
	die($data);
}

function reopenTicket($params) {
	global $iTopAPI;
	if(!isset($params['id'])) {
		die(errMsg("103"));	
	}
	$data = $iTopAPI->coreApply_stimulus("UserRequest", (int)$params['id'], array(
	), 'ev_reopen');
	$ret = json_decode($data, true);
	if($ret['code'] == 0) {
		die(errMsg("0"));
	}
	die($data);
}


if(!isset($_GET['action'])) {
	die(errMsg("101"));
} 
if(!isset($_GET['sign'])) {
	die(errMsg("130"));
}

$action = $_GET['action'];
$sign = $_GET['sign'];
if(!checkSign($action, $_GET, $sign)) {
	die(errMsg("130"));
}

switch($action) {
	case "reopen":reopenTicket($_GET);break;
	case "close":closeTicket($_GET);break;
	default:die(errMsg("110"));
}
