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
use Maclof\Kubernetes\Models\Deployment;
/*
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

class iTopKubernetes {
	private $app;
	private $data;
	private $deployment;
	private $ingress;
	private $service;
	private $domain;

	function __construct($data) {
		$this->app = $data['applicationsolution_name'];
		$this->data = $data;
		$this->_getdomain();
	}

	function get($attr) {
		if(!isset($this->$attr)){
			return(NULL);
		}
		return $this->$attr;
	}

	private function _getenv() {
		$secret = new iTopSecret();
		$secret->__init_by_id($this->data['secret_id']);
		$data = $secret->Secret($this->data['k8snamespace_name']);
		$env = [];
		foreach($data['data'] as $k => $v) {
			$env[] = [
				'name' => $k,
				'valueFrom' =>[
					'secretKeyRef' => [
						'name' => $data['metadata']['name'],
						'key' => $k
					]
				]
			];
		}
		return $env;
	}

	private function _getports($objtype) {
		$ports = explode(",", $this->data['containerport']);
		$port_list = ['deployment'=>[], 'service'=>[]];
		foreach($ports as $k => $v) {
			$port_list['deployment'][] = ['containerPort' => $v];	
			$port_list['service'][] = ['port' => $v, 'targetPort'=>$v];	
		}
		return $port_list[$objtype];
	}

	private function _getdomain() {
		$domain = explode("/", $this->data['url']);
		$this->domain = end($domain);
	}

	function Deployment() {
		$this->deployment = [
			'metadata' => [
				'name' => $this->app,
				'labels' => [
					'app' => $this->app
				],
				'namespace' => $this->data['k8snamespace_name'],
			],
			'spec' => [
				'replicas' => $this->data['replicas'],
				'selector' => [
					'matchLabels' => [
						'app' => $this->app
					]
				],
				'template' => [
					'metadata' => [
						'labels' => [
							'app' => $this->app
						],
					],
					'spec' => [
						'containers' => [
							[
								'name' => $this->app,
								'image' => $this->data['image'],
								'env' => $this->_getenv($this->data['secret_id']),
								'ports' => $this->_getports('deployment'),
							]
						],
					],
				]
			]
		];	
	}

	function Service() {
		$this->service = [
			'metadata' => [
				'name' => $this->app,
				'namespace' => $this->data['k8snamespace_name'],
				'labels' => [
					'app' => $this->app
				]
			],
			'spec' => [
				'ports' => $this->_getports('service'),
				'selector' => [
					'app' => $this->app
				]
			]
		];
	}
	
	function Ingress() {
		$rules = [];
		$rules[] = [
			'host' => $this->domain,
			'http' => [
				'paths' => [
					'path' => '/',
					'backend' => [
						'serviceName' => $this->app,
						'servicePort' => $this->_getports('service')[0]['port']
					]
				]
			]
		];

		// 处理自定义的Ingress
		foreach($this->data['ingress_list'] as $k => $v) {
			$rules[] = [
				'host' => $v['domain_name'],
				'http' => [
					'paths' => [
						'path' => $v['location'],
						'backend' => [
							'serviceName' => $this->app,
							'servicePort' => $v['serviceport']
						]
					]
				]
			];
		}

		$this->ingress = [
			'metadata' => [
				'name' => $this->app,
				'namespace' => $this->data['k8snamespace_name'],
				'annotations' => [
					'kubernetes.io/ingress.class' => $this->data['k8snamespace_name']
				]
			],
			'spec' => [
				'rules' => $rules
			]
		];
	}
}

class iTopSecret {
	private $secret_id;
	private $data;

	function __init_by_data($data) {
		$this->data = $data;
	}

	function __init_by_id($secret_id) {
		$this->secret_id = $secret_id;
		$this->data = GetData($secret_id, "Secret");
	}

	function Secret($namespace="") {
		$secret_data_str = explode("\n", $this->data['data']);
		$secret_data = [];
		foreach($secret_data_str as $k => $v) {
			$item = explode(":", $v);
			$key = $item[0];
			array_shift($item);
			$value = implode(":", $item);
			$secret_data[$key] = $value;
		}
		$secrets = [];
		foreach($this->data['deployment_list'] as $k => $v) {
			$secrets[$v['k8snamespace_name']] = [
				'metadata' => [
					'name' => $this->data['name'],
					'namespace' => $v['k8snamespace_name']
				],
				'type' => 'Opaque',
				'data' => $secret_data
			];
		}
		if($namespace) { return $secrets[$namespace]; }
		return $secrets;
	}
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

function GetData($ID, $sClass="Kubernetes") {
	global $iTopAPI;
	if($sClass == "Kubernetes") {
		$oql = "SELECT Kubernetes WHERE id=$ID";
		$data = $iTopAPI->coreGet("Kubernetes", $oql, "finalclass");
		$KubeObj = json_decode($data, true)['objects'];
		$sClass = reset($KubeObj)['fields']['finalclass'];
	}
	$oql = "SELECT $sClass WHERE id=$ID";
	$data = $iTopAPI->coreGet($sClass, $oql);
	$KubeObj = json_decode($data, true)['objects'];
	$ret = reset($KubeObj)['fields'];
	return $ret;
}

$data = GetData($ID);
$itopK8s = new iTopKubernetes($data);
$itopK8s->Deployment();
$itopK8s->Service();
$itopK8s->Ingress();
print_r($itopK8s->get('ingress'));die();

file_put_contents($log, $config['datetime'] . " - $ID - $ret\n", FILE_APPEND);
?>
