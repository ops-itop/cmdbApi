#!/usr/bin/php
<?php

require dirname(__FILE__).'/../etc/config.php';
$fields = array(
	"title" => "测试api提交Incident",
	"description" => "测试API提交Incident",
	"org_id" => 2,
	"caller_id" => 1,
	"functionalcis_list" => array(array("functionalci_name"=>"cmdb", "functionalci_id_finalclass_recall"=>"ApplicationSolution")),
);
#$data = $iTopAPI->coreCreate("Incident", $fields);

$data = $iTopAPI->coreGet("RequestTemplate", NULL);
print_r($data);

