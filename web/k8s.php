<?php
/**
 * Usage: 用于快速重部开发环境
 * File Name: k8s.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2018-04-28 18:35:08
 **/

require 'common/init.php';

$dev = $config['k8s']['dev'];

if(isset($_GET['app']) && isset($_GET['cluster']) && $_GET['cluster'] != '' && $_GET['app'] !='') {
	$cluster = $_GET['cluster'];
	$app = $_GET['app'];
	if(!in_array($cluster, $dev)) {
		die("$cluster auto update not allowd");
	}

	$oql = "SELECT Deployment WHERE applicationsolution_name='$app' AND k8snamespace_name='$cluster' AND status='production'";
	$deploy = $iTopAPI->coreGet("Deployment", $oql);
	$deploy = json_decode($deploy, true)['objects'];
	if(!$deploy) {
		die("deploy $cluster.$app not found in CMDB, can not auto update");
	}
	$deploy_id = reset($deploy)['key'];
	$shell = dirname(__FILE__) . "/../actions/tasks/kubernetes.php";
	exec("PULLPOLICY=Always ID=$deploy_id php $shell", $out, $res);
	if($res!=0) {
		die(json_encode($out));
	}
	die("already send auto update task for $cluster.$app");
} else {
	die("args error: cluster and app required");	
}

