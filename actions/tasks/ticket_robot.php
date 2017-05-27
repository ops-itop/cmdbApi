#!/usr/bin/php
<?php
/**
 * Usage: 工单机器人，用于指派工单，创建工单申请的资源，根据工单状态更新资源状态等
 * File Name: ticket_robot.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-05-24 13:10:27
 **/

// 写日志
function writeLog($ret)
{
	global $ID;
	global $sClass;
	global $log;
	global $config;
	file_put_contents($log , $config['datetime'] . " - $ID - $sClass - $ret \n", FILE_APPEND);
}

/**
 * 资源申请工单创建资源对象
 * 要求：request template中Fields必须在对应类中存在，比如 name对应name，location对应location
 * 允许存在tips开头的Fields，该函数忽略tips开头的Fields
 * 工单创建时，新建对象，状态设置为实施，工单完成时，更改对象状态（上线或者废弃）
 */
function CreateObj($oClass, $Ticket)
{
	global $iTopAPI;
	global $sClass;
	global $type;
	global $user_data;
	if($oClass == "Server" || $sClass != "UserRequest" || $type != "new")
	{
		return;
	}
	$fields = array();
	$fields['org_id'] = $Ticket['fields']['org_id'];
	$fields['description'] = $Ticket['fields']['description'];
	$fields['status'] = "implementation";

	foreach($user_data as $k => $v)
	{
		if(preg_match("/^tips/", $k))
		{
			continue;
		}elseif($k == "applicationsolution_list")
		{
			$fields[$k] = array(array("applicationsolution_id"=>$v));
		}else
		{
			$fields[$k] = $v;
		}
	}
	// 如果是申请app上线的工单，app联系人设置为工单申请人
	if($oClass == "ApplicationSolution")
	{
		$fields['contact_list_custom'] = array(array("contact_id"=>$Ticket['fields']['caller_id']));
	}
	
	$oNew = json_decode($iTopAPI->coreCreate($oClass, $fields), true);
	$tFields = array();
	if($oNew['code'] == 0)
	{
		$oKey = reset($oNew['objects'])['key'];
		$tFields['functionalcis_list'] = array(array('functionalci_id' => $oKey));
		$tFields['public_log'] = "资源创建成功: " . reset($oNew['objects'])['fields']['friendlyname'];
	}else{
		$tFields['public_log'] = "资源创建异常: " . $oNew['message'];
	}

	$upTk = json_decode($iTopAPI->coreUpdate("UserRequest", $Ticket['key'], $tFields), true);
	return($upTk['code'] . ":" . $tFields['public_log']);
}

/** 
 * 更新Change类型的工单及配置项
 */
function UpdateChangeTicket($Ticket, $update=false)
{
	global $type;
	global $sClass;
	global $user_data;
	global $oClass;
	global $iTopAPI;
	if($type != "change" || $sClass != "UserRequest")
	{
		return;
	}
	
	$fields = array();
	foreach($user_data as $k => $v)
	{
		// 变更类型工单 name 一般是一个下拉列表，值为变更CI的ID
		if(preg_match("/^tips|name/", $k))
		{
			continue;
		}elseif($k == "applicationsolution_list")
		{
			$fields[$k] = array(array("applicationsolution_id"=>$v));
		}else
		{
			$fields[$k] = $v;
		}		
	}
	
	// 审核未通过的工单应该保持CI不变，只有正常完成的工单才更新
	// new[set ci implementation] -> assign[] -> resolve[] -> closed[set ci production; update changed fields]
	// new[set ci implementation] -> assign[] -> rejected[set ci production] -> closed[]
	// new[set ci implementation] -> assign[] -> rejected[set ci production] -> new[set ci implementation] -> assign[] -> ...
	$Id = $user_data['name'];
	$obj = json_decode($iTopAPI->coreGet($oClass, $Id, 'status'), true);
	$obj = reset($obj['objects']);
	$objStatus = $obj['fields']['status'];
	$status = $Ticket['fields']['status'];
	
	switch($status . "." . $objStatus)
	{
		case "new.production": 
			$ret = json_decode($iTopAPI->coreUpdate($oClass, $Id, array("status"=>"implementation")),true);break;
		case "closed.implementation":
			$fields['status'] = "production";
			$ret = json_decode($iTopAPI->coreUpdate($oClass, $Id, $fields),true);break;
		case "rejected.implementation":
			$ret = json_decode($iTopAPI->coreUpdate($oClass, $Id, array("status"=>"production")),true);break;
		default: $ret = array('code'=>100, 'message'=>'无需操作');break;
	}
	
	$msg = array();
	if($ret['code'] == 0)
	{
		$msg[] = "变更CI更新成功";
	}else
	{
		$msg[] = "变更CI未更新: " . $ret['message'];
	}
	
	// $update参数控制是否更新工单
	if($update)
	{
		$ret = json_decode($iTopAPI->coreUpdate($sClass, $Ticket['key'], array("functionalcis_list" => array(array("functionalci_id"=>$user_data['name'])))), true);
		if($ret['code'] != 0)
		{
			$msg[] = $ret['message'];
		}else
		{
			$msg[] = "变更工单链接CI成功";
		}
	}
	$msg = implode("  --  ", $msg);
	$iTopAPI->coreUpdate($sClass, $Ticket['key'], array("public_log"=>$msg));
	return($msg);
}
 
/**
 * 更新对象
 * 资源申请工单审批通过，对象状态设置为在线，审批失败，设置为废弃
 */
function UpdateObjStatus($Ticket)
{
	global $sClass;
	global $oClass;
	global $user_data;
	global $iTopAPI;
	$status = $Ticket['fields']['status'];
	$oCIs = $Ticket['fields']['functionalcis_list'];
	
	// 只有在工单状态变为new, closed或者rejected时才更新对象状态
	if($sClass != "UserRequest" || !in_array($status, array("new", "closed", "rejected")))
	{
		return;
	}
	$ret = array("code"=>100, "message"=>"empty FunctionalCI");
	foreach($oCIs as $key => $value)
	{
		// 如果是计算方式添加的配置项或者和模板关联类无关的对象，或者不是用户输入名称的对象，忽略
		if($value['impact_code'] == "computed" || $value['functionalci_id_finalclass_recall'] != $oClass || $value['functionalci_name'] != $user_data['name'])
		{
			continue;
		}
		$functionalci_id = $value['functionalci_id'];
		$data = json_decode($iTopAPI->coreGet($oClass, $functionalci_id, "status"), true);
		if($data['code'] != 0 || !$data['objects'])
		{
			continue;
		}
		$obj = reset($data['objects']);
		$objStatus = $obj['fields']['status'];
		switch($objStatus. "." . $status)
		{
			case "implementation.closed":
				$ret = json_decode($iTopAPI->coreUpdate($oClass, $functionalci_id, array("status"=>"production")),true);break;
			case "implementation.rejected":
				$ret = json_decode($iTopAPI->coreUpdate($oClass, $functionalci_id, array("status"=>"obsolete")),true);break;
			case "obsolete.new":
				$ret = json_decode($iTopAPI->coreUpdate($oClass, $functionalci_id, array("status"=>"implementation")),true);break;
			default: $ret = array('code'=>100, 'message'=>'UpdateObjStatus:无需操作');break;
		}
	}
	$msg = $ret['message'];
	if($ret['code'] == 0)
	{
		$msg = "UpdateObjStatus:关联对象状态更新成功";
	}
	return($msg);
}


/**
 * 创建事件之后，根据事件关联的APP自动更新事件的联系人
 */
function UpdateIncident($Ticket, $plan)
{
	global $iTopAPI;
	global $sClass;
	$contacts_list = array();
	foreach($plan as $k => $v)
	{
		$contacts_list[] = array('contact_id'=>$v);
	}
	$iTopAPI->coreUpdate($sClass, $Ticket['key'], array(
		"contacts_list" => $contacts_list
	));
}

/**
 * 获取事件工单指派信息
 * 根据联系人所在组织的交付模式获取该交付模式的联系人（团队），判断该联系人的团队ID
 */
function GetIncidentAssignInfo($Ticket, $allstaff)
{
	global $iTopAPI;
	$appId = "";
	$plan = array();
	foreach($Ticket['fields']['functionalcis_list'] as $k => $v)
	{
		if($v["functionalci_id_finalclass_recall"] == "ApplicationSolution" && $v["impact_code"] == "manual")
		{
			$appId = $v["functionalci_id"];
		}
	}
	if($appId)
	{
		$app = json_decode($iTopAPI->coreGet("ApplicationSolution", $appId, "contact_list_custom"), true);
		$contacts = reset($app['objects'])['fields']['contact_list_custom'];
		$personIds = array();
		foreach($contacts as $k => $v)
		{
			if($v['contact_id_finalclass_recall'] == "Person")
			{
				$personIds[] = $v['contact_id'];
			}
		}
		
		$query = "SELECT Person WHERE id IN (" . implode("','", $personIds) . ")";
		$persons = json_decode($iTopAPI->coreGet("Person", $query, "login,team_list"), true);
		
		if($persons['objects'])
		{
			// 检查person是否在$allstaff团队里，如果不在，则添加进去
			foreach($persons['objects'] as $k => $v)
			{
				$plan[] = $v['key'];
				$allteam = array();
				foreach($v['fields']['team_list'] as $id => $team)
				{
					$allteam[] = $team['team_id'];
				}
				if(!in_array($allstaff, $allteam))
				{
					$iTopAPI->coreCreate("lnkPersonToTeam", array(
						"person_id"=>$v['key'],
						"team_id"=>$allstaff
					));
				}
			}
			sort($plan);
		}
	}
	return($plan);
}

/**
 * 指派工单
 * 根据配置文件自动指派工单（排班）
 */
function AssignTicket($Ticket)
{
	global $iTopAPI;
	global $config;
	global $sClass;
	
	$servicesubcategory = $Ticket['fields']['servicesubcategory_name'];
	extract($config['ticket']);
	$team_id = $opsteam; // 默认分配给运维团队
	
	// 如果是事件工单，取关联app的联系人，按字典排序作为$plan，并使用allstaff团队
	if($sClass== "Incident")
	{
		$plan = GetIncidentAssignInfo($Ticket, $allstaff);
		$team_id = $allstaff;
		UpdateIncident($Ticket, $plan);
	}
	
	// get oAssign
	$agent_id = NULL;
	if(is_array($special) && array_key_exists($servicesubcategory, $special))
	{
		$agent_id = $special[$servicesubcategory];
	}elseif(is_array($plan))
	{
		$week = date("W", time());
		$len = count($plan);
		$agent_id = $plan[$week%$len];
	}

	// 自动指派
	if($agent_id && $team_id)
	{
		$ret = json_decode($iTopAPI->coreApply_stimulus($sClass, $Ticket['key'], array(
			'agent_id' => $agent_id,
			'team_id' => $team_id
		),'ev_assign'),true);
		if($ret['code'] == 0)
		{
			$msg = "自动指派成功: 指派给 " . reset($ret['objects'])['fields']['agent_id_friendlyname']; 
		}else
		{
			$msg = "自动指派失败: " . $ret['message'];
		}
	}else
	{
		$msg = "自动指派失败：agent_id 或者 team_id 异常";
	}

	$iTopAPI->coreUpdate($sClass, $Ticket['key'], array(
		"public_log" => $msg
	));
	return($msg);
}


require dirname(__FILE__).'/../etc/config.php';

$ID = getenv("ID");
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";

$data = json_decode($iTopAPI->coreGet("Ticket", $ID), true);

if($data['code'] != 0)
{
	$ret = $data['message'];
	$sClass = "Ticket";
	writeLog($ret);
	die();
}

$Ticket = reset($data['objects']);
$sClass = $Ticket['fields']['finalclass'];
$data = json_decode($iTopAPI->coreGet($sClass, $ID), true);

$ret = null;

if($data['code'] != 0 || !$data['objects'])
{
	$ret = $data['message'];
	writeLog($ret);
	die();
}

/** 
 * 这里需要取service_details, 2.3.3的rest api不支持AttributeCustom类型的Fields，需要打补丁
 * 补丁在 ../itop_restapi_2.3.3.patch
 */
$Ticket = reset($data['objects']);
$user_data = $Ticket['fields']['service_details']['user_data'];
$template_id = $Ticket['fields']['service_details']['template_id'];
$ticketStatus = $Ticket['fields']['status'];

// template_id为null时，接口不报错，而是返回全部模板，因此这里需要判断
if($template_id)
{
	$template = json_decode($iTopAPI->coreGet('RequestTemplate', $template_id), true);
	$template = reset($template['objects']);
	$oClass = $template['fields']['relatedclass'];
	$type = $template['fields']['type'];
}else
{
	$type = "no_template";
}

$ret = array();
switch($type . "." . $ticketStatus) {
	case "new.new": $ret[] = CreateObj($oClass, $Ticket); $ret[] = AssignTicket($Ticket);$ret[] = UpdateObjStatus($Ticket);break;
	case "new.closed": $ret[] = UpdateObjStatus($Ticket);break;
	case "new.rejected": $ret[] = UpdateObjStatus($Ticket);break;
	case "incident.new": $ret[] = AssignTicket($Ticket);break;
	case "change.new": $ret[] = AssignTicket($Ticket); $ret[] = UpdateChangeTicket($Ticket, true);break;
	case "change.closed": $ret[] = UpdateChangeTicket($Ticket);break;
	case "change.rejected": $ret[] = UpdateChangeTicket($Ticket);break;
	case "no_template.new": $ret[] = AssignTicket($Ticket);break;
	default: $ret[] = "Nothing to do"; break;
}

writeLog(implode(" - ", $ret));
