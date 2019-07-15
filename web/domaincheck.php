<?php
/**
 * From 云计算: 泛解析可能会出问题 因为我们sdns管理系统有一个bug导致 有时候泛解析会出现解析不了的情况
 * 我是不想做这个的，可是又有什么办法呢 ㄟ( ▔, ▔ )ㄏ
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2019-04-17 12:52:34
 **/
require 'common/init.php';

function dnsAtoString($resolv_a) {
	$a = "";
	if($resolv_a) {
		$arr_a = [];
		foreach($resolv_a as $val) {
			$arr_a[] = $val['ip'];
		}
		sort($arr_a);
		$a = implode(",", $arr_a);
	}
	return $a;
}

function getCnameMap() {
	global $iTopAPI;
	$Oql = "SELECT K8sNamespace";
	$data = $iTopAPI->coreGet('K8sNamespace', $Oql, 'name,cname');
	$data = json_decode($data, true)['objects'];
	$map = [];
	foreach($data as $val) {
		$cname = $val['fields']['cname'];
		$a = "";
		if($cname) {
			$resolv_a = dns_get_record($cname, DNS_A);
			$a = dnsAtoString($resolv_a);
		}
		$map[$val['fields']['name']] = ['cname' => $val['fields']['cname'], 'a' => $a];
	}
	return $map;
}

function checkResolv($domain, $ns) {
	$map = getCnameMap();
	$cname = "";
	$resolv_cname = dns_get_record($domain, DNS_CNAME);
	$resolv_a = dns_get_record($domain, DNS_A);

	if($resolv_cname) {
		$cname = $resolv_cname[0]['target'];
	}
	
	$a = dnsAtoString($resolv_a);

	// 检查域名是否cname到集群cname域名，并且检查A记录是否和cname域名的A记录一致，如果一致但是没有cname，说明是走的泛解析，可以直接加
	// cname记录。否则域名没有在k8s，不能做解析操作
	$result = ['ns' => $ns, 'domain' => $domain, 'cname' => $cname, 'a' => $a, 'expact' => $map[$ns]['cname'], 'expactA' => $map[$ns]['a'], 'match' => 'false', 'safe' => 'false'];

	if($cname == $map[$ns]['cname']) {
		$result['match'] = "true";
	}
	if($a == $map[$ns]['a']) {
		$result['safe'] = "true";
	}
	return $result;
}

function getDomains($lbtype = "production") {
	global $iTopAPI;
	$OqlDeploy = "SELECT Deployment AS d JOIN K8sNamespace AS ns ON d.k8snamespace_id=ns.id WHERE ns.lbtype='$lbtype' AND d.url!='' AND d.status='production'";
	$OqlIngress = "SELECT Ingress AS i JOIN K8sNamespace AS ns ON i.k8snamespace_id=ns.id WHERE ns.lbtype='$lbtype' AND i.status='production' AND i.domaincheck='yes'";
	$deploy_domain = $iTopAPI->coreGet("Deployment", $OqlDeploy, "k8snamespace_name, url");
	$ingress_domain = $iTopAPI->coreGet("Ingress", $OqlIngress, "k8snamespace_name, domain_name");

	$deploy_domain = json_decode($deploy_domain, true)['objects'];
	$ingress_domain = json_decode($ingress_domain, true)['objects'];

	$domains = [];
	if($deploy_domain) {
		foreach($deploy_domain as $val) {
			$url = explode("/", $val['fields']['url']);
			$domain = end($url);
			$item = ['ns' => $val['fields']['k8snamespace_name'], 'domain' => $domain];
			if($domain) {
				$item = ['ns' => $val['fields']['k8snamespace_name'], 'domain' => $domain];
				if(!in_array($item, $domains)) {
					$domains[] = $item;
				}
			}
		}
	}

	if($ingress_domain) {
		foreach($ingress_domain as $val) {
			$item = ['ns' => $val['fields']['k8snamespace_name'], 'domain' => $val['fields']['domain_name']];
			if(!in_array($item, $domains)) {
				$domains[] = $item;
			}
		}
	}
	return $domains;
}

function doCheck($lbtype = "production") {
	$domains = getDomains($lbtype);
	$result = ['all' => [], 'manual' => [], 'manualUnsafe' => []];
	foreach($domains as $val) {
		$r = checkResolv($val['domain'], $val['ns']);
		$result['all'][] = $r;
		if($r['match'] == 'false' && $r['safe'] == 'true') {
			$result['manual'][] = $r;
		}
		if($r['match'] == 'false' && $r['safe'] == 'false') {
			$result['manualUnsafe'][] = $r;
		}
	}
	return $result;
}

function arrtocsv($arr) {
	$items = [];
	foreach($arr as $val) {
		$items[] = implode(",", $val);
	}
	$csv = implode("<br>", $items);
	return $csv;
}

function arrtojson($arr) {
	return json_encode(["count" => count($arr), "data" => $arr]);
}

// 方便直接提工单的形式
function jira($safe = true) {
	global $result;
	$items = [];

	if ($safe) {
		$manual = $result['manual'];
	} else {
		$manual = $result['manualUnsafe'];
	}
	foreach($manual as $val) {
		$items[] = $val['domain'] . " CNAME " . $val['expact'];
	}
	if(!$items) {
		$jira = "All is well";
	} else {
		$jira = implode("<br>", $items);
	}
	return $jira;
}

$help = array(
	"errno" => "1",
	"errmsg" => "param error",
	"usage" => "云计算sdns管理系统bug导致泛解析可能会出现解析不了的情况，此接口用于检查泛解析域名",
	"params" => array(
		"show" => array(
			"manual" => "显示需要手工处理的域名",
			"all" => "显示所有域名"
		),
		"format" => array(
			"json" => "输出json格式",
			"jira" => "输出csv格式用于提jira工单",
			"jiraunsafe" => "输出csv格式用于jira工单，unsafe表示不严格的检查"
		),
		"lbtype" => array(
			"develop" => "开发集群",
			"test" => "测试集群",
			"production" => "生成集群"
		)
	)
);

if(!$_GET) {
	header('Content-Type:application/json');
	die(json_encode($help));
}

if(isset($_GET['lbtype'])) {
	$result = doCheck($_GET['lbtype']);
} else {
	$result = doCheck();
}

$show = $result['all'];
if(isset($_GET['show'])) {
	if($_GET['show'] == 'manual') {
		$show = $result['manual'];
	}
}

if(isset($_GET['format'])) { 
	if($_GET['format'] == "json") {
		header('Content-Type:application/json');
		die(arrtojson($show));
	}
	if($_GET['format'] == "jira") die(jira());
	if($_GET['format'] == "jiraunsafe") die(jira(false));
	die(arrtocsv($show));
} else {
	die(arrtocsv($show));
}
