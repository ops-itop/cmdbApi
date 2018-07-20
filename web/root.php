<?php
/**
 * Usage:
 * File Name: public.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/

require 'common/init.php';

function Error($msg="") {
	die("Error $msg\n");
}

if(!isset($_POST['action'])) {
	Error("no action");
}

function getGpgKey() {
	global $iTopAPI;
	$query = "SELECT Person AS p JOIN User AS u ON u.contactid=p.id JOIN URP_UserProfile AS upr ON upr.userid=u.id JOIN URP_Profiles AS profile ON upr.profileid=profile.id WHERE profile.name='Administrator' AND p.status='active' AND gpg_pub_key!=''";
	$data = $iTopAPI->coreGet("Person", $query, "gpg_pub_key,email");
	$data = json_decode($data, true)['objects'];
	$ret = [];
	if($data != null){
		foreach($data as $k => $v) {
			$gpgKey = preg_replace('/^Comment:.*\r\n/m','',$v['fields']['gpg_pub_key']);
			$gpgKey = str_replace("\r\n","#", $gpgKey);
			$ret[] = $gpgKey;
		}
	}
	$retStr = implode("\n", $ret);
	return $retStr;
}

function getNotManaged() {
	global $iTopAPI;
	$query = "SELECT Server WHERE manage_rootpwd='no'";
	$data = $iTopAPI->coreGet("Server", $query, "name");
	$data = json_decode($data, true)['objects'];
	$ret = [];
	if($data != null) {
		foreach($data as $k => $v) {
			$ret[] = $v['fields']['name'];
		}
	}
	$retStr = implode(",", $ret);
	$retStr = "NotManagedServer:" . $retStr;
	return $retStr;
}

if($_POST['action'] == "query") {
	$gpgData = getGpgKey();
	$notManaged = getNotManaged();
	$ret = "OK\n" . $notManaged . "\n" . $gpgData;
	die($ret);
} else if($_POST['action'] == "update") {
	if(isset($_POST['ip']) && $_POST['ip'] && isset($_POST['sn']) && $_POST['sn'] && isset($_POST['pwd']) && isset($_POST['date'])) {
		// 检查ip
		if(!checkIP($_POST['ip'])) {
			Error("access denied");
		}
		// 检查ip和sn是否对应
		$query = "SELECT Server AS s JOIN PhysicalIP AS ip ON ip.connectableci_id=s.id WHERE ip.ipaddress='" . 
			$_POST['ip'] . "' AND s.name='" . $_POST['sn'] . "'";
		$data = $iTopAPI->coreGet("Server", $query, "name");
		$data = json_decode($data, true)['objects'];
		if($data == null) {
			Error("sn ip not match");
		}
		$id = reset($data)['key'];
		$data = $iTopAPI->coreUpdate("Server", $id, array("rootpwd"=>$_POST['pwd'],'rootpwd_date'=>$_POST['date']));
		$data = json_decode($data,true);
		if($data['message'] == null && $data['code'] == 0) {
			die("SUCC\n");
		} else {
			die("FAILED: " . $data['message'] . "\n");
		}
	} else {
		Error("param error");
	}
} else {
	Error("action value error");
}
