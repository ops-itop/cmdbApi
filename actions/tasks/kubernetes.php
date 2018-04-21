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

define("SECRET_PRE", "app-secret-");

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

	private function _mountsecret() {
		$ret = ['volumeMounts' => [], 'volumes' => []];
		if(!$this->data['secret_id']) { return $ret; }
		
		$name = $this->app . "-appconfig";
		$ret['volumeMounts'][] = ['name'=>$name, 'mountPath'=>'/run/secrets/appconfig', 'readOnly'=>true];
		$ret['volumes'][] = ['name'=>$name,'secret'=>['secretName'=>SECRET_PRE . $this->data['secret_name']]];
		return $ret;

		/*
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
		 */
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

	private function _list2str($l, $key) {
		$s = [];
		foreach($l as $k => $v) {
			$s[] = $v[$key];
		}
		return implode(",", $s);
	}

	private function _getenv() {
		$env = [];
		$envpod = [
			'MY_NODE_NAME' => 'spec.nodeName',
			'MY_POD_NAME' => 'metadata.name',
			'MY_POD_NAMESPACE' => 'metadata.namespace',
			'MY_POD_IP' => 'status.podIP',
		];
		$envstr = [
			'APP_NAME' => $this->app,
			'APP_DOMAIN' => $this->domain . "/," . $this->_list2str($this->data['ingress_list'], 'friendlyname'),
			'APP_NAMESPACE' => $this->data['k8snamespace_name'],
			'APP_ORG' => $this->data['organization_name'],
			'APP_DESCRIPTION' => $this->data['description'],
			'APP_ONLINEDATE' => $this->data['move2production'],
			'APP_CONTACTS' => $this->_list2str($this->data['person_list'], 'person_name'),
		];

		foreach($envpod as $k => $v) {
			$env[] = [
				'name' => $k,
				'valueFrom' => [
					'fieldRef' => [
						'fieldPath' => $v
					]
				]
			];
		}

		foreach($envstr as $k => $v) {
			$env[] = [
				'name' => $k,
				'value' => $v
			];
		}
		return $env;
	}

	function Deployment() {
		$mount = $this->_mountsecret();

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
								'env' => $this->_getenv(),
								'ports' => $this->_getports('deployment'),
								'volumeMounts' => $mount['volumeMounts'],
							]
						],
						'volumes' => $mount['volumes'],
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

	function run($finalclass, $del=false) {
		global $k8sClient;
		$k8sClient->setNamespace($this->data['k8snamespace_name']);
		
		$result = [];
		if($del && $finalclass == "Deployment") {
			$result[] = $k8sClient->deployments()->deleteByName($this->app);
			$result[] = $k8sClient->services()->deleteByName($this->app);
			$result[] = $k8sClient->ingresses()->deleteByName($this->app);

			// replicaSet 和 Pod 需要单独删除
			$rs = $k8sClient->replicaSets()->setLabelSelector(['app'=>$this->app])->find();
			$pod = $k8sClient->pods()->setLabelSelector(['app'=>$this->app])->find();
			foreach($rs as $k => $v) {
				$result[] = $k8sClient->replicaSets()->delete($v);
			}
			foreach($pod as $k => $v) {
				$result[] = $k8sClient->pods()->delete($v);
			}
			return json_encode($result);
		}

		$this->Deployment();
		$this->Service();
		$this->Ingress();

		$deployment = new Deployment($this->get('deployment'));
		$service = new Service($this->get('service'));
		$ingress = new Ingress($this->get('ingress'));
		
		if($k8sClient->ingresses()->exists($ingress->getMetadata('name'))) {
			$result[] = $k8sClient->ingresses()->update($ingress);
		} else {
			$result[] = $k8sClient->ingresses()->create($ingress);
		}

		// 如果只是更新了Ingress，只操作Ingress对象
		if($finalclass == "Ingress") { return json_encode($result); }

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
		
		return json_encode($result);
	}
}

class iTopSecret {
	private $secret_id;
	private $name;
	private $data;

	function __init_by_data($data) {
		$this->data = $data;
		$this->name = SECRET_PRE . $data['name'];
	}

	function __init_by_id($secret_id) {
		$this->secret_id = $secret_id;
		$this->data = GetData($secret_id, "Secret");
	}

	function _getNamespace() {
		global $iTopAPI;
		$oql = "SELECT K8sNamespace";
		$ns_list = [];
		$ns = $iTopAPI->coreGet("K8sNamespace", $oql, "name");
		$ns = json_decode($ns, true)['objects'];
		foreach($ns as $k => $v) {
			$ns_list[] = $v['fields']['name'];
		}
		return $ns_list;
	}

	function Secret() {
		$secret_data_str = explode("\n", preg_replace("/\r\n/m", "\n", $this->data['data']));
		$secret_data = [];
		foreach($secret_data_str as $k => $v) {
			$item = explode(":", $v);
			$key = $item[0];
			array_shift($item);
			$value = implode(":", $item);
			$secret_data[$key] = base64_encode($value);
		}
		$secrets = [];
		$ns_list = $this->_getNamespace();
		foreach($ns_list as $k => $v) {
			$secrets[$v] = [
				'metadata' => [
					'name' => $this->name,
					'namespace' => $v
				],
				'type' => 'Opaque',
				'data' => $secret_data
			];
		}
		return $secrets;
	}

	function deallog($r, $k) {
		if($r['kind'] == "Secret") {
			return ["kind"=>"Secret", "message"=>"update Secret $k." . $r['metadata']['name'] . " successful"];
		}
		return $r;
	}

	function run($finalclass, $del=false) {
		global $k8sClient;
		$secrets = $this->Secret();
		$result = [];
		foreach($secrets as $k => $v) {
			$secret = new Secret($v);
			$k8sClient->setNamespace($k);
			if($del) {
				$r = $k8sClient->secrets()->deleteByName($this->name);
			} elseif($k8sClient->secrets()->exists($secret->getMetadata('name'))) {
				$r = $k8sClient->secrets()->update($secret);
			} else {
				$r = $k8sClient->secrets()->create($secret);
			}
			$result[] = $this->deallog($r, $k);
		}
		return json_encode($result);
	}
}

function CreateEvent($log) {
	global $iTopAPI;

	$triggers = $iTopAPI->coreGet("TriggerOnObject", "SELECT TriggerOnObject WHERE target_class='Kubernetes'");
	$trigger = json_decode($triggers, true)['objects'];
	$trigger = reset($trigger);
	$trigger_id = $trigger['key'];
	$action_id = $trigger['fields']['action_list'][0]['action_id'];

	global $ID;
	$d = json_decode($log, true);
	$description = [];
	foreach($d as $k => $v) {
		if($v['kind'] == "Status") {
			$description[] = $v['message'];
		} else {
			$description[] = "Update " . $v['kind'] . " " . $v['metadata']['name'] . " successful";
		}
	}

	$message = implode(",", $description);

	$fields = array(
		"message" => $message,
		"date" => date("Y-m-d H:i:s"),
		"userinfo" => "kubernetes",
		"trigger_id" => $trigger_id,
		"action_id" => $action_id,
		"object_id" => $ID,
		"log" => $log
	);
	$data = $iTopAPI->coreCreate("EventNotificationShellExec", $fields);
	return $data;
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

// 是否执行删除
$del = false;
if($data['status'] == 'stock') {
	$del = true;
}

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
	$ret = $itopK8s->run($finalclass, $del);
} catch(Exception $e) {
	$ret = [];
	$ret[] = json_decode($e->getMessage());
	$ret = json_encode($ret);
}

$itopEvent = CreateEvent($ret);

if($DEBUG) { print_r($ret); }
file_put_contents($log, $config['datetime'] . " - $ID - $ret\n", FILE_APPEND);
file_put_contents($log, $config['datetime'] . " - $ID - $itopEvent\n", FILE_APPEND);
?>
