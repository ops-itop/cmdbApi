#!/usr/bin/php
<?
/**
 * Usage: 用于账号申请工单的创建任务，包含创建lnkUserToServer, 指派工单。action参数 ID=$this->ref$
 * File Name: accoutRequest_create.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-04-24 17:08:30
 **/

require dirname(__FILE__).'/../etc/config.php';

$ID = getenv("ID");
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";

$oql = "SELECT UserRequest AS t WHERE t.ref = '" . $ID . "'";
$data = $iTopAPI->coreGet("UserRequest", $oql, "functionalcis_list, caller_id, description");
$obj = json_decode($data, true)['objects'];

$spt = array(); // 统计函数执行时间

foreach($obj as $k => $request)
{
	$description = preg_replace('/<p>(.*?)<\/p>/s', '\\1', $request['fields']['description']);
	$params = json_decode($description, true);
	$caller_id = $request['fields']['caller_id'];
	$user_id = getUserId($caller_id);
	$servers = $request['fields']['functionalcis_list'];
	$sIds = getServerIds($servers);

	$t1 = microtime(true);
	$msg = CreateAccount($sIds, $user_id, $params);
	$t2 = microtime(true);
	$spt['CreateAccount'] = "CreateAccount:" . (string)($t2 - $t1);

	$t1 = microtime(true);
	$assign_msg = doAssign($request['key'], $params);
	$t2 = microtime(true);
	$spt['doAssign'] = "doAssign" . (string)($t2 - $t1);

	die($assign_msg);
	// 将执行结果写入工单公共日志
	$public_log = "账号(lnkUserToServer)添加结果(code 0为成功)<br>" . implode("<br>", $msg). 
		"<br>后台任务执行时间:<br>" . implode("<br>", $spt);
	$iTopAPI->coreUpdate("UserRequest", $request['key'], array("public_log" => $public_log), $config['comment']);
}

// lnkUserToServer需要user_id
function getUserId($caller_id)
{
	global $iTopAPI;
	$oql = "SELECT User WHERE contactid = $caller_id";
	$ret = json_decode($iTopAPI->coreGet("User", $oql, "contactid"), true)['objects'];
	foreach($ret as $k => $v)
	{
		return($v['key']);
	}
}

// 获取服务器id列表
function getServerIds($servers)
{
	$sIds = array();
	foreach($servers as $k => $v)
	{
		$sIds[] = $v['functionalci_id'];
	}
	return($sIds);
}

// 自动指派
function doAssign($tId, $params)
{
	global $spt;
	$agents = split("_", $params['admin']);
	$agent_id = (int)$agents[0];
	$isFailed = true;
	$msg = "";
	
	if($agent_id != 0)
	{
		$t1 = microtime(true);
		$oAssign = GetAssignInfo($agents);
		$t2 = microtime(true);
		$spt["GetAssignInfo"] = $t2 - $t1;
		if($oAssign)
		{
			$team_id = $oAssign['team_id'];
			$agent_id = $oAssign['agent_id'];
		}else 
		{
			$isFailed = true;
			$msg = "GetAssignInfo Failed; ";
		}
	}else  // 指派给运维
	{
		// 使用template-base中的配置
		$team_id = $params['ops_team_id'];
		$plan = $params['ops_oncall'];
		if(is_array($plan))
		{
			$week = date("W", time());
			$len = count($plan);
			$agent_id = $plan[$week%$len];
		}
	}
	// 执行指派
	if($agent_id && $team_id)
	{
		$asign = json_decode($iTopAPI->coreApply_stimulus('UserRequest', $tId, array(
					'team_id' => $team_id,
					'agent_id' => $agent_id
				),'ev_assign'),true);
		if($asign['code'] == 0)
		{
			$isFailed = false;
		}else
		{
			$msg = $msg . $asign['message'];
		}
	}
	
	// 自动指派失败
	if($isFailed)
	{
		$msg = "Auto Assign Failed:" .  $msg;
	}
	return($msg);
}

/**
 * 根据联系人所在组织的交付模式获取该交付模式的联系人（团队），判断该联系人的团队ID
 */
function GetAssignInfo($oIds)
{	
	global $iTopAPI;
	// 随机取一个联系人
	$oWinnerId = $oIds[array_rand($oIds, 1)];
	$oPerson = json_decode($iTopAPI->coreGet("Person", $oWinnerId, "org_id, team_list"), true)['objects'];
	foreach($oPerson as $k => $v)
	{
		$org_id = $v['fields']['org_id'];
	}
	$oOrg = json_decode($iTopAPI->coreGet("Organization", $org_id, 'deliverymodel_id'), true)['objects'];
	foreach($oOrg as $k => $v)
	{
		$deliverymodel_id = $v['fields']['deliverymodel_id'];
	}
	$oDeliveryModel = json_decode($iTopAPI->coreGet("DeliveryModel", $deliverymodel_id), true)['objects'];
	die(json_encode($oDeliveryModel));
	$oDeliveryModel = MetaModel::GetObject("DeliveryModel", $deliverymodel_id);
	
	// 用户所属组成的交付模式的contact列表
	$aim_team = $oDeliveryModel->Get("contacts_list")->ToArrayOfValues();
	$list_aim_team = array();
	foreach($aim_team as $v)
	{
		array_push($list_aim_team, $v['lnkDeliveryModelToContact.contact_id']);
	}
	
	// 用户的team列表
	$my_team = $oPerson->Get("team_list")->ToArrayOfValues();
	$list_my_team = array();
	foreach($my_team as $v)
	{
		array_push($list_my_team, $v['lnkPersonToTeam.team_id']);
	}
	
	$all_team = array_intersect($list_aim_team, $list_my_team);
	if(!$all_team)
	{
		return(false);
	}
	$team_id = $list_aim_team[array_rand($all_team, 1)];
	return(array('team_id'=>$team_id, 'agent_id' => $oWinnerId));
}

// 创建lnkUserToServer	
function CreateAccount($sIds, $user_id, $params)
{
	global $iTopAPI;	
	$sudo = $params['sudo'];
	$expiration = $params['type'];
	if($expiration == "permanent")
	{
		$expiration = "1970-01-01 08:00:00";
	} else
	{
		$day = (int)$params['expiration_day'];
		$expiration = time()+$day*24*60*60;
	}
	
	$msg = array();
	foreach($sIds as $k => $v)
	{
		$param = array("user_id"=>$user_id, "server_id"=>$v, "sudo"=>$sudo, "expiration"=>$expiration, "status"=>"disabled");
		$ret = $iTopAPI->coreUpdate("lnkUserToServer", "SELECT lnkUserToServer WHERE user_id = $user_id AND server_id = $v", $param);
		if(json_decode($ret, true)['code'] != 0)
		{
			$ret = $iTopAPI->coreCreate("lnkUserToServer", $param);
		}
		$ret = json_decode($ret, true);
		$msg[] = "serverId=" . $v . ":  code: " . $ret['code'] . ", message: " . $ret['message'];
	}
	return($msg);
}

file_put_contents($log, $config['datetime'] . " - $ID - " . json_encode($msg) . "\n", FILE_APPEND);
?>
