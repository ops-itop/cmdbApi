#!/usr/bin/php
<?php

require dirname(__FILE__).'/../etc/config.php';
//die($iTopAPI->coreGet("UserRequest", 1605));
$service_details = reset(json_decode($iTopAPI->coreGet("UserRequest", 1537, "service_details"), true)['objects'])['fields']['service_details'];
$service_details['extradata_id'] = 0;
$service_details['user_data']['sudo'] = "需要";
$service_details['user_data']['request_template_ip_textarea'] = "10.135.28.97";
$fields = array(
	"title" => "测试api提交userrequest",
	"description" => "测试API提交UserRequest",
	"org_id" => 5,
	"caller_id" => 2,
	"origin" => "portal",
	"server_list" => array(array("server_id"=>813)),
	"service_id" => 10,
	"servicesubcategory_id" => 31,
	"service_details" => $service_details,
);
$data = $iTopAPI->coreCreate("UserRequest", $fields);

print_r($data);

