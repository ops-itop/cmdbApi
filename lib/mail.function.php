<?php
/**
 * Usage:
 * File Name: mail.function.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-10-31 10:58:02
 **/


/**
 *  http_mail 函数，接口使用 https://github.com/iambocai/mailer 搭建
 */
function http_mail($api, $data)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $api);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
	$output = curl_exec($ch);
	curl_close($ch);
	return($output);
}
