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
use Maclof\Kubernetes\Models\Service;
use Maclof\Kubernetes\Models\Ingress;
use Maclof\Kubernetes\Models\Secret;

$ID = getenv("ID");
$DEBUG = getenv("DEBUG");
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
		$env = [];
		if(!$this->data['secret_id']) { return $env; }
		$secret = new iTopSecret();
		$secret->__init_by_id($this->data['secret_id']);
		$data = $secret->Secret($this->data['k8snamespace_name']);
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
			$port_list['deployment'][] = ['containerPort' => (int) $v];	
			$port_list['service'][] = ['port' => (int) $v, 'targetPort'=>(int) $v];
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
				'replicas' => (int) $this->data['replicas'],
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
					[
						'path' => '/',
						'backend' => [
							'serviceName' => $this->app,
							'servicePort' => $this->_getports('service')[0]['port']
						]
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
						[
							'path' => $v['location'],
							'backend' => [
								'serviceName' => $this->app,
								'servicePort' => (int) $v['serviceport']
							]
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

	function run() {
		global $k8sClient;
		$k8sClient->setNamespace($this->data['k8snamespace_name']);

		$this->Deployment();
		$this->Service();
		$this->Ingress();

		$deployment = new Deployment($this->get('deployment'));
		$service = new Service($this->get('service'));
		$ingress = new Ingress($this->get('ingress'));
		
		$result = [];
		if($k8sClient->deployments()->exists($deployment->getMetadata('name'))) {
			$result[] = $k8sClient->deployments()->update($deployment);
		} else {
			$result[] = $k8sClient->deployments()->create($deployment);
		}

		if($k8sClient->services()->exists($service->getMetadata('name'))) {
			$result[] = $k8sClient->services()->patch($service);
		} else {
			$result[] = $k8sClient->services()->create($service);
		}

		if($k8sClient->ingresses()->exists($ingress->getMetadata('name'))) {
			$result[] = $k8sClient->ingresses()->update($ingress);
		} else {
			$result[] = $k8sClient->ingresses()->create($ingress);
		}

		return json_encode($result);
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
			$secret_data[$key] = base64_encode($value);
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

	function run() {
		global $k8sClient;
		$secrets = $this->Secret();
		$result = [];
		foreach($secrets as $k => $v) {
			$secret = new Secret($v);
			$k8sClient->setNamespace($k);
			if($k8sClient->secrets()->exists($secret->getMetadata('name'))) {
				$result[] = $k8sClient->secrets()->update($secret);
			} else {
				$result[] = $k8sClient->secrets()->create($secret);
			}
		}
		return json_encode($result);
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
$finalclass = $data['finalclass'];

if($finalclass == "Secret") {
	$itopK8s = new iTopSecret();
	$itopK8s->__init_by_data($data);
}
if($finalclass == "Ingress") {
	$data = GetData($data['deployment_id'], 'Deployment');
	$itopK8s = new iTopKubernetes($data);
}
if($finalclass == "Deployment") {
	$itopK8s = new iTopKubernetes($data);
}

try {
	$ret = $itopK8s->run();
} catch(Exception $e) {
	$ret = $e->getMessage();
}

if($DEBUG) { print_r($ret); }
file_put_contents($log, $config['datetime'] . " - $ID - $ret\n", FILE_APPEND);
?>
