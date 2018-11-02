<?php
/**
 * Usage:
 * File Name:
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2018-11-02 23:15:07
 **/
function _getenv($key, $default="") {
	// 环境变量
	$val = getenv($key);
	if($val) {
		return $val;
	}
	// 找不到定义时使用默认值
	return $default;
}

function _getconfig($key, $default="") {
	global $config;
	// config变量优先级最高
	if(array_key_exists($key, $config)) {
		return $config[$key];
	}

	return _getenv($key, $default);
}

