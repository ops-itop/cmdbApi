<?php
/**
 * Usage:
 * File Name: default.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/

function typeApp($iTopAPI, $value) 
{
	$output = "friendlyname, email, phone";
	$query = "SELECT Person AS p JOIN lnkContactToApplicationSolution AS l ON l.contact_id=p.id " .
		"WHERE l.applicationsolution_name IN ('$value') AND p.status='active' AND p.notify='yes'";
	$data = $iTopAPI->coreGet("Person", $query, $output);

	return($data);
}
