<?php
/**
 * Usage:
 * File Name: logininfo.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-03-29 10:05:48
 * 用于登录机器时打印机器信息
 * 机器上部署 /etc/profile.d/login.sh 访问此接口
 **/

require '../etc/config.php';
require '../composer/vendor/autoload.php';

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

define('CACHE_HOST', $config['memcached']['host']);
define('CACHE_PORT', $config['memcached']['port']);
define('CACHE_EXPIRATION', $config['memcached']['expiration']);


// 状态
define('PAM_OFF', "PAM_OFF");
define('ACCOUNTS_OK', "ACCOUNTS_OK");

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');

define('HEAD',"\n################################ Server Info ################################\n");
define('WARN',"WARNING!! 此机器未登记业务信息，有被下线风险");
define('TIP',"如果您认为以上信息有误，请及时联系运维修正错误");
define('TAIL',"\n#############################################################################\n");

function red($text) {
	return "\033[31m     $text \033[0m";
}

function skyblue($text) {
	return "\033[36m     $text \033[0m";
}

function yellow($text) {
	return "\033[33m     $text \033[0m";
}

function white($text) {
	return "\033[37m     $text \033[0m";
}

function defaultInfo() {
	$info = red(WARN);
	return $info;
}

function errorInfo() {
	$info = HEAD . skyblue("获取业务信息失败，请联系运维") .  TAIL;
	return $info;
}

function getServerLoginInfo($ip)
{
	global $iTopAPI;
	$query = "SELECT Server AS s JOIN PhysicalIP AS ip ON ip.connectableci_id=s.id WHERE ip.ipaddress = '$ip'";	

	$optional = array(
		'output_fields' => array("Server"=>"status,location_name","ApplicationSolution"=>"name"),
		'show_relations' => ["Server","ApplicationSolution","Location","Person"],
		'hide_relations' => [],
		'direction' => 'both',
		'filter' => ["Server","ApplicationSolution","Person"],
		'depth' => 3,
	);
	$data = $iTopAPI->extRelated("Server", $query, "impacts", $optional);

	$obj = json_decode($data, true)['objects'];
	if(!$obj)
	{
		return(errorInfo());
	}
	
	$apps = [];
	$contacts = [];
	$location = "";
	foreach($obj as $k => $v) {
		if($v['class'] == "Person") {
			$contacts[] = $v['fields']['friendlyname'];
		}
		if($v['class'] == "Server") {
			$location = $v['fields']['location_name'];
		}
		if($v['class'] == "ApplicationSolution") {
			$apps[] = $v['fields']['name'];
		}
	}

	if(!$apps) {
		$apps = WARN;
	} else {
		$apps = implode(",", $apps);
	}
	$contacts = implode(",", $contacts);
	$info = HEAD;
	$info .= red("业务：" . $apps) . "\n";
	$info .= skyblue("机房：" . $location) . "\n";
	$info .= white("联系人：" . $contacts) . "\n";
	$info .= "\n" . yellow(TIP) . TAIL;
	return($info);
}


// 使用缓存需要配合iTop触发器及action-shell-exec， lnkContactToFunctionalCI对象创建或者工单
// 审批通过时，需要触发一个脚本去更新缓存。对象删除暂时不能通过触发器，考虑每小时定时任务
// 或者开发一个trigger-ondelete插件
// Server的pam开关有变化时，也需要触发操作，需要trigger-onupdate插件
function setCache($ip, $value)
{
	$m = new Memcached();
	$m->addServer(CACHE_HOST, CACHE_PORT);
	$expiration = time() + (int)CACHE_EXPIRATION;
	return($m->set($ip, $value, $expiration));
}

function getCache($ip)
{
	$m = new Memcached();
	$m->addServer(CACHE_HOST, CACHE_PORT);
	return($m->get($ip));
}

if(isset($_GET['ip'])) {
	$ip = $_GET['ip'];
	// 暂时不用缓存
	die(getServerLoginInfo($ip));
	// 设置缓存(无需校验IP)
	if(isset($_GET['cache']) && $_GET['cache'] == "set")
	{
		$ret = getServerLoginInfo($ip);
		die(setCache($ip, $ret));
	}

	if(isset($_GET['cache']) && $_GET['cache'] == "false")
	{
		die(getServerLoginInfo($ip));
	}else
	{
		// 首先获取缓存内容
		$ret = getCache($ip);
		if(!$ret)
		{
			$ret = main($ip);
			setCache($ip, $ret);
		}
		die($ret);
	}
}else
{
	die(errorInfo());
}

