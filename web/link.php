<html>
<head>
<title>对象关系图查询</title>
</head>
<body>
<form action="link.php" method="GET">
	<p>请选择对象类型: 
	<select name="type">
		<option value="rds">RDS</option>
		<option value="app">APP</option>
		<option value="domain">域名</option>
		<option value="server">服务器</option>
		<option value="ip">IP地址</option>
		<option value="mongo">MongoDB</option>
		<option value="redis">Redis</option>
		<option value="rack">机架</option>
		<option value="person">人员</option>
	</select>
	<p>
	<p>请输入对象名称: <input type="text" name="name"/></p>
	<p>隐藏对象类型: <label><input name="hide_0" type="checkbox" value="Url" checked="checked"/>Url</label>
	 <label><input name="hide_1" type="checkbox" value="BusinessProcess" checked="checked"/>业务线</label>
	 <label><input name="hide_2" type="checkbox" value="Team" checked="checked"/>团队</label>
	 <label><input name="hide_3" type="checkbox" value="Server"/>Server</label>
	 <label><input name="hide_4" type="checkbox" value="Rack"/>机架</label>
	 <label><input name="hide_5" type="checkbox" value="MongoDB"/>MongoDB</label>
	 <label><input name="hide_6" type="checkbox" value="RDS"/>RDS</label>
	 <label><input name="hide_7" type="checkbox" value="Redis"/>Redis</label>
	 <label><input name="hide_8" type="checkbox" value="Domain"/>域名</label>
	</p>
	<input type="submit" value="Submit" />
</form>
</body>
</html>
<?php
require '../etc/config.php';
$api = $config['rooturl'] . "public.php";

$type = $_GET['type'];
$value = $_GET['name'];
$hide_arr = array();
foreach($_GET as $k => $v)
{
	if(preg_match("/hide_.*/", $k))
	{
		array_push($hide_arr, $v);
	}
}
$hide = implode(",", $hide_arr);

$url = $api . "?type=$type&value=$value&rankdir=LR&direction=both&hide=$hide&depth=20";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HEADER, 0);
$output = curl_exec($ch);
curl_close($ch);

$imgurl = json_decode($output, true)['imgurl'];

$ret = '<img src="' . $imgurl . '" />';
print($ret);
