<?php
/**
 * Usage: 用于快速重部开发环境
 * File Name: k8s.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2018-04-28 18:35:08
 **/

require 'common/init.php';

// dashboard proxy使用， 返回某个联系人所有部署列表
function dashboard($person) {
	$default = json_encode([]);
	if(!$person) {
		return $default;
	}

	global $iTopAPI;
	$query = "SELECT ApplicationSolution AS app JOIN lnkContactToApplicationSolution AS l ON l.applicationsolution_id=app.id JOIN Person AS p ON l.contact_id=p.id WHERE p.login='" . $person . "'";

	$data = $iTopAPI->coreGet("ApplicationSolution", $query, "name");
	$data = json_decode($data, true)['objects'];
	if(!$data) return $default;

	$apps = [];
	foreach($data as $key => $val) {
		$apps[] = $val['fields']['name'];
	}
	return(json_encode($apps));
}

// 主机名前缀为 k8s-node, k8s-router, k8s-master
function labels($hostpre = ['k8s-node%', 'k8s-router-%', 'k8s-master%']) {
	global $iTopAPI;
	// labels
	$cluster = "default";
	$role = "default";
	$cpu = "default";
	$location = "default";
	
	$query = "SELECT Server WHERE ";
	$conditions = [];
	foreach($hostpre as $k => $v) {
		$conditions[] = "hostname LIKE '" . $v . "'";
	}
	$conditions = implode(' OR ', $conditions);
	$query .= $conditions;
	$data = $iTopAPI->coreGet("Server", $query, "*");
	$data = json_decode($data, true)['objects'];
	//die(json_encode($data));
	$kubectl = [];
	foreach($data as $key => $val) {
		$cmd = "/usr/local/bin/kubectl label nodes ";
		if(count($val['fields']['ip_list']) == 0) {
			continue;
		}
		foreach($val['fields']['ip_list'] as $ip) {
			if($ip['type'] == 'int') {
				$node = $ip['ipaddress'];
				$cmd .= $node;
				break;
			}
		}
		$hostname = explode(".", $val['fields']['hostname']);
		$hostname = explode("-", $hostname[0]);
		$role = $hostname[1];
		$cluster = 'default';
		if(count($hostname) == 3) {
			$cluster = $hostname[2];
		}
		if($val['fields']['cpu'] != "") {
			$cpu = $val['fields']['cpu'];
		}
		$location = $val['fields']['location_id'];
		$cmd .= " cluster=$cluster role=$role cpu=$cpu location=$location";
		$kubectl[] = $cmd . ' --overwrite';
	}
	return implode("\n", $kubectl);
}

$dev = $config['k8s']['dev'];

if(isset($_GET['app']) && isset($_GET['cluster']) && $_GET['cluster'] != '' && $_GET['app'] !='') {
	$cluster = $_GET['cluster'];
	$app = $_GET['app'];
	if(!in_array($cluster, $dev)) {
		die("$cluster auto update not allowd");
	}

	$oql = "SELECT Deployment WHERE applicationsolution_name='$app' AND k8snamespace_name='$cluster' AND status!='stock'";
	$deploy = $iTopAPI->coreGet("Deployment", $oql);
	$deploy = json_decode($deploy, true)['objects'];
	if(!$deploy) {
		die("deploy $cluster.$app not found in CMDB, can not auto update");
	}
	$deploy_id = reset($deploy)['key'];
	$shell = dirname(__FILE__) . "/../actions/tasks/kubernetes.php";
	exec("BATCH=1 AUTOUPDATE=1 PULLPOLICY=Always ID=$deploy_id php $shell", $out, $res);
	if($res!=0) {
		die(json_encode($out));
	}
	die("already send auto update task for $cluster.$app");
} elseif (isset($_GET['label'])) {
	die(labels());
} elseif (isset($_GET['dash'])) {
	die(dashboard($_GET['dash']));
}else {
	die("args error: cluster and app required");	
}

