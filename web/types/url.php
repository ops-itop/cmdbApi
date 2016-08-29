<?php
/**
 * Usage:
 * File Name: default.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/
include_once 'app.php';

function typeUrl($iTopAPI, $value) 
{
	$output = "applicationsolution_name, third_email, third_phone";
	//$output = "friendlyname, email, phone";
	$query = "SELECT Url AS url WHERE url.name IN ('$value') AND url.status = 'production'";
	$data = $iTopAPI->coreGet("Url", $query, $output);
	$obj = $data['objects'];
	$app = array();
	$third_person = array();
	if(!$obj)
	{
		return($data);
	}

	foreach($obj as $k=>$v)
	{
		array_push($app, $v['fields']['applicationsolution_name']);
		$person = array(
			'fields' => array(
				'email' => $v['fields']['third_email'],
				'phone' => $v['fields']['third_phone']
			)
		);
		$third_person['Person_third_'.$k] = $person;
	}

	$app_str = implode("','", $app);
	$contact = typeApp($iTopAPI, $app_str);
	if(!$contact['objects'])
	{
		$contact['objects'] = array();
	}
	foreach($third_person as $k => $v)
	{
		$contact['objects'][$k] = $v;
	}
//	$query = "SELECT Person AS p JOIN lnkContactToApplicationSolution AS l ON l.contact_id=p.id " .
//	"WHERE l.applicationsolution_name IN ('$value') AND p.status='active' AND p.notify='yes'";
//	$data = $iTopAPI->coreGet("Person", $query, $output);

	return($contact);
}
