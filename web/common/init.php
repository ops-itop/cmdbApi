<?php
/**
 * Usage:
 * File Name: account.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-03-29 10:05:48
 **/

require dirname(__FILE__) . '/../../etc/config.php';
require dirname(__FILE__) . '/../../composer/vendor/autoload.php';

define('ITOPURL', $config['itop']['url']);
define('ITOPUSER', $config['itop']['user']);
define('ITOPPWD', $config['itop']['password']);

$iTopAPI = new \iTopApi\iTopClient(ITOPURL, ITOPUSER, ITOPPWD, $version='1.2');
