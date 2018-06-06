<!DOCTYPE HTML>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link href="//cdn.bootcss.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet" media="screen">
<meta name="viewport" content="width=device-width, initial-scale=1.0,user-scalable=no">
<style type="text/css">
body { 
	width:90%; 
	margin:auto;
	font-size: large;
}
#content {
	box-shadow: 0 0 5px #BBB;
/*	height: 1000px;*/
	background: #F7F7F7;
	border: 1px solid #ccc; 
	padding: 30px 100px 60px 10px;
	margin-top: 50px;
}
#editor {
	width: 45%;
	float: left;
}
#preview {
	width: 45%;
	float: right;
}
#img {
	margin-top: 50px;
	margin-left: 50px;	
}
</style>
<title>对象关系图查询</title>
</head>
<body>
<div id="content">
<form action="link.php" method="GET" class="form-horizontal">
	<p>请选择对象类型: 
	<select name="type">
<?php
	$html = "";
	$options = array("rds" => "RDS",
					"app" => "APP",
					"domain" => "域名",
					"server" => "服务器",
					"ip" => "IP地址",
					"mongo" => "MongoDB",
					"redis" => "Redis",
					"rack" => "机架",
					"person" => "人员");
	$selected = "app";
	if(isset($_GET['type']))
	{
		$selected = $_GET['type'];
	}
	foreach($options as $k=>$v)
	{
		if($k == $selected)
		{
			$html .= '<option value="' . $k . '" selected="selected">' . $v . '</option>';
		}else {
			$html .= '<option value="' . $k . '">' . $v . '</option>';
		}
	}
	$html .= '</select>';
	print($html);
?>
	<p>
	<p>请输入对象名称: <input type="text" name="name" value="<?php if(isset($_GET['name'])) echo $_GET['name'];?>"/></p>
	<p>隐藏对象类型:
<?php
	$hides = array("Url"=>"Url",
		"BusinessProcess" => "业务线",
		"Team" => "团队",
		"Cluster" => "集群",
		"Person" => "人员",
		"Server" => "服务器",
		"Location" => "机房",
		"Rack" => "机柜",
		"MongoDB" => "MongoDB",
		"RDS" => "RDS",
		"Redis" => "Redis",
		"Domain" => "域名");
	$html = "";
	$checked = array("Url", "BusinessProcess", "Team");
	$newchecked = array();
	foreach($_GET as $k=>$v)
	{
		if(preg_match("/hide_.*/", $k))
		{
			$newchecked[] = $v;	
		}
	}
	if(count($newchecked)>0)
	{
		$checked = $newchecked;	
	}
	foreach($hides as $k => $v)
	{
		if(in_array($k, $checked)){
			$html .= '<label><input name="hide_' . $k . '" type="checkbox" value="' . $k . '" checked="checked"/>' . $v . "</label>\n";
		}else{
			$html .= '<label><input name="hide_' . $k . '" type="checkbox" value="' . $k . '"/>' . $v . "</label>\n";
		}
	}
	print($html);
?>
	</p>
	<input type="submit" value="Submit" />
</form>
<div id="img">
<?php
require '../etc/config.php';
require '../lib/core.function.php';
$api = $config['rooturl'] . "public.php";

if(!isset($_GET['type']) || !isset($_GET['name']))
{
	return;
}

$type = $_GET['type'];
$value = preg_replace("/\s+/s", ",", $_GET['name']);
$hide_arr = array();
foreach($_GET as $k => $v)
{
	if(preg_match("/hide_.*/", $k))
	{
		array_push($hide_arr, $v);
	}
}
$hide = implode(",", $hide_arr);

// 简单的方法隐藏除app外的所有类型
if(isset($_GET['hide']) and $_GET['hide'] == "all") {
	$hide = implode(",", array_keys($hides));
}


$url = $api . "?type=$type&value=$value&rankdir=LR&direction=both&hide=$hide&depth=20";
print('<p><a href="' . $url . '" target="_blank">API链接</a></p>');
$output = curlGet($url);
$ret = json_decode($output, true);
$imgurl = $ret['imgurl'];
if($ret['relations'])
{
	$ret = '<img style="width:100%;height:auto;" src="' . $imgurl . '" />';
	print($ret);
}else
{
	print("<h2>此对象无图像</h2>");
}
?>
</div>
</div>
</body>
</html>
