#!/usr/bin/php
<?
/**
 * Usage: 操作Kubernetes部署
 * Require: 此程序用到了 guzzle ,要使用cURL，你必须已经有版本cURL >= 7.19.4，并且编译了OpenSSL 与 zlib, 另外，需要安装php7.1及以上版本
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
$BATCH = getenv("BATCH"); // 脚本模式
$PULLPOLICY = getenv("PULLPOLICY");
$AUTOUPDATE = getenv("AUTOUPDATE");
if(!$PULLPOLICY) {
	$PULLPOLICY = "IfNotPresent";
}

if($AUTOUPDATE) {
	$PULLPOLICY = "Always";
}

$script = explode("/", $argv[0]);
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end($script) . ".log";

define("SECRET_PRE", "app-secret-");
define("APPCONFIG_PATH", "/run/secrets/appconfig");

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
	private $secrets;
	private $domain;
	private $errno = 0;
	private $result;
	private $mount;
	private $env;

	function __construct($data) {
		$this->app = $data['applicationsolution_name'];
		$this->data = $data;
		$this->_getdomain();
		$this->result = ['errno' => $this->errno, 'msg' => []];
		$this->mount = ['volumeMounts' => [], 'volumes' => []];
		$this->secrets = [];
		$this->env = [];
	}

	function get($attr) {
		if(!isset($this->$attr)){
			return(NULL);
		}
		return $this->$attr;
	}

	// 挂载宿主机时区
	private function _mounttz() {
		$this->mount['volumeMounts'][] = ['name'=>'tz-config', 'mountPath'=>'/etc/localtime'];
		$this->mount['volumes'][] = ['name'=>'tz-config','hostPath'=>['path'=>'/usr/share/zoneinfo/Asia/Shanghai']];
	}

	private function _mountsecret() {
		foreach($this->secrets as $key => $val) {
			$secretName = $val['metadata']['name'];
			$name = $secretName . "-appconfig";
			$this->mount['volumeMounts'][] = ['name'=>$name, 'mountPath'=>APPCONFIG_PATH, 'readOnly'=>true];
			$this->mount['volumes'][] = ['name'=>$name,'secret'=>['secretName'=>$secretName]];
		}
	}

	// 同时将secret写入环境变量
	private function _secret2env() {
		$secret = new iTopSecret();
		$secret->__init_by_data($this->data);
		$data = $secret->Secret();
		if(!$data) return;
		$secretResult = json_decode($secret->run('Secret'), true);
		$this->result['errno'] = $secretResult['errno'];

		$ns = $this->data['k8snamespace_name'];

		// 只有存在有效数据的secret可以被挂载
		if($data[$ns]['data']) {
			$this->secrets[] = $data[$ns];
		}
		foreach($data[$ns]['data'] as $k => $v) {
			$this->env[] = [
				'name' => $k,
				'valueFrom' =>[
					'secretKeyRef' => [
						'name' => $data[$ns]['metadata']['name'],
						'key' => $k
					]
				]
			];
		}
		// 提供CreateEvent中使用的格式
		if($secretResult['errno'] == 0) {
			$this->result['msg'][] = ["kind"=>"Secret", "metadata"=>["name" => $data[$ns]['metadata']['name']]];
		} else {
			$this->result['msg'][] = $secretResult['msg'][0];
		}
	}

	private function _getimage() {
		$tag = $this->data['image_tag'];
		if($tag == "") {
			$tag = "latest";
		}
		return $this->data['image'] . ":" . $tag;
	}

	private function _getresources() {
		$res = ['requests'=>[],'limits'=>[]];
		$mem = $this->data['mem_request'] . "Mi";
		$res['requests']['cpu'] = $this->data['cpu_request'];
		$res['requests']['memory'] = $mem;
		$res['limits']['cpu'] = $this->data['cpu_limit'];
		$res['limits']['memory'] = $mem;
		return $res;
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
		$envpod = [
			'MY_NODE_NAME' => 'spec.nodeName',
			'MY_POD_NAME' => 'metadata.name',
			'MY_POD_NAMESPACE' => 'metadata.namespace',
			'MY_POD_IP' => 'status.podIP',
		];
		// 设置UPDATEDTIME，否则无修改时重部，pod可能不更新
		$envstr = [
			'APP_CONFIG_PATH' => APPCONFIG_PATH,
			'APP_NAME' => $this->app,
			'APP_DOMAIN' => $this->domain . "/," . $this->_list2str($this->data['ingress_list'], 'friendlyname'),
			'APP_NAMESPACE' => $this->data['k8snamespace_name'],
			'APP_ORG' => $this->data['organization_name'],
			'APP_DESCRIPTION' => $this->data['description'],
			'APP_ONLINEDATE' => $this->data['move2production'],
			'APP_CONTACTS' => $this->_list2str($this->data['person_list'], 'person_name'),
			'UPDATEDTIME' =>  (string)time(),
		];

		foreach($envpod as $k => $v) {
			$this->env[] = [
				'name' => $k,
				'valueFrom' => [
					'fieldRef' => [
						'fieldPath' => $v
					]
				]
			];
		}

		foreach($envstr as $k => $v) {
			$this->env[] = [
				'name' => $k,
				'value' => $v
			];
		}
	}

	function Deployment() {
		global $PULLPOLICY;
		// 挂载宿主机时区
		$this->_mounttz();

		$this->_getenv();
		$this->_secret2env();
		$this->_mountsecret();

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
								'image' => $this->_getimage(),
								'resources' => $this->_getresources(),
								'env' => $this->env,
								'ports' => $this->_getports('deployment'),
								'volumeMounts' => $this->mount['volumeMounts'],
								'imagePullPolicy' => $PULLPOLICY,
							]
						],
						'volumes' => $this->mount['volumes'],
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

	function _checkResult($result) {
		foreach($result as $v) {
			if($v['kind'] == "Status") {
				$this->result['errno'] = 100;
			}
			$this->result['msg'][] = $v;
		}
	}

	function run($finalclass, $del=false) {
		global $k8sClient;
		$k8sClient->setNamespace($this->data['k8snamespace_name']);

		$result = [];
		if($del && $finalclass == "Deployment") {
			$result[] = $k8sClient->deployments()->deleteByName($this->app);
			$result[] = $k8sClient->services()->deleteByName($this->app);
			$result[] = $k8sClient->ingresses()->deleteByName($this->app);
			foreach($this->secrets as $v) {
				$result[] = $k8sClient->secret()->deleteByName($v['metadata']['name']);
			}

			// replicaSet 和 Pod 需要单独删除
			$rs = $k8sClient->replicaSets()->setLabelSelector(['app'=>$this->app])->find();
			$pod = $k8sClient->pods()->setLabelSelector(['app'=>$this->app])->find();
			foreach($rs as $k => $v) {
				$result[] = $k8sClient->replicaSets()->delete($v);
			}
			foreach($pod as $k => $v) {
				$result[] = $k8sClient->pods()->delete($v);
			}

			$this->_checkResult($result);
			return json_encode($this->result);
		}

		$this->Deployment();
		$this->Service();
		$this->Ingress();

		$deployment = new Deployment($this->get('deployment'));
		//print_r($deployment);die();
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

		$this->_checkResult($result);
		return json_encode($this->result);
	}
}

class iTopSecret {
	private $secret_id;
	private $name;
	private $data;      // secret数据
	private $isYaml = false;
	private $ns;
	private $errno = 100;
	private $result;

	function __init_by_data($data) {
		$this->ns = [];
		// 处理Deployment中的Secret

		if($data['finalclass'] == "Deployment") {
			$this->data = $data['secret'];
			$this->name = SECRET_PRE . "private-" . $data['applicationsolution_name'];
			$this->ns[] = $data['k8snamespace_name'];
		} else {
			$this->data = $data['data'];
			$this->name = SECRET_PRE . $data['name'];
			$this->ns = $this->_getNamespace();
		}

		$this->_check();
		$this->result = ['errno' => $this->errno, 'msg' => []];
	}

	function __init_by_id($secret_id) {
		$this->secret_id = $secret_id;
		$data = GetData($secret_id, "Secret");
		$this->__init_by_data($data);
	}

	// 检查是否是yaml格式
	function _check() {
		if(!$this->data) {
			$this->data = [];
			$this->isYaml = true;  // secret为空时执行删除逻辑， 不判断是否是yaml
			return;
		}
		$parsed = yaml_parse($this->data);
		if(is_array($parsed)) {
			$this->data = $parsed;
			$this->isYaml = true;
		} else {
			$this->data = [];
		}
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
		$secret_data = [];
		foreach($this->data as $k => $v) {
			$secret_data[$k] = base64_encode($v);
		}
		$secrets = [];
		foreach($this->ns as $v) {
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
			$this->result['errno'] = 0;   // kind为Secret时没有错误发生
			return ["kind"=>"Secret", "message"=>"update Secret $k." . $r['metadata']['name'] . " successful"];
		}
		return $r;
	}

	function run($finalclass, $del=false) {
		global $k8sClient;
		if(!$this->data) {
			$del = true;
		}

		if(!$this->isYaml) {
			$this->result['msg'][] = ["kind"=>"Status", "message"=>['message'=>"ERR: secret is not valid yaml"]];
			return json_encode($this->result);
		}
		$secrets = $this->Secret();
		foreach($secrets as $k => $v) {
			$secret = new Secret($v);
			$k8sClient->setNamespace($k);
			$exists = $k8sClient->secrets()->exists($secret->getMetadata('name'));
			$r = ['kind'=>"Secret", "metadata"=>["name"=>$this->name]];
			if($del) {
				if($exists) $r = $k8sClient->secrets()->deleteByName($this->name);
			} elseif($exists) {
				$r = $k8sClient->secrets()->update($secret);
			} else {
				$r = $k8sClient->secrets()->create($secret);
			}
			$this->result['msg'][] = $this->deallog($r, $k);
		}
		return json_encode($this->result);
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
	foreach($d['msg'] as $k => $v) {
		if($v['kind'] == "Status") {
			$description[] = $v['message']['message'];
		} else {
			$description[] = "Update " . $v['kind'] . " " . $v['metadata']['name'] . " successful";
		}
	}

	//print_r($description);die();
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

// 更新部署状态到iTop
function UpdateKubestatus($ret, $class, $id) {
	$ret = json_decode($ret, true);
	if($ret['errno'] == 0) {
		$stat = "SUCC";
		$bgcolor = "#59db8f";
	} else {
		$stat = "WARN";
		$bgcolor = "#ff0000";
	}
	$kubestatus = '<p><strong><span style="color:#ffffff"><span style="background-color:' . $bgcolor . '"> ' . $stat . ' </span></span></strong></p>';
	$flag_kubestatus = time();

	global $iTopAPI;
	return $iTopAPI->coreUpdate($class, $id, array('kubestatus'=>$kubestatus, 'flag_kubestatus'=>$flag_kubestatus));
}

$data = GetData($ID);

// 是否执行删除
$del = false;
if($data['status'] == 'stock') {
	$del = true;
}

// 检查flag_kubestatus标识，如果大于0，说明是本脚本更新过的，直接退出，防止无限循环
// 如果是脚本模式，忽略 flag_kubestatus 标识
if($data['flag_kubestatus'] > 0 && !$BATCH) {
	file_put_contents($log, $config['datetime'] . " - $ID - flag_kubestatus set, exit;\n", FILE_APPEND);
	die();
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

// $ret 格式 :
// ['errno'=>0, 'msg'=>[]]
try {
	$ret = $itopK8s->run($finalclass, $del);
} catch(Exception $e) {
	$message = json_decode($e->getMessage());
	if(!$message) $message = $e->getMessage();
	$ret = ['errno'=>100,'msg'=>[['kind'=>'Status', 'message'=>$message]]];
	$ret = json_encode($ret);
}

$itopEvent = CreateEvent($ret);
$updateStatus = UpdateKubestatus($ret, $finalclass, $ID);
if($DEBUG) { print_r($ret); }

file_put_contents($log, $config['datetime'] . " - $ID - $ret\n", FILE_APPEND);
file_put_contents($log, $config['datetime'] . " - $ID - $itopEvent\n", FILE_APPEND);
file_put_contents($log, $config['datetime'] . " - $ID - $updateStatus\n", FILE_APPEND);
?>
