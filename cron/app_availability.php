<?php
/**
 * Usage: app可用率分级报表
 * File Name:
 * Author:  
 * Mail: 
 * Created Time: 2017-01-11 17:27:00
 **/

require dirname(__FILE__).'/../etc/config.php';
require dirname(__FILE__).'/../lib/core.function.php';
require dirname(__FILE__).'/../lib/table.class.php';
require dirname(__FILE__).'/../composer/vendor/autoload.php';

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

define('MAILAPI', $config['mail']['api']);
define('MAILTO', $config['mail']['to']);
define('MAILCC', $config['mail']['cc']);
define('MAILFROM', $config['mail']['from']);

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');

// 发邮件
function sendmail($sub, $content, $format="text")
{
	$data = array(
		"tos" => MAILTO,
		"cc" => MAILCC,
		"subject" => $sub,
		"content" => $content,
		"from" => MAILFROM,
		"format" => $format
	);
	return(http_mail(MAILAPI, $data));
}

// iTop 中查询所有的business_criticity属性及联系人信息
function getAppInfo()
{
	global $iTopAPI;
	global $config;
	$oql = "SELECT ApplicationSolution AS app WHERE app.status='production'";
	$output_fields = "name,business_criticity,contact_list_custom";
	$data = $iTopAPI->coreGet("ApplicationSolution", $oql, $output_fields);
	$data = json_decode($data, true);
	if($data['code'] != 0){
		return array();
	}
	$info = array("sla" => array(), "contact" => array());
	foreach($data['objects'] as $k => $v)
	{
		$info['sla'][$v['fields']['name']] = $config['app_sla'][$v['fields']['business_criticity']];	
		$contacts = $v['fields']['contact_list_custom'];
		$contact_array = array();
		foreach($contacts as $key => $value)
		{
			array_push($contact_array, preg_replace("/(\w*\d*)(@.*)/i", "$1", $value['contact_email']));
		}
		$info['sla'][$v['fields']['name']] = $config['app_sla'][$v['fields']['business_criticity']];	
		$info['contact'][$v['fields']['name']] = $contact_array;
	}
	return $info;
}

function queryAvailabilityData($database, $sql)
{
	$result = $database->query($sql);
	$series = $result->getSeries();
	return($series);
}

// 获取联系人列表
function mailToList($app)
{
	global $iTopAPI;
	global $config;
	$app = implode("','", $app);
	//print($app);
	$oql = "SELECT lnkContactToApplicationSolution AS l WHERE l.applicationsolution_name IN ('$app')";
	$output_fields = "contact_email";
	$data = $iTopAPI->coreGet("lnkContactToApplicationSolution", $oql, $output_fields);
	$data = json_decode($data, true);
	$to = array();
	if($data['code'] == 0 && $data['objects'] != null)
	{
		$data = $data['objects'];
		foreach($data as $k => $v)
		{
			array_push($to, $v['fields']['contact_email']);
		}
	}
	$to = implode(",", array_unique($to));
	return($to);
}

function stripAppName($app)
{
	// 处理带端口的app，例如 desktop.8080
	if(preg_match("/^\w+([-_]\w+\d*)*\.\d+$/", $app))
	{
		$app = explode(".", $app);
		$app = array_slice($app, 0, -1);
		$app = implode(".", $app);
	}
	return($app);
}

function appSLA($app, $sla)
{
	global $config;
	if(array_key_exists($app, $sla)) {
		return($sla[$app]);
	}else {
		return($config['app_sla']['null']);
	}
}

function emailReport($orig, $sla, $contacts)
{
	global $config;
	
	$report = array();
	foreach($config['app_sla'] as $k => $v)
	{
		$report[$v] = array();
	}
	
	$apps = array(); // 所有app

	foreach($orig as $k => $v)
	{
		$item = array();
		$item['app'] = $v['tags']['app'];
		$strip_app = stripAppName($item['app']);
		array_push($apps, $strip_app);
		$item['cluster'] = $v['tags']['cluster'];
		$item['level'] = appSLA($strip_app, $sla);
		$item['avail'] = (float)$v['values'][0][1];
		$item['status'] = "达标";
		if(!array_key_exists($item['level'], $config['app_status']))
		{
			$item['status'] = "未定级";
			$item['sla'] = "未定义";
		}elseif($item['avail'] < $config['app_status'][$item['level']])
		{
			$item['sla'] = $config['app_status'][$item['level']];
			$item['status'] = "不达标";
		}
		if($item['status'] != "达标")
		{
			$item['联系人'] = appContacts($strip_app, $contacts);
			array_push($report[$item['level']], $item);
		}
	}

	// 转换成html表格
	$mail = "";
	$content = "";
	foreach($report as $k => $v)
	{
		$table = new Table();
		$title = "业务级别 $k 报表";
		$style = "border: 2px solid #cad9ea;";
		$content .= $table->array2table($v, $title, $style) . "<br><hr/><br>";
	}

	$mail = $content;

	$title = "APP可用率报表";
	$report_date = date("Y-m-d" , strtotime("-1 day"));

	if(array_key_exists("report_tpl", $config))
	{
		$path = dirname(__FILE__) . "/" . $config['report_tpl'];
		$mail = file_get_contents($path);
		$mail = str_replace('{$content}', $content, $mail);
		$mail = str_replace('{$title}', $title, $mail);
		$mail = str_replace('{$report_date}', $report_date, $mail);
	}
	
	if($config['report_debug'])
	{
		$to = $config['mail']['to'];
		$cc = $config['mail']['cc'];
	} else {
		$to = mailToList($apps);
		$cc = $config['report_cc'];
	}	

	// 发邮件
	$data = array(
		"tos" => $to,
		"cc" => $cc,
		"subject" => $title . "-" . $report_date,
		"content" => $mail,
		"from" => $config['mail']['from'],
		"format" => "html"
	);
	return(http_mail(MAILAPI, $data));	
}

function appContacts($app, $contacts)
{
	if(array_key_exists($app, $contacts)) {
		return(implode(",",$contacts[$app]));
	}
	return("");
}

function genAvailabilityPoints($measurement, $orig, $sla, $contacts)
{
	$points = array();

	// 更新tags和values，增加级别和日期信息
	foreach($orig as $k => $v)
	{
		$tags = $v['tags'];
		$app = $tags['app'];
		$strip_app = stripAppName($app);
		$tags['level'] = appSLA($strip_app, $sla);
		$tags['contacts'] = appContacts($strip_app, $contacts);
		$tags['date'] = date("Y-m-d", strtotime("-1 day"));
		$time = strtotime(date("Y-m-d") . " 00:00:00");

		// 取value
		$fields = array("avail" => (float)$v['values'][0][1]);

		$point = new InfluxDB\Point($measurement, null, $tags, $fields, $time);
		array_push($points, $point);
	}
	return $points;
}

function writeAvailabilityData($database, $points, $retentionPolicy=null)
{	
	$result = $database->writePoints($points, InfluxDB\Database::PRECISION_SECONDS, $retentionPolicy);
	return $result;	
}

function main()
{
	global $config;
	$client = new InfluxDB\Client($config['influx']['host'], $config['influx']['port']);
	$database = $client->selectDB($config['influx']['db']);
	$sql = $config['influx']['query'];
	$orig = queryAvailabilityData($database, $sql);
	$measurement = $config['influx']['measurement'];

	$appInfo = getAppInfo();
	$sla = $appInfo['sla'];
	$contacts = $appInfo['contact'];
	$points = genAvailabilityPoints($measurement, $orig, $sla, $contacts);
	$retentionPolicy = $config['influx']['rp'];
	writeAvailabilityData($database, $points, $retentionPolicy);
	//emailReport($orig, $sla);
	print_r(emailReport($orig, $sla, $contacts));
}

main();
