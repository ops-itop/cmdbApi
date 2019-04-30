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
use Maclof\Kubernetes\Models\Endpoint;

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
	protected $data;
	protected $app;
	protected $ns;
	protected $result=[];
	protected $exists;
	protected $k8sClient;

	function __construct($data) {
		$this->data = $data;
		// app名称中不应该出现下划线
		$this->app = str_replace("_", "-", $data['applicationsolution_name']);
		$this->ns = $data['k8snamespace_name'];

		global $k8sClient;
		$this->k8sClient = $k8sClient;
		$this->k8sClient->setNamespace($this->ns);
	}

	function DealResult($ret) {
		foreach($ret as $val) {
			$this->result[] = $val;
		}
	}

	function Get($attr) {
		if(!isset($this->$attr)){
			return(NULL);
		}
		return $this->$attr;
	}

	function List2Str($l, $key) {
		$s = [];
		foreach($l as $k => $v) {
			$s[] = $v[$key];
		}
		return implode(",", $s);
	}
}

class iTopService extends iTopK8s {
	protected $sessionAffinity = "None";   //ClientIP并不好用，不能解决ipvs高延迟问题，还会带来新的问题，流量不均，影响更坏
	protected $service;
	protected $serviceName;
	protected $ports = [];
	private $selector = true;

	function __construct($data) {
		parent::__construct($data);

		// Ingress管理的Service做特殊处理
		if($this->data['finalclass'] == 'Ingress') {
			$ports = $this->data['serviceport'];
			// 考虑到同一个app可能添加了多个外部负载，用ingress name加后缀作为外部服务的Service名称
			// DNS-1035 label must consist of lower case alphanumeric characters or '-', start with an alphabetic character, and end with an alphanumeric character
			$this->serviceName = str_replace(".", "-", $data['name']);
			$this->selector = false;
		} else {
			$ports = $this->data['containerport'];
			$this->serviceName = $this->app;
		}
		$ports = explode(",", $ports);
		foreach($ports as $k => $v) {
			$this->ports[] = ['port' => (int) $v, 'targetPort'=>(int) $v];
		}
	}

	private function UpdateEndpoints($ips) {
		// 没有填写endpoint时直接退出
		if(!$ips) {
			return '';
		}

		$ips = str_replace("\r\n", "\n", $ips);
		$ip_list = explode("\n", $ips);
		$addresses = [];
		foreach($ip_list as $ip) {
			$addresses[] = ['ip' => $ip];
		}

		$ports = [];
		foreach($this->ports as $v) {
			$ports[] = ['port' => $v['targetPort'], 'protocol' => 'TCP'];
		}

		$endpoints = [
			'metadata' => [
				'namespace' => $this->ns,
				'name' => $this->serviceName
			],
			'subsets' => [
				[
					'addresses' => $addresses,
					'ports' => $ports
				]
			]
		];

		$ep = new Endpoint($endpoints);
		$exists = $this->k8sClient->endpoints()->exists($ep->getMetadata('name'));
		if($exists) {
			$this->result[] = $this->k8sClient->endpoints()->update($ep);
		} else {
			$this->result[] = $this->k8sClient->endpoints()->create($ep);
		}
	}

	function Service($selector=true, $del = false) {
		$this->service = [
			'metadata' => [
				'name' => $this->serviceName,
				'namespace' => $this->ns,
				'labels' => [
					'app' => $this->app
				]
			],
			'spec' => [
				'ports' => $this->ports,
				'selector' => [
					'app' => $this->app
				],
				"sessionAffinity" => $this->sessionAffinity
			]
		];

		// 通过Ingress创建的Service没有selector，并且使用后缀
		if(!$selector && !$del) {
			unset($this->service['spec']['selector']);
			$this->UpdateEndpoints($this->data['endpoints']);
		}
	}

	function Run($del = false) {
		$this->Service($this->selector, $del);
		$service = new Service($this->Get('service'));
		$this->exists = $this->k8sClient->services()->exists($service->getMetadata('name'));

		if($del) {
			if($this->exists) $this->result[] = $this->k8sClient->services()->deleteByName($service->getMetadata('name'));
		} elseif($this->exists) {
			$this->k8sClient->setPatchType("merge");  // 更改service端口时patch type需要为merge
			$this->result[] = $this->k8sClient->services()->patch($service);
		} else {
			$this->result[] = $this->k8sClient->services()->create($service);
		}

		return ($this->result);
	}
}

class iTopController extends iTopK8s {
	protected $affinity;
	protected $image;
	protected $resources;
	protected $env = [];
	protected $ports;
	protected $volumes;
	protected $sidecars = [];
	protected $imagePullPolicy;
	protected $hostNetwork = false;
	protected $livenessProbe;
	protected $readinessProbe;
	protected $hostAliases;
	protected $strategy;
	protected $securityContext;
	protected $lifecycle;
	protected $terminationGracePeriodSeconds;
	protected $secrets = [];
	protected $privateSecret;
	protected $command;
	protected $args;

	function __construct($data) {
		parent::__construct($data);

		// 优先获取Secret，volume和env都依赖secret
		$this->GetSecrets();

		$this->GetImagePullPolicy();
		$this->GetVolumes();
		$this->GetEnvs();
		$this->GetImage();
		$this->GetResources();
		$this->GetPorts();
		$this->GetHostAliases();
		$this->GetStrategy();
		$this->GetSecurityContext();
		$this->GetAffinity();
		$this->GetHostNetwork();
		$this->GetProbe();
		$this->GetLifecycle();
		$this->GetTerminationGracePeriodSeconds();
		$this->GetCommand();
		$this->GetArgs();
		
		// sidecars放在最后，可能需要用到env， secret, volume等
		$this->GetSideCars();
	}

	function GetSecrets() {
		$data = [];
		$data['k8snamespace_name'] = $this->data['k8snamespace_name'];
		$data['applicationsolution_name'] = $this->app;
		$data['data'] = $this->data['secret'];
		$data['name'] = "";

		// 获取私有secret
		$this->privateSecret = new iTopSecret($data);
		$this->secrets[] = $this->privateSecret->Get('secret');

		// 公共secret
		// ToDo
	}

	function GetImagePullPolicy() {
		global $PULLPOLICY;
		$this->imagePullPolicy = $PULLPOLICY;
		if($this->data['image_tag'] == "latest" or $this->data['image_tag'] == "") {
			$this->imagePullPolicy = "Always";
		}
	}

	function GetVolumes() {
		// 挂载volumes
		$volumes = new iTopVolume($this->data['volume_list'], $this->app);
		$this->volumes = $volumes->Run();
		// 挂载宿主机时区
		$this->volumes['volumeMounts'][] = ['name'=>'tz-config', 'mountPath'=>'/etc/localtime'];
		$this->volumes['volumes'][] = ['name'=>'tz-config','hostPath'=>['path'=>'/usr/share/zoneinfo/Asia/Shanghai']];
		// 挂载secret
		foreach($this->secrets as $key => $val) {
			if(!$val['data']) {
				continue;  // 配置项为空的情况
			}
			$secretName = $val['metadata']['name'];
			$name = $secretName . "-appconfig";
			$this->volumes['volumeMounts'][] = ['name'=>$name, 'mountPath'=>APPCONFIG_PATH, 'readOnly'=>true];
			$this->volumes['volumes'][] = ['name'=>$name,'secret'=>['secretName'=>$secretName]];
		}
	}

	function GetEnvs() {
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
			'APP_NAMESPACE' => $this->ns,
			'APP_ORG' => $this->data['organization_name'],
			'APP_DESCRIPTION' => str_replace(array("\r", "\n", "\r\n"), " ", $this->data['description']),
			'APP_ONLINEDATE' => $this->data['move2production'],
			'APP_CONTACTS' => $this->List2Str($this->data['person_list'], 'person_name'),
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

		// 同时将secret写入环境变量
		foreach($this->secrets as $k => $v) {
			foreach($v['data'] as $key => $val) {
				$this->env[] = [
					'name' => $key,
					'valueFrom' =>[
						'secretKeyRef' => [
							'name' => $v['metadata']['name'],
							'key' => $key
						]
					]
				];
			}
		} 
	}

	function GetImage() {
		$tag = $this->data['image_tag'];
		if($tag == "") {
			$tag = "latest";
		}
		$this->image = $this->data['image'] . ":" . $tag;
	}

	function GetResources() {
		$this->resources = ['requests'=>[],'limits'=>[]];
		$mem = $this->data['mem_request'] . "Mi";
		$this->resources['requests']['cpu'] = $this->data['cpu_request'];
		$this->resources['requests']['memory'] = $mem;
		$this->resources['limits']['cpu'] = $this->data['cpu_limit'];
		$this->resources['limits']['memory'] = $mem;
	}

	function GetPorts() {
		$ports = explode(",", $this->data['containerport']);
		$this->ports = [];
		foreach($ports as $k => $v) {
			$this->ports[] = ['containerPort' => (int) $v];
		}
	}

	function GetHostAliases() {
		$this->hostAliases = [];
		if($this->data['hostaliases']) {
			$hosts = yaml_parse($this->data['hostaliases']);
			if(is_array($hosts)) {
				foreach($hosts as $domain => $ip) {
						$this->hostAliases[]=["ip" => $ip, "hostnames"=>["$domain"]];
					}
			}
		}
	}

	function GetStrategy() {
		$this->strategy = [];
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
			$this->strategy = ["type" => "RollingUpdate",
				"rollingUpdate"=>["maxUnavailable"=>$ns[0], "maxSurge"=>$ns[1]]
			];
		}
	}

	function GetSecurityContext() {
		$this->securityContext = [];
		// forbidden sysctl: "net.ipv4.ip_local_port_range" not allowed with host net enabled
		if($this->data['hostnetwork'] == 'true') {
			return '';
		}
		$this->securityContext["sysctls"] = [];
		$this->securityContext["sysctls"][] = [
			"name" => "net.ipv4.ip_local_port_range",
			"value" => "2000    65000"
		];
	}

	function GetDefaultAffinity() {
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

	function GetAffinity() {
		$affinity = new iTopAffinity($this->data['affinity_list']);
		$this->affinity = $affinity->Run();
		if(count($this->affinity) == 0) {
			$this->affinity = $this->GetDefaultAffinity();
		}
	}

	function GetHostNetwork() {
		if($this->data['hostnetwork'] == "true") {
			$this->hostNetwork = true;
		}
	}

	function GetProbe() {
		$probe = new iTopProbe($this->data);
		$this->livenessProbe = $probe->livenessProbe();
		$this->readinessProbe = $probe->readinessProbe();
	}

	function GetSideCars() {
		/* sidecar 是否要创建独立的secret，环境变量及volume?
		 * 1. 独立 : 可以增加隔离性，但是管理麻烦
		 * 2. 使用主容器配置: 管理方便
		 * 选择第二种方案实现。其中，环境变量有和容器相关部分，不能全部使用主容器配置
		 */
		$sidecar_list = $this->data['sidecar_list'];
		foreach($sidecar_list as $sc) {
			$sidecar = GetData($sc['sidecarver_id'], 'SideCarVer');
			$sidecar['cpu_request'] = $sc['cpu_request'];
			$sidecar['cpu_limit'] = $sc['cpu_limit'];
			$sidecar['mem_request'] = $sc['mem_request'];
			// 需要设置sidecar的app名称及命名空间
			$sidecar['applicationsolution_name'] = $this->app;
			$sidecar['k8snamespace_name'] = $this->ns;
			$sidecar['person_list'] = $this->data['person_list'];
			// 根据app的状态决定是否要删除sidecar单独添加的secret等资源
			$sidecar['status'] = $this->data['status'];
			
			// 传入主容器的secret数据，处理时过滤出前缀为 sidecar_$sidecarname_ 的key
			$sidecar['secret'] = $this->data['secret'];
			
			// 未来可能需要处理共享volume的问题，考虑和secret类似用前缀区分
			// $sidecar['volume_list'] = $this->data['volume_list'];
			
			$oSc = new iTopSideCar($sidecar);
			$sidecars[] = $oSc->Run();
		}
	}

	function GetLifecycle() {
		$sec = '45';
		if(array_key_exists("prestop", $this->data) && $this->data['prestop']) {
			$sec = (string)$this->data['prestop'];
		}
		$this->lifecycle =  [
			'preStop' => [
				'exec' => [
					'command' => ['sleep',$sec]
				]
			]
		];
	}

	function GetTerminationGracePeriodSeconds() {
		$this->terminationGracePeriodSeconds = 90;
		if(array_key_exists('graceperiod', $this->data) && $this->data['graceperiod']) {
			$this->terminationGracePeriodSeconds = $this->data['graceperiod'];
		}
	}

	function GetCommand() {
		$command = json_decode($this->data['command'], true);
		if($this->data['command'] && is_array($command)) {
			$this->command = $command;
		}
	}

	function GetArgs() {
		$args = json_decode($this->data['args'], true);
		if($this->data['args'] && is_array($args)) {
			$this->args = $args;
		}
	}

	function DeletePod() {
		$del = true;
		// replicaSet 和 Pod 需要单独删除
		// 在子类的run方法中最后调用(先删除可能会被rs重新创建)
		$rs = $this->k8sClient->replicaSets()->setLabelSelector(['app'=>$this->app])->find();
		$pod = $this->k8sClient->pods()->setLabelSelector(['app'=>$this->app])->find();
		foreach($rs as $k => $v) {
			$this->result[] = $this->k8sClient->replicaSets()->delete($v);
		}
		foreach($pod as $k => $v) {
			$this->result[] = $this->k8sClient->pods()->delete($v);
		}
	}

	function UpdateService($del) {
		$service = new iTopService($this->data);
		$this->DealResult($service->Run($del));
	}

	function Run($del = false) {
		// 处理私有Secret 公共Secret只需挂载即可，不用处理创建更新以及删除
		$this->DealResult($this->privateSecret->Run($del));

		// 处理Service 写成方法是为了在Deployment中可以重写该方法(Worker类型不需要创建Service)
		$this->UpdateService($del);

		// 设置默认HPA
		if(!array_key_exists("hpa_list", $this->data) || !$this->data['hpa_list']) {
			$hpa = new iTopHPA($this->data);
			$this->DealResult($hpa->Run($del));
		}

		return $this->result;
	}
}

class iTopDeployment extends iTopController {
	protected $isHttp = true;
	private $deployment;
	private $domain;
	private $privateIngressServicePort;

	function __construct($data) {
		// GetEnvs设置APP_DOMAIN需要domain
		$domain = explode("/", $data['url']);
		$this->domain = end($domain);

		// 需要在执行父类构造函数之前获取正确的isHttp值，注意此时还没有设置$this->data，只能用$data
		if($data['type'] != 'web') {
			$this->isHttp = false;
		}

		parent::__construct($data);
	}

	function GetEnvs() {
		parent::GetEnvs();

		$envstr = [];
		$envstr['APP_TYPE'] = $this->data['type'];

		if($this->isHttp) {
			$envstr['APP_DOMAIN'] = $this->domain . "/," . $this->List2Str($this->data['ingress_list'], 'friendlyname');
		}

		foreach($envstr as $k => $v) {
			$this->env[] = [
				'name' => $k,
				'value' => $v
			];
		}
	}

	function GetProbe() {
		if($this->isHttp) {
			parent::GetProbe();
		} else {
			$this->readinessProbe = [];
			$this->livenessProbe = [];
		}
	}

	function GetPorts() {
		if($this->isHttp) {
			parent::GetPorts();
			$this->privateIngressServicePort = $this->ports[0]['containerPort'];
		} else {
			$this->ports = [];
			$this->privateIngressServicePort = 80;
		}
	}

	function UpdateIngress($del) {
		// 后台服务清除Ingress
		if(!$this->isHttp) {
			$del = true;
		}

		$data = [];
		$data['applicationsolution_name'] = $this->app;
		$data['domain_name'] = $this->domain;
		$data['https'] = $this->data['https'];
		$data['location'] = "/";
		$data['k8snamespace_name'] = $this->ns;
		$data['serviceport'] = $this->privateIngressServicePort;
		$data['ingressannotations_list'] = $this->data['ingressannotations_list'];
		$data['status'] = 'production'; // 私有ingress保持production状态
		$data['manage_svc'] = 'no';   //私有ingress不管理svc

		$privateIngress = new iTopIngress($data);
		// 存在domain_name为空的情况，此时不创建privateIngress
		if($data['domain_name']) {
			$this->DealResult($privateIngress->Run($del));
		}

		// 考虑先下线后上线，下线步骤删除所有ingress的情况，上线步骤应上线所有ingress
		foreach($this->data['ingress_list'] as $val) {
			$ing = new iTopIngress($val);
			$this->DealResult($ing->Run($del));
		}
	}

	function UpdateService($del) {
		if($this->isHttp) {
			parent::UpdateService($del);
		} else {
			parent::UpdateService(true);
		}
	}

	function Deployment() {
		$this->deployment = [
			'metadata' => [
				'name' => $this->app,
				'labels' => [
					'app' => $this->app
				],
				'namespace' => $this->ns,
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
						'affinity' => $this->affinity,
						'containers' => [
							[
								'name' => $this->app,
								'image' => $this->image,
								'resources' => $this->resources,
								'env' => $this->env,
								'ports' => $this->ports,
								'volumeMounts' => $this->volumes['volumeMounts'],
								'imagePullPolicy' => $this->imagePullPolicy,
							]
						],
						'volumes' => $this->volumes['volumes'],
					],
				]
			]
		];

		if($this->readinessProbe) {
			$this->deployment['spec']['template']['spec']['containers'][0]['readinessProbe'] = $this->readinessProbe;
		}

		if($this->livenessProbe) {
			$this->deployment['spec']['template']['spec']['containers'][0]['livenessProbe'] = $this->livenessProbe;
		}

		if($this->hostAliases) {
			$this->deployment['spec']['template']['spec']['hostAliases'] = $this->hostAliases;
		}

		if($this->strategy) {
			$this->deployment['spec']['strategy'] = $this->strategy;
		}

		if($this->securityContext) {
			$this->deployment['spec']['template']['spec']['securityContext'] = $this->securityContext;
		}

		if($this->lifecycle) {
			$this->deployment['spec']['template']['spec']['containers'][0]['lifecycle'] = $this->lifecycle;
		}

		if($this->command) {
			$this->deployment['spec']['template']['spec']['containers'][0]['command'] = $this->command;
		}

		if($this->args) {
			$this->deployment['spec']['template']['spec']['containers'][0]['args'] = $this->args;
		}
		
		if($this->sidecars) {
			foreach($this->sidecars as $sidecar) {
				$this->deployment['spec']['template']['spec']['containers'][] = $sidecar;
			}
		}

		$this->deployment['spec']['template']['spec']['terminationGracePeriodSeconds'] = $this->terminationGracePeriodSeconds;
	}

	function Run($del = false) {
		parent::Run($del);

		$this->Deployment();
		$deployment = new Deployment($this->deployment);
		$this->exists = $this->k8sClient->deployments()->exists($deployment->getMetadata('name'));

		if($del) {
			$this->result[] = $this->k8sClient->deployments()->deleteByName($this->app);
		} elseif($this->exists) {
			$this->result[] = $this->k8sClient->deployments()->update($deployment);
		} else {
			$this->result[] = $this->k8sClient->deployments()->create($deployment);
		}

		$this->UpdateIngress($del);

		// 清理ReplicaSet 和 Pod
		if($del) {
			$this->DeletePod();
		}

		return $this->result;
	}
}

class iTopSecret extends iTopK8s {
	private $name;
	protected $secret;
	private $isYaml = false;
	private $oData;    // Secret data

	function __construct($data) {
		parent::__construct($data);

		$this->oData = $data['data'];

		// 私有secret名称直接用app名称，公共secret用app名称加secret名称，Controller对象中私有secret调用时将data['name']设置为空值
		$this->name = $this->app;
		if($data['name']) {
			$this->name = $this->app . "-" . $data['name'];
		}

		// 加前缀 便于区分
		$this->name = SECRET_PRE . $this->name;

		$this->CheckYaml();
		$this->Secret();
	}

	// 检查是否是yaml格式
	function CheckYaml() {
		if(!$this->oData) {
			$this->oData = [];
			$this->isYaml = true;  // secret为空时执行删除逻辑， 不判断是否是yaml
			return;
		}
		$parsed = yaml_parse($this->oData);
		if(is_array($parsed)) {
			$this->oData = $parsed;
			$this->isYaml = true;
		} else {
			$this->oData = [];
		}
	}

	function Secret() {
		$secret_data = [];
		foreach($this->oData as $k => $v) {
			$secret_data[$k] = base64_encode($v);
		}
		$this->secret = [
			'metadata' => [
				'name' => $this->name,
				'namespace' => $this->ns
			],
			'type' => 'Opaque',
			'data' => $secret_data
		];
	}

	function DealLog($r) {
		if($r['kind'] == "Secret") {
			// 不在iTop事件日志中记录secret内容
			unset($r['data']);return $r;
		}
		return $r;
	}

	function Run($del = false) {
		if(!$this->oData) {
			$del = true;
		}

		if(!$this->isYaml) {
			$this->result[] = ["kind"=>"Status", "message"=>['message'=>"ERR: secret is not valid yaml"]];
			return ($this->result);
		}

		$secret = new Secret($this->secret);
		$this->exists = $this->k8sClient->secrets()->exists($secret->getMetadata('name'));

		$r = ['kind'=>"Secret", 'metadata'=>['name'=>$this->name], "message"=>" Not Found"];
		if($del) {
			if($this->exists) $r = $this->k8sClient->secrets()->deleteByName($this->name);
		} elseif($this->exists) {
			$r = $this->k8sClient->secrets()->update($secret);
		} else {
			$r = $this->k8sClient->secrets()->create($secret);
		}
		$this->result[] = $this->DealLog($r);
		return ($this->result);
	}
}

class iTopAffinity {
	private $data;
	private $affinity;

	function __construct($data) {
		$this->data = $data;
		$this->affinity = [];
	}

	private function CheckKey($key) {
		if(!array_key_exists($key, $this->affinity)) {
			$this->affinity[$key] = [];
		}
	}

	private function CheckKey2($key, $key2) {
		if(!array_key_exists($key, $this->affinity[$key2])) {
			$this->affinity[$key2][$key] = [];
		}
	}

	private function GetExp($exp, $values) {
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

	function GetNodeAffinity($val) {
		$this->CheckKey("nodeAffinity");
		$tp = "required";
		if($val['k8saffinity_requiretype'] == "required") {
			$key = "requiredDuringSchedulingIgnoredDuringExecution";
		} else {
			$key = "preferredDuringSchedulingIgnoredDuringExecution";
			$tp = "preferred";
		}

		$this->CheckKey2($key, "nodeAffinity");
		if($tp == "required") {
			$this->affinity["nodeAffinity"][$key]["nodeSelectorTerms"][$val['group']] = ["matchExpressions" => []];
			$this->affinity["nodeAffinity"][$key]["nodeSelectorTerms"][$val['group']]["matchExpressions"][] = $this->GetExp($val['k8saffinity_expressions'], $val['values']);
		}
	}

	function GetPodAffinity($val) {
		$this->CheckKey("podAffinity");
	}

	function GetPodAntiAffinity($val) {
		$this->CheckKey("podAntiAffinity");
	}

	function DelArrayKey() {
		if(array_key_exists("nodeAffinity", $this->affinity)) {
			if(array_key_exists("requiredDuringSchedulingIgnoredDuringExecution", $this->affinity['nodeAffinity'])) {
				$this->affinity['nodeAffinity']['requiredDuringSchedulingIgnoredDuringExecution']['nodeSelectorTerms'] = array_values($this->affinity['nodeAffinity']['requiredDuringSchedulingIgnoredDuringExecution']['nodeSelectorTerms']);
			}
		}
	}

	function Run() {
		foreach($this->data as $val) {
			$affinitytype = $val['k8saffinity_affinitytype'];
			if(in_array($affinitytype, ["nodeaffinity", "nodeantiaffinity"])) {
				$this->GetNodeAffinity($val);
			} elseif($affinitytype == "podaffinity") {
				$this->GetPodAffinity($val);
			} else {
				$this->GetPodAntiAffinity($val);
			}
		}
		$this->DelArrayKey();
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

	function GetHostpath($key, $val) {
		global $hostPathPre;
		$name = "hostpath-" . $key . "-" . $this->app;
		$path = rtrim($hostPathPre, "/") . "/" . $name;
		$this->volumes['volumeMounts'][] = ['name'=>$name, 'mountPath'=>$val['mountpath']];
		$this->volumes['volumes'][] = ['name'=>$name, 'hostPath'=>['path'=>$path]];
	}

	function Run() {
		foreach($this->data as $key => $val) {
			$volumetype = $val['k8svolume_type'];
			if($volumetype == "hostpath") {
				$this->GetHostpath($key, $val);
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

	function GetProbe($val) {
		$probe = [];
		$common_conf = @yaml_parse($val['common_conf']);
		if(is_array($common_conf)) {
			foreach($common_conf as $k => $v) {
				$probe[$k] = $v;
			}
		}

		if($val['type'] == "httpGet") {
			$httpGet = @yaml_parse($val['httpGet']);
			if(is_array($httpGet)) {
				$probe['httpGet'] = $httpGet;
			} else {
				return false;
			}
		} elseif($val['type'] == "exec") {
			$command = @yaml_parse($val['exec_command']);
			if(is_array($command)) {
				$probe['exec'] = $command;
			} else {
				return false;
			}
		} elseif($val['type'] == "tcpSocket") {
			$probe['tcpSocket']['port'] = (int)$val['tcpSocket_port'];
		} else {
			return false;
		}
		return $probe;
	}

	function readinessProbe() {
		if(array_key_exists("probe_list", $this->data)) {
			foreach($this->data['probe_list'] as $val) {
				if($val['k8sprobe_name'] == "readinessProbe" && $val['enable'] == 'yes') {
					$probe = $this->GetProbe($val);
					if($probe) return $probe;
				}
			}
		}
		return ["tcpSocket"=>["port" => $this->port], "initialDelaySeconds"=>_getconfig("kubernetes_readiness_initdelay",5),"periodSeconds" => _getconfig("kubernetes_readiness_period", 5)];
	}

	function livenessProbe() {
		if(array_key_exists("probe_list", $this->data)) {
			foreach($this->data['probe_list'] as $val) {
				if($val['k8sprobe_name'] == "livenessProbe" && $val['enable'] == 'yes') {
					$probe = $this->GetProbe($val);
					if($probe) return $probe;
				}
			}
		}
		return ["tcpSocket"=>["port" => $this->port], "initialDelaySeconds"=>_getconfig("kubernetes_liveness_period", 60),"periodSeconds" => _getconfig("kubernetes_liveness_period", 5)];
	}
}

class iTopSideCar extends iTopController {
	function __construct($data) {
		parent::__construct($data);
		$this->FilterEnv();
	}
	
	// 重载此函数，直接返回空
	function GetSideCars() {
		return false;
	}
	
	// 处理env，替换containerName
	function FilterEnv() {
		foreach($this->env as $key => $val) {
			if(array_key_exists('valueFrom', $val) && array_key_exists('resourceFieldRef', $val['valueFrom']) && array_key_exists('containerName', $val['valueFrom']['resourceFieldRef'])) {
				$val['valueFrom']['resourceFieldRef']['containerName'] = $this->data['k8sappstore_name'];
				$this->env[$key] = $val;
			}
		}
	}
	
	// 此处$del没啥用
	function Run($del = false) {
		// 这里的 data['status']是传参过来的app的status，不是sidecarver的status。当app下线时，执行删除sidecar创建的资源
		// SideCarVer不管理任何k8s 资源，不应执行 parent::Run();
		$container = [
			'name' => $this->data['k8sappstore_name'],
			'image' => $this->image,
			'resources' => $this->resources,
			'env' => $this->env,
			'ports' => $this->ports,
			'volumeMounts' => $this->volumes['volumeMounts'],
			'imagePullPolicy' => $this->imagePullPolicy,
		];
		
		if($this->readinessProbe) {
			$container['readinessProbe'] = $this->readinessProbe;
		}

		if($this->livenessProbe) {
			$container['livenessProbe'] = $this->livenessProbe;
		}
		if($this->lifecycle) {
			$container['lifecycle'] = $this->lifecycle;
		}

		if($this->command) {
			$container['command'] = $this->command;
		}

		if($this->args) {
			$container['args'] = $this->args;
		}
		
		return $container;
	}
}

class iTopIngress extends iTopK8S {
	private $name;
	private $ingress;
	private $serviceName;
	private $externalService;

	function __construct($data) {
		parent::__construct($data);
		$this->serviceName = $this->app;
		$this->ingress = [];
		$this->GetName();
		// 每一个外部服务负载均衡应对应唯一的Service，防止只用 app-后缀 方案可能存在的配置覆盖问题
		// 用 IngressNmae-后缀 的方式提供唯一名称
		$this->data['name'] = $this->externalService;
	}

	private function GetName() {
		$matches = [];
		$this->name = $this->app . "-" . $this->data['domain_name'] . "-" . $this->data['location'];
		$md5Str = md5($this->name);
		$hash = substr($md5Str, 0, 5);
		// By convention, the names of Kubernetes resources should be up to maximum length of 253 characters and consist of lower case alphanumeric characters, -, and .
		preg_match_all('/[a-z0-9\.]+/', $this->name, $matches);
		$this->name = implode("-", $matches[0]);
		$this->name = $this->name . "-" . $hash;
		// 取16位md5值
		$this->externalService = $this->app . "-forexternal-" . substr($md5Str, 8, 16);
	}

	private function Ingress() {
		$rules = [];
		$tls = [];
		$data = $this->data;
		$rules[] = [
			'host' => $data['domain_name'],
			'http' => [
				'paths' => [
					[
						'path' => $data['location'],
						'backend' => [
							'serviceName' => $this->serviceName,
							'servicePort' => (int)$data['serviceport']
						]
					]
				]
			]
		];
		// secretName 为 default-tls，需事先通过kubectl创建
		if($this->data['https'] == "on") {
			$tls[] = ['hosts' =>[$data['domain_name']], 'secretName' => 'default-tls'];
		}
		$customNginx = new iTopIngressAnnotations($data['ingressannotations_list']);
		$annotations = $customNginx->Run();
		$annotations['kubernetes.io/ingress.class'] = $data['k8snamespace_name'];

		// 考虑同一个域名多location的情况,HTTPS可能同时存在on和off，为了避免308引起的问题，统一https为关闭的ingress添加nginx.ingress.kubernetes.io/ssl-redirect配置
		if($this->data['https'] == "off" && !array_key_exists("nginx.ingress.kubernetes.io/ssl-redirect", $annotations)) {
			$annotations['nginx.ingress.kubernetes.io/ssl-redirect'] = "false";
		}

		$this->ingress = [
			'metadata' => [
				'name' => $this->name,
				'namespace' => $data['k8snamespace_name'],
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

	function Run($del = false) {
		// 根据manage_svc做不同处理
		if($this->data['manage_svc'] != 'no') {
			$service = new iTopService($this->data);
			if($this->data['manage_svc'] == "clean") {
				$this->DealResult($service->Run(true));
			} else {
				$this->DealResult($service->Run($del));
				$this->serviceName = $service->Get('serviceName');
			}
		}

		$this->Ingress();
		$ingress = new Ingress($this->ingress);
		$this->exists = $this->k8sClient->ingresses()->exists($ingress->getMetadata('name'));

		// 因为iTopKubernetes中_updateIngress要将Deployment ingress_list中的所有对象都上线，
		// 所以即使没有del=true，当对象状态为stock时需也需要del，防止误上线
		if($this->data['status'] == 'stock') {
			$del = true;
		}

		if($del) {
			if($this->exists) $this->result[] = $this->k8sClient->ingresses()->deleteByName($ingress->getMetadata('name'));
		} elseif($this->exists) {
			$this->result[] = $this->k8sClient->ingresses()->update($ingress);
		} else {
			$this->result[] = $this->k8sClient->ingresses()->create($ingress);
		}

		return ($this->result);
	}
}

class iTopIngressAnnotations {
	private $data;
	private $annotations;

	function __construct($data) {
		$this->data = $data;
		$this->annotations = [];
	}

	function Run() {
		foreach($this->data as $val) {
			if($val['enable'] != 'yes') {
				continue; // 解决暂时下线某项配置，以后可能还用的场景
			}
			if($val['value']) {
				$v = $val['value'];
			} else {
				$v = $val['k8singressannotations_default_value'];
			}
			$this->annotations[$val['k8singressannotations_name']] = $v;
		}
		return $this->annotations;
	}
}

class iTopHPA extends iTopK8s {
	private $hpa;
	private $min;
	private $max;
	private $metrics;

	function __construct($data) {
		parent::__construct($data);
		$this->metrics = [];
		if($this->data['finalclass'] == "Deployment") {
			$this->GetDefaultHpa();
		} else {
			$this->GetCustomHpa();
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

	function GetDefaultHpa() {
		$this->min = ceil($this->data['replicas'] * _getconfig("kubernetes_hpa_default_min", 0.3));
		if($this->data['hostnetwork'] == 'true' || $this->data['fix_replicas'] == 'true') {
			$this->max = (int)$this->data['replicas'];
			$this->min = $this->max;
		} else {
			$this->max = ceil($this->data['replicas'] * _getconfig("kubernetes_hpa_default_max", 3));
		}

		$this->AddResouceMetrics(_getconfig("kubernetes_hpa_targetcpuutilizationpercentage", 60));
		$this->AddResouceMetrics(_getconfig("kubernetes_hpa_targetmemoryutilizationpercentage", 85), "memory");
	}

	function GetCustomHpa() {
		$this->min = $this->data['minreplicas'];
		$this->max = $this->data['maxreplicas'];
		$metrics = $this->data['metrics'];
		if($metrics) {
			$metrics = yaml_parse($metrics);
		}
		foreach($metrics as $key => $val) {
			switch($key) {
				case "cpu": $this->AddResouceMetrics((int)$val); break;
				case "memory": $this->AddResouceMetrics((int)$val, "memory"); break;
				default : break;
			}
		}
	}

	function AddResouceMetrics($val,$rtype="cpu") {
		$this->metrics[] = [
			"type" => "Resource",
			"resource" => [
				"name" => $rtype,
				"targetAverageUtilization" => $val
			]
		];
	}

	function Run($del = false) {
		$hpa = new HorizontalPodAutoscaler($this->hpa);
		$this->exists = $this->k8sClient->horizontalPodAutoscalers()->exists($hpa->getMetadata('name'));

		if($del) {
			if($this->exists) $this->result[] = $this->k8sClient->horizontalPodAutoscalers()->deleteByName($hpa->getMetadata('name'));
		} elseif($this->exists) {
			$this->result[] = $this->k8sClient->horizontalPodAutoscalers()->update($hpa);
		} else {
			$this->result[] = $this->k8sClient->horizontalPodAutoscalers()->create($hpa);
		}

		return ($this->result);
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
	$description = [];
	foreach($log as $k => $v) {
		if(!is_array($v) || !array_key_exists("kind", $v)) {
			$description[] = $v;
			continue;
		}
		if($v['kind'] == "Status") {
			if(array_key_exists('message', $v)) {
				$description[] = $v['message'];
			} elseif(array_key_exists('status', $v)) {
				$description[] = "Delete " . $v['details']['kind'] . " " . $v['details']['name'] . "  " . $v['status'];
			}
		} else {
			$description[] = "Update " . $v['kind'] . " " . $v['metadata']['name'] . " successful";
		}
		if(array_key_exists("spec", $v)) {
			// 去除spec内容，不然log太多
			unset($log[$k]['spec']);
		}
		if(array_key_exists("status", $v)) {
			unset($log[$k]['status']);
		}
	}

	$message = implode("\n", $description);
	if(!$message) $message = "nothing to do";

	$fields = array(
		"message" => $message,
		"date" => date("Y-m-d H:i:s"),
		"userinfo" => "kubernetes",
		"trigger_id" => $trigger_id,
		"action_id" => $action_id,
		"object_id" => $ID,
		"log" => json_encode($log, JSON_PRETTY_PRINT)
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
	$obj = reset($KubeObj);
	$ret = $obj['fields'];
	$ret['key'] = $obj['key'];
	return $ret;
}

// 更新部署状态到iTop
function UpdateKubestatus($ret, $class, $id, $status) {
	$stat = "production";
	// 如果存在kind=>Status的结果，说明有对象更新异常
	foreach($ret as $val) {
		if(!is_array($val) || !array_key_exists("kind", $val)) {
			$stat = "error";
			break;
		}
		if($val['kind'] == 'Status') {
			if(array_key_exists('message', $val)) {
				$stat = "error";
				break;
			}
		}
	}
	$flag_kubestatus = "AUTOUPDATE";

	global $iTopAPI;

	$stimulus = "UpdateKubestatus: nothing to do";
	// 当状态有变时才更新
	if($status != $stat) {
		$ev = 'ev_update';
		if($stat == 'error') {
			$ev = 'ev_error';
		}
		global $iTopAPI;
		$stimulus = $iTopAPI->coreApply_stimulus($class,$id, array("flag_kubestatus"=>$flag_kubestatus),$ev);
	}

	// 删除脚本启动的通知日志，通知页面太乱了
	$oql = "SELECT EventNotificationShellExec AS e JOIN TriggerOnObject AS t ON e.trigger_id=t.id WHERE t.target_class='Kubernetes' AND e.object_id=$id AND message LIKE 'Script%successfully started.'";
	$d = $iTopAPI->coreDelete('EventNotificationShellExec', $oql);
	return $stimulus;
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
		file_put_contents($log, $config['datetime'] . " - $ID - flag_kubestatus set, exit; - " . json_encode($data) . "\n", FILE_APPEND);
		die();
	}
}

$finalclass = $data['finalclass'];

if($finalclass == "Secret") {
	$itopK8s = new iTopSecret($data);
}
if($finalclass == "Ingress") {
	$itopK8s = new iTopIngress($data);
}
if($finalclass == "Deployment") {
	$itopK8s = new iTopDeployment($data);
}

try {
	$ret = $itopK8s->Run($del);
} catch(Exception $e) {
	$message = json_decode($e->getMessage(), true);
	if(!$message) $message = $e->getMessage();
	$ret = [$message];
}
$itopEvent = CreateEvent($ret);


// 下线操作不写kubestatus
// 因为下线操作不能更新flag_kubestatus，当del=true时，不检查flag_kubestatus
// 但这样又会造成循环更新，因此如果是del，就不更新iTop了，防止无限循环
if($del) {
	$updateStatus = "action stock";
} else {
	$updateStatus = UpdateKubestatus($ret, $finalclass, $ID, $data['status']);
}

if($DEBUG) { print_r($ret); }

$retStr = json_encode($ret);
file_put_contents($log, $config['datetime'] . " - $ID - $retStr\n", FILE_APPEND);
file_put_contents($log, $config['datetime'] . " - $ID - $itopEvent\n", FILE_APPEND);
file_put_contents($log, $config['datetime'] . " - $ID - $updateStatus\n", FILE_APPEND);
?>
