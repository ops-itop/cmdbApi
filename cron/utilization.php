<?php
/**
 * Usage: 服务器CPU，内存，硬盘利用率统计
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2018-07-23 18:41:33
 **/

require dirname(__FILE__).'/../etc/config.php';
require dirname(__FILE__).'/../lib/core.function.php';
require dirname(__FILE__).'/../composer/vendor/autoload.php';
require dirname(__FILE__).'/../composer/vendor/confirm-it-solutions/php-zabbix-api/build/ZabbixApi.class.php';

// 是否生成环境执行，只有PRODUCT为真才往influxdb提交数据
$PRODUCT=getenv("PRODUCT");
define('ZBXURL', $config['zabbix']['url']);
define('ZBXUSER', $config['zabbix']['user']);
define('ZBXPWD', $config['zabbix']['password']);

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

// influxdb要求Field数据类型一致，通过bcscale确保保留2为小数
bcscale(2);

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');
$zbxAPI = new \ZabbixApi\ZabbixApi(ZBXURL, ZBXUSER, ZBXPWD);

/**
 * 统计以下指标
 * cpu总数, cpu使用率 
 * 内存总量，剩余内存量，内存使用率
 * 磁盘空间总量，剩余磁盘空间量，磁盘使用率
 * 以上均取最近一小时监控值的平均值
 *
 * 首先在zabbix添加以上监控，然后用calculated类型的item计算最近一小时的平均
 * 值，平均使用率等数据，并将需要的指标存入inventory，这样查找的时候更方便
 * cpu空闲率 -> alias
 * CPU核数   -> tag
 * 磁盘总量  -> hardware
 * 磁盘使用率-> software
 * 总内存    -> notes
 * 可用内存  -> chassis
 *
 * getAllServer() 取 应统计数量
 * zabbixAllHostGet() 去除不在 getAllServer() 中的，剩余为 实际统计数量
 */

// iTop 中查询所有服务器列表，排除不需要统计的组织
function getAllServer()
{
	global $iTopAPI;
	$oql = "SELECT Server WHERE status!='obsolete'";
	$output_fields = "name,organization_name";
	$data = $iTopAPI->coreGet("Server", $oql, $output_fields);
	$data = json_decode($data, true);
	$sns = [];
	$exclude = excludeFilter();
	foreach($data['objects'] as $k => $v) {
		foreach($exclude as $key => $val) {
			if(array_key_exists($key, $v['fields'])) {
				if(in_array($v['fields'][$key], $val)) continue 2;
			}
		}
		$sns[] = $v['fields']['name'];
	}
	return $sns;
}

//print_r(getAllServer());die();
// zabbix查询host接口
function zabbixHostGet($name)
{
	global $zbxAPI;
	$param = array(
		"output" => array("host","inventory"),
		"selectInventory" => array("asset_tag", "alias", "tag", "hardware", "software","notes", "chassis"),
		//"searchInventory" => array("asset_tag" => $name)
	);
	$data = $zbxAPI->hostGet($param);
	return($data);
}

// zabbix获取所有有asset_tag的服务器
function zabbixAllHostGet()
{
	return(json_decode(json_encode(zabbixHostGet("")), true));
}

// cpu使用率分段统计
function cpuUtil($idle, &$metrics) {
	// 分段区间 1,2,5,10,20,30,40,50,60,70,80,90,100
	$range = [0,1,2,5,10,15,20,30,40,50,60,70,80,90];
	$util = 100 - $idle;
	foreach($range as $v) {
		$s = (string)$v;
		$k = 'cpu_util' . $s;
		if(!array_key_exists($k, $metrics)) $metrics[$k] = 0;
	}

	switch($util) {
	case $util < 1:
		$k = 'cpu_util0'; $metrics[$k]++; break;
	case $util < 2:
		$k = 'cpu_util1'; $metrics[$k]++; break;
	case $util < 5:
		$k = 'cpu_util2'; $metrics[$k]++; break;
	case $util < 10:
		$k = 'cpu_util5'; $metrics[$k]++; break;
	case $util < 15:
		$k = 'cpu_util10'; $metrics[$k]++; break;
	case $util < 20:
		$k = 'cpu_util15'; $metrics[$k]++; break;
	case $util >= 20 && $util <= 100:
		$k = floor($util/10) * 10;
		$k = 'cpu_util' . (string)$k; $metrics[$k]++;break;
	default:
		break;
	}

}

// cpu使用率分段统计 - 百分比
function cpuUtilPercent(&$metrics) {
	foreach($metrics as $k => $v) {
		if(preg_match('/^cpu_util/', $k)) {
			$key = 'percent_' . $k;
			$metrics[$key] = $v/$metrics['real_count'];
		}
	}
}

function calMetrics() {
	$metrics = ['cpu_all'=>0,'cpu_avail'=>0,'mem_all'=>0,'mem_avail'=>0,'disk_all'=>0,'disk_avail'=>0];
	$cmdbHosts = getAllServer();
	$zabbixHosts = zabbixAllHostGet();

	$metrics['expect_count'] = count($cmdbHosts);
	$calHosts = [];
	foreach($zabbixHosts as $k => $v) {
		if(array_key_exists('asset_tag', $v['inventory']) && in_array($v['inventory']['asset_tag'], $cmdbHosts)) {
			$calHosts[] = $v['inventory'];
		}
	}

	$metrics['real_count'] = count($calHosts);
	$metrics['error_count'] = 0;
	$metrics['error_log'] = [];

	foreach($calHosts as $k => $v) {
		foreach($v as $key => $val) {
			if(!$val) {
				$metrics['real_count']--;
				$metrics['error_count']++;
				$metrics['error_log'][$v['hostid']] = $v['asset_tag'];
				continue 2;
			}
		}
		$metrics['cpu_all'] = bcadd($metrics['cpu_all'],$v['tag']);
		$metrics['cpu_avail'] = bcadd($metrics['cpu_avail'],$v['tag'] * $v['alias'] / 100);
		// cpu利用率分段统计
		cpuUtil($v['alias'], $metrics);
		$metrics['mem_all'] = bcadd($metrics['mem_all'],str_replace(" GB", "", $v['notes']) * 1024 * 1024 * 1024);
		$metrics['mem_avail'] = bcadd($metrics['mem_avail'],$v['chassis']);
		$metrics['disk_all'] = bcadd($metrics['disk_all'],$v['hardware']);
		$metrics['disk_avail'] = bcadd($metrics['disk_avail'],$v['hardware'] - $v['hardware'] * $v['software']);
	}
	cpuUtilPercent($metrics);
	return $metrics;
}

function toFloat($metrics) {
	foreach($metrics as $k => $v) {
		$metrics[$k] = (double)$v;
	}
	return $metrics;
}
$metrics = calMetrics();
$metrics = toFloat($metrics);
$client = new InfluxDB\Client($config['influx']['host'], $config['influx']['port']);
$database = $client->selectDB($config['influx']['utildb']);
$error_log = $metrics['error_log'];
unset($metrics['error_log']);
$points = [];
$time = time();
$points[] = new InfluxDB\Point($config['influx']['utilmeasur'], null, [], $metrics, $time);
$result = "not write";
if($PRODUCT) {
	$result = $database->writePoints($points, InfluxDB\Database::PRECISION_SECONDS, null);
}
print_r("influxdb write status: " . $result . "\n");
print_r("error_log: \n" . implode("\n", $error_log) . "\n");
print_r("metrics: \n");
var_dump($points);
