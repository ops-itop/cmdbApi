#!/usr/bin/php
<?php
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
use Maclof\Kubernetes\Models\HorizontalPodAutoscaler;

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
$hostPathPre = $config['kubernetes']['hostpathpre'];

define("SECRET_PRE", "app-secret-");
define("APPCONFIG_PATH", "/run/secrets/appconfig");

$k8sClient = new Client([
	'master'      => $config['kubernetes']['master'],
	'ca_cert'     => $config['kubernetes']['ca_cert'],
	'client_cert' => $config['kubernetes']['client_cert'],
	'client_key'  => $config['kubernetes']['client_key'],
]);

abstract class iTopK8S {
	protected $app;
	protected $ns;
	protected $result;

	function __construct() {}

	function _checkResult($result) {
		foreach($result as $v) {
			if($v['kind'] == "Status") {
				$this->result['errno'] = 100;
			}
			$this->result['msg'][] = $v;
		}
	}

	function get($attr) {
		if(!isset($this->$attr)){
			return(NULL);
		}
		return $this->$attr;
	}
}

class iTopKubernetes extends itopK8s {
	private $data;
	protected $deployment;
	protected $ingress;
	protected $service;
	private $secrets;
	private $domain;
	private $errno = 0;
	private $mount;
	private $env;
	private $hostNetwork = false;
	private $livenessProbe;
	private $readinessProbe;
	private $sessionAffinity = "ClientIP";

	function __construct($data) {
		$this->app = $data['applicationsolution_name'];
		$this->data = $data;
		$this->_getdomain();
		$this->result = ['errno' => $this->errno, 'msg' => []];
		$this->secrets = [];
		$this->env = [];

		// 挂载volumes
		$volumes = new iTopVolume($this->data['volume_list'], $this->app);
		$this->mount = $volumes->run();

		// hostNetwork
		if($this->data['hostnetwork'] == "true") {
			$this->hostNetwork = true;
		}

		// Probe
		$probe = new iTopProbe($this->data);
		$this->livenessProbe = $probe->livenessProbe();
		$this->readinessProbe = $probe->readinessProbe();
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

	private function _gethostaliases() {
		$hostaliases = [];
		if(!$this->data['hostaliases']) return $hostaliases;
		$hosts = yaml_parse($this->data['hostaliases']);
		if(is_array($hosts)) {
			foreach($hosts as $ip => $domain) {
				$hostaliases[]=["ip" => $ip, "hostnames"=>["$domain"]];
			}
		}
		return $hostaliases;
	}

	private function _getrollingstrategy() {
		$strategy = [];
		$rolling_strategy = $this->data['rolling_strategy'];
		if($rolling_strategy) {
			$s = explode(":", $rolling_strategy);
			$ns = [];
			foreach($s as $val) {
				if(!preg_match('/.*%$/',$val)) {
					$ns[] = (int)$val;
				} else {
					$ns[] = $val;
				}
			}
			$strategy = ["type" => "RollingUpdate",
				"rollingUpdate"=>["maxUnavailable"=>$ns[0], "maxSurge"=>$ns[1]]
			];
		}
		return $strategy;
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

		$envresource = [
			'MY_CPU_REQUEST' => 'requests.cpu',
			'MY_CPU_LIMIT' => 'limits.cpu',
			'MY_MEM_REQUEST' => 'requests.memory',
			'MY_MEM_LIMIT' => 'limits.memory'
		];

		// 设置UPDATEDTIME，否则无修改时重部，pod可能不更新
		$envstr = [
			'APP_CONFIG_PATH' => APPCONFIG_PATH,
			'APP_NAME' => $this->app,
			'APP_DOMAIN' => $this->domain . "/," . $this->_list2str($this->data['ingress_list'], 'friendlyname'),
			'APP_NAMESPACE' => $this->data['k8snamespace_name'],
			'APP_ORG' => $this->data['organization_name'],
			'APP_DESCRIPTION' => str_replace(array("\r", "\n", "\r\n"), " ", $this->data['description']),
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

		foreach($envresource as $k => $v) {
			$this->env[] = [
				'name' => $k,
				'valueFrom' => [
					'resourceFieldRef' => [
						'containerName' => $this->app,
						'resource' => $v
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

	private function _defaultasecuritycontext() {
		$securitycontext = [];
		$securitycontext["sysctls"] = [];
		$securitycontext["sysctls"][] = [
			"name" => "net.ipv4.ip_local_port_range",
			"value" => "2000    65000"
		];
		return $securitycontext;
	}
	private function _defaultaffinity() {
		$CPU = [];
		// cpu_request超过设置阈值时，优先选择高配机器，高配机器核数通过配置文件定义
		if($this->data['cpu_request'] >= _getconfig("kubernetes_pm_threshold", 3)) {
			$CPU = _getconfig("kubernetes_pm_core", ["24"]);
		}

		// cpu_request低于设置阈值时，优先选择低配（虚拟机）机器，低配机器核数通过配置文件定义
		// 小流量业务使用虚拟机相比物理机可以在相同成本下大幅增加可分配CPU的数量，充分利用kvm超售节省成本
		if($this->data['cpu_request'] <= _getconfig("kubernetes_vm_threshold", 0.5)) {
			$CPU = _getconfig("kubernetes_vm_core", ["8"]);
		}

		if($CPU) {
			$exp = [
				"key" => "cpu",
				"operator" => "In",
				"values" => $CPU
			];
		} else {
			$exp = "";
		}

		// 优先使用node和router，尽量不往master上部署
		$matchExpressions = [];
		$matchExpressions[] = ["key"=>"role","operator"=>"In","values"=>["node", "router"]];

		if($exp) {
			$matchExpressions[] = $exp;
		}
		$aff = ["nodeAffinity"=>["preferredDuringSchedulingIgnoredDuringExecution"=>[]]];
		$aff["nodeAffinity"]["preferredDuringSchedulingIgnoredDuringExecution"][] = [
			"weight" => 1,
			"preference" => [
				"matchExpressions" => $matchExpressions
			]
		];
		return $aff;
	}

	private function _getaffinity() {
		$affinity = new iTopAffinity($this->data['affinity_list']);
		$data = $affinity->run();
		if(count($data) == 0) {
			$data = $this->_defaultaffinity();
		}
		//print_r($data);die();
		return $data;
	}

	function Deployment() {
		global $PULLPOLICY;
		if($this->data['image_tag'] == "latest" or $this->data['image_tag'] == "") {
			$PULLPOLICY = "Always";
		}
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
						'hostNetwork' => $this->hostNetwork,
						'affinity' => $this->_getaffinity(),
						'containers' => [
							[
								'name' => $this->app,
								'image' => $this->_getimage(),
								'resources' => $this->_getresources(),
								'env' => $this->env,
								'ports' => $this->_getports('deployment'),
								'volumeMounts' => $this->mount['volumeMounts'],
								'imagePullPolicy' => $PULLPOLICY,
								'readinessProbe' => $this->readinessProbe,
								'livenessProbe' => $this->livenessProbe,
							]
						],
						'volumes' => $this->mount['volumes'],
						'securityContext' => $this->_defaultasecuritycontext(),
					],
				]
			]
		];

		$hostaliases = $this->_gethostaliases();
		if($hostaliases) {
			$this->deployment['spec']['template']['spec']['hostAliases'] = $hostaliases;
		}

		$strategy = $this->_getrollingstrategy();
		if($strategy) {
			$this->deployment['spec']['strategy'] = $strategy;
		}
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
				],
				"sessionAffinity" => $this->sessionAffinity
			]
		];
	}

	function Ingress() {
		$rules = [];
		$tls = [];
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

		$alldomain = [];
		$alldomain[] = $this->domain;

		// 处理自定义的Ingress
		foreach($this->data['ingress_list'] as $k => $v) {
			$alldomain[] = $v['domain_name'];
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

		// secretName 为 default-tls，需事先通过kubectl创建
		if($this->data['https'] == "on") {
			$tls[] = ['hosts' =>$alldomain, 'secretName' => 'default-tls'];
		}

		$customNginx = new iTopIngressAnnotations($this->data['ingressannotations_list']);
		$annotations = $customNginx->run();
		$annotations['kubernetes.io/ingress.class'] = $this->data['k8snamespace_name'];
		$this->ingress = [
			'metadata' => [
				'name' => $this->app,
				'namespace' => $this->data['k8snamespace_name'],
				'annotations' => $annotations
			],
			'spec' => [
				'rules' => $rules
			]
		];

		if($tls) {
			$this->ingress['spec']['tls'] = $tls;
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

		// 设置默认HPA
		if(!array_key_exists("hpa_list", $this->data) || !$this->data['hpa_list']) {
			$hpa = new iTopHPA($this->data);
			$rethpa = json_decode($hpa->run(), true);
			$result[] = $rethpa['msg'][0];
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

class iTopAffinity {
	private $data;
	private $affinity;

	function __construct($data) {
		$this->data = $data;
		$this->affinity = [];
	}

	private function _checkKey($key) {
		if(!array_key_exists($key, $this->affinity)) {
			$this->affinity[$key] = [];
		}
	}

	private function _checkKey2($key, $key2) {
		if(!array_key_exists($key, $this->affinity[$key2])) {
			$this->affinity[$key2][$key] = [];
		}
	}

	private function _getExp($exp, $values) {
		if(!$exp) {
			return [];
		}
		$val = explode(",",$values);

		foreach($val as $k => $v) {
			$val[$k] = "- " . $v;
		}
		$val = implode("\n", $val);

		$exp = str_replace("__VALUES__", "\n$val", $exp);
		$parsed = yaml_parse($exp);
		if(is_array($parsed)) {
			return $parsed;
		} else {
			return [];
		}
	}

	function _getnodeaffinity($val) {
		$this->_checkKey("nodeAffinity");
		$tp = "required";
		if($val['k8saffinity_requiretype'] == "required") {
			$key = "requiredDuringSchedulingIgnoredDuringExecution";
		} else {
			$key = "preferredDuringSchedulingIgnoredDuringExecution";
			$tp = "preferred";
		}

		$this->_checkKey2($key, "nodeAffinity");
		if($tp == "required") {
			$this->affinity["nodeAffinity"][$key]["nodeSelectorTerms"][$val['group']] = ["matchExpressions" => []];
			$this->affinity["nodeAffinity"][$key]["nodeSelectorTerms"][$val['group']]["matchExpressions"][] = $this->_getExp($val['k8saffinity_expressions'], $val['values']);
		}
	}

	function _getpodaffinity($val) {
		$this->_checkKey("podAffinity");
	}

	function _getpodantiaffinity($val) {
		$this->_checkKey("podAntiAffinity");
	}

	function _delarraykey() {
		if(array_key_exists("nodeAffinity", $this->affinity)) {
			if(array_key_exists("requiredDuringSchedulingIgnoredDuringExecution", $this->affinity['nodeAffinity'])) {
				$this->affinity['nodeAffinity']['requiredDuringSchedulingIgnoredDuringExecution']['nodeSelectorTerms'] = array_values($this->affinity['nodeAffinity']['requiredDuringSchedulingIgnoredDuringExecution']['nodeSelectorTerms']);
			}
		}
	}

	function run() {
		foreach($this->data as $val) {
			$affinitytype = $val['k8saffinity_affinitytype'];
			if(in_array($affinitytype, ["nodeaffinity", "nodeantiaffinity"])) {
				$this->_getnodeaffinity($val);
			} elseif($affinitytype == "podaffinity") {
				$this->_getpodaffinity($val);
			} else {
				$this->_getpodantiaffinity($val);
			}
		}
		$this->_delarraykey();
		return $this->affinity;
	}
}

class iTopVolume {
	private $data;
	private $app;
	private $volumes;

	function __construct($data, $app) {
		$this->data = $data;
		$this->app = $app;
		$this->volumes = ['volumeMounts' => [], 'volumes' => []];
	}

	function _gethostpath($key, $val) {
		global $hostPathPre;
		$name = "hostpath-" . $key . "-" . $this->app;
		$path = rtrim($hostPathPre, "/") . "/" . $name;
		$this->volumes['volumeMounts'][] = ['name'=>$name, 'mountPath'=>$val['mountpath']];
		$this->volumes['volumes'][] = ['name'=>$name, 'hostPath'=>['path'=>$path]];
	}

	function run() {
		foreach($this->data as $key => $val) {
			$volumetype = $val['k8svolume_type'];
			if($volumetype == "hostpath") {
				$this->_gethostpath($key, $val);
			}
		}
		return $this->volumes;
	}
}

class iTopProbe {
	private $data;
	private $port;

	function __construct($data) {
		$this->data = $data;
		$ports = explode(",", $data['containerport']);
		$this->port = (int)$ports[0];
	}

	function readinessProbe() {
		if(array_key_exists("probe_list", $this->data)) {
			foreach($this->data['probe_list'] as $val) {
				if($val['probe_type'] == "readinessProbe") {

				}
			}
		}
		return ["tcpSocket"=>["port" => $this->port], "initialDelaySeconds"=>10,"periodSeconds" => 5];
	}

	function livenessProbe() {
		if(array_key_exists("probe_list", $this->data)) {
			foreach($this->data['probe_list'] as $val) {
				if($val['probe_type'] == "readinessProbe") {

				}
			}
		}
		return ["tcpSocket"=>["port" => $this->port], "initialDelaySeconds"=>15,"periodSeconds" => 3];
	}
}

class iTopIngressAnnotations {
	private $data;
	private $annotations;

	function __construct($data) {
		$this->data = $data;
	}

	function run() {
		foreach($this->data as $val) {
			$this->annotations[$val['k8singressannotations_name']] = $val['value'];
		}
		return $this->annotations;
	}
}

class iTopHPA extends itopK8s {
	private $data;
	private $hpa;
	private $min;
	private $max;
	private $metrics;

	function __construct($data) {
		$this->data = $data;
		$this->metrics = [];
		if($this->data['finalclass'] == "Deployment") {
			$this->defaultHpa();
		} else {
			$this->customHpa();
		}
		$this->hpa = [
			"metadata" => [
				"name" => $this->app,
				"namespace" => $this->ns
			], 
			"spec" => [
				"scaleTargetRef" => ["apiVersion"=>"apps/v1", "kind"=>"Deployment", "name" => $this->app],
				"minReplicas" => $this->min,
				"maxReplicas" => $this->max,
				"metrics" => $this->metrics
			]
		];
	}

	function defaultHpa() {
		$this->ns = $this->data['k8snamespace_name'];
		$this->app = $this->data['applicationsolution_name'];
		$this->min = ceil($this->data['replicas'] * _getconfig("kubernetes_hpa_default_min", 0.3));
		$this->max = ceil($this->data['replicas'] * _getconfig("kubernetes_hpa_default_max", 3));

		$this->addResouceMetrics(_getconfig("kubernetes_hpa_targetcpuutilizationpercentage", 60));
		$this->addResouceMetrics(_getconfig("kubernetes_hpa_targetmemoryutilizationpercentage", 60), "memory");
	}

	function customHpa() {
		$this->ns = $this->data['k8snamespace_name'];
		$this->app = $this->data['applicationsolution_name'];
		$this->min = $this->data['minreplicas'];
		$this->max = $this->data['maxreplicas'];
		$metrics = $this->data['metrics'];
		if($metrics) {
			$metrics = yaml_parse($metrics);
		}
		foreach($metrics as $key => $val) {
			switch($key) {
				case "cpu": $this->addResouceMetrics((int)$val); break;
				case "memory": $this->addResouceMetrics((int)$val, "memory"); break;
				default : break;
			}
		}
	}

	function addResouceMetrics($val,$rtype="cpu") {
		$this->metrics[] = [
			"type" => "Resource",
			"resource" => [
				"name" => $rtype,
				"targetAverageUtilization" => $val
			]
		];
	}

	function run() {
		global $k8sClient;
		$k8sClient->setNamespace($this->data['k8snamespace_name']);

		$hpa = new HorizontalPodAutoscaler($this->hpa);

		$result = [];
		if($k8sClient->horizontalPodAutoscalers()->exists($hpa->getMetadata('name'))) {
			$result[] = $k8sClient->horizontalPodAutoscalers()->update($hpa);
		} else {
			$result[] = $k8sClient->horizontalPodAutoscalers()->create($hpa);
		}
		$this->_checkResult($result);
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
	$flag_kubestatus = "AUTOUPDATE";

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
// 如果是下线操作($del=true)，忽略 flag_kubestatus 标识
// 因为开启opcache的原因，需要多试几次才能获取正确的值
if($data['flag_kubestatus'] != "MANUAL" && !$BATCH && !$del) {
	for($i=0;$i<3;$i++) {
		$data = GetData($ID);
		if($data['flag_kubestatus'] == "MANUAL") {
			break;
		}
		sleep(1);
	}
	if($data['flag_kubestatus'] != "MANUAL") {
		file_put_contents($log, $config['datetime'] . " - $ID - flag_kubestatus set, exit;\n", FILE_APPEND);
		die();
	}
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

// 下线操作不写kubestatus
// 因为下线操作不能更新flag_kubestatus，当del=true时，不检查flag_kubestatus
// 但这样又会造成循环更新，因此如果是del，就不更新iTop了，防止无限循环
if($del) {
	$updateStatus = "action stock";
} else {
	$updateStatus = UpdateKubestatus($ret, $finalclass, $ID);
}

if($DEBUG) { print_r($ret); }

file_put_contents($log, $config['datetime'] . " - $ID - $ret\n", FILE_APPEND);
file_put_contents($log, $config['datetime'] . " - $ID - $itopEvent\n", FILE_APPEND);
file_put_contents($log, $config['datetime'] . " - $ID - $updateStatus\n", FILE_APPEND);
?>
