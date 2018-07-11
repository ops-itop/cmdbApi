<?php
/**
 * Usage:
 * File Name: public.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/

require 'common/init.php';

$allowed_ip = $config['resetpw']['allowed_ip'];

// 验证IP，只允许访问自己的数据, 
// 如果使用代理，需要配置 proxy_set_header     X-Forwarded-For $proxy_add_x_forwarded_for;
function checkIP($ip_para)
{
	$ip = getenv("HTTP_X_FORWARDED_FOR");
	if(!$ip)
	{
		$ip = $_SERVER["REMOTE_ADDR"];
	}
	if($ip_para == $ip)
	{
		return(true);
	}
	return(false);
}

function Error() {
	$data = array("code" => "1", "errmsg" => "action or id error");
	die(json_encode($data));
}

if(!isset($_GET['action'])) {
	Error();
}

if($_GET['action'] == "query") {
	$query = "SELECT Person WHERE status='active' AND reset_pwd='yes' AND gpg_pub_key!=''";
	$data = $iTopAPI->coreGet("Person", $query, "gpg_pub_key,email");
	$data = json_decode($data, true)['objects'];
	$ret = [];
	if($data != null){
		foreach($data as $k => $v) {
			$gpgKey = preg_replace('/^Comment:.*\r\n/m','',$v['fields']['gpg_pub_key']);
			$gpgKey = str_replace("\r\n","#", $gpgKey);
			$ret[] = $v['key'] . "," . $v['fields']['email'] . "," . $gpgKey;
		}
		die(implode("\n", $ret));
	}
} else if($_GET['action'] == "update") {
	if(!checkIP($allowed_ip)) {
		die("access denied");
	}
	if(isset($_GET['id']) && $_GET['id']) {
		$ids = explode(",", $_GET['id']);
		$r = [];
		foreach($ids as $k => $v) {
			$data = $iTopAPI->coreUpdate("Person", $v, array("reset_pwd"=>"no"));
			$data = json_decode($data,true);
			$r['id'] = $v;
			$r['code'] = $data['code'];
			$r['msg'] = $data['message'];
			die(json_encode($r));
		}
	} else {
		Error();
	}
} else {
	Error();
}
