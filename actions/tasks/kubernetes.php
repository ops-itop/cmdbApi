#!/usr/bin/php
<?
/**
 * Usage: 操作Kubernetes部署
 * File Name: kubernetes.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2018-04-19 15:30:30
 **/

require dirname(__FILE__).'/../etc/config.php';
use Maclof\Kubernetes\Client;
/*
use Maclof\Kubernetes\Models\Deployment;
use Maclof\Kubernetes\Models\Ingress;
use Maclof\Kubernetes\Models\Secret;
 */

$ID = getenv("ID");
$script = explode("/", $argv[0]);
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end($script) . ".log";

$k8sClient = new Client([
	'master'      => $config['kubernetes']['master'],
	'ca_cert'     => $config['kubernetes']['ca_cert'],
	'client_cert' => $config['kubernetes']['client_cert'],
	'client_key'  => $config['kubernetes']['client_key'],
]);

var_dump($k8sClient);die();

function Deployment($data) {
	$deployment = new Deployment([
		'metadata' => [
			'name' => '',
			'labels' => '',
		],
	]);
}

function CreateEvent() {
	$oql = "SELECT EventNotificationShellExec";
	$fields = array(
		"message" => "Succ",
		"date" => "2018-04-19 17:44:03",
		"userinfo" => "kubernetes",
		"trigger_id" => "100",
		"action_id" => "100",
		"object_id" => $ID,
		"log" => "kubernetes"
	);
	$data = $iTopAPI->coreCreate("EventNotificationShellExec", $fields);
}

$obj = json_decode($data, true)['objects'];
file_put_contents($log, $config['datetime'] . " - $ID - $ret\n", FILE_APPEND);
?>
