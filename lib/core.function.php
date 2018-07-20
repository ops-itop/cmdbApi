<?php
/**
 * Usage:
 * File Name: default.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-06-24 12:52:34
 **/
function getNode($key, $strip=array())
{
	$arr = explode("::", $key);
	$image = "images/" . strtolower($arr[0]) . ".png";
	$name = $arr[0] . $arr[1];
	//$label = $arr[0] . "::" . $arr[2];
	$label = $arr[2];
	foreach($strip as $v)
	{
		$label = preg_replace($v, '\1', $label);
	}
	$shape = "none";
	$labelloc = "b";
	//$node = $name . '[label="' . $label . '", shape=' . $shape . ', image="' . $image . '", labelloc=' . $labelloc . ']';
	$label = '<<table border="0" cellborder="0">' . 
		'<tr><td><img src="' . $image . '"/></td></tr>' . 
		'<tr><td>' . $label . '</td></tr></table>>';
	$node = $name . '[label=' . $label . ', shape=' . $shape . ']';
	return $node;
}

function getEdge($src, $dest)
{
	$src_arr = explode("::", $src);
	$src_name = $src_arr[0] . $src_arr[1];
	$dest_arr = explode("::", $dest);
	$dest_name = $dest_arr[0] . $dest_arr[1];
	$edge = $src_name . "->" . $dest_name;
	return $edge;
}

function _getDot($nodes, $edges, $rankdir="TB")
{
	if(!in_array($rankdir , array("TB", "LR")))
	{
		$rankdir = "TB";
	}
	$head = "digraph G{rankdir=" . $rankdir . ";";
	$nodes_str = implode(";", $nodes);
	$edges_str = implode(";", $edges);
	$tail = "}";
	return $head . $nodes_str . ";" . $edges_str . ";" . $tail;
}

function getDot($relations, $rankdir, $strip=array())
{
	$nodes = array();
	$edges = array();
	foreach($relations as $key => $value)
	{
		$node = getNode($key, $strip);
		if(!in_array($node, $nodes))
		{
			array_push($nodes, $node);
		}
		foreach($value as $v)
		{
			$node = getNode($v['key'], $strip);
			if(!in_array($node, $nodes))
			{
				array_push($nodes, $node);
			}
			$edge = getEdge($key, $v['key']);
			if(!in_array($edge, $edges))
			{
				array_push($edges, $edge);
			}
		}
	}
	$dot = _getDot($nodes, $edges, $rankdir);
	return($dot);
}

function curlPost($api, $data)
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

function curlGet($url) {
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
	$out = curl_exec($ch);
	curl_close($ch);
	return($out);
}

function getImgUrl($api, $dot, $post=10000)
{
	$dotlen = strlen($dot);
	if($dotlen < $post)
	{
		$imgurl = $api . "?cht=gv:dot&chl=" . urlencode($dot);
	}else
	{
		$data = array("cht" => "gv:dot", "chl"=>$dot);
		// 调用chart接口生成图片
		curlPost($api, $data);
		$imgurl = str_replace("api.php", "", $api) . "img/" . md5($dot) . "dot.png";
	}
	return($imgurl);
}

/**
 *  http_mail 函数，接口使用 https://github.com/iambocai/mailer 搭建
 */
function http_mail($api, $data)
{
	return(curlPost($api, $data));
}


/**
 * 设置缓存
 */
function setCache($key, $result, $ttl=CACHE_EXPIRATION)
{
	$m = new Memcached();
	$m->addServer(CACHE_HOST, CACHE_PORT);
	$expiration = time() + (int)$ttl;
	return($m->set($key, $result, $expiration));
}

/**
 * 获取缓存
 */
function getCache($key)
{
	$m = new Memcached();
	$m->addServer(CACHE_HOST, CACHE_PORT);
	return($m->get($key));
}


// 验证IP，只允许访问自己的数据, 
// 如果使用代理，需要配置 proxy_set_header     X-Forwarded-For $proxy_add_x_forwarded_for;
function checkIP($ip_para)
{
	$ip = getenv("HTTP_X_FORWARDED_FOR");
	if(!$ip)
	{
		$ip = $_SERVER["REMOTE_ADDR"];
	}
	if($ip_para == $ip)
	{
		return(true);
	}
	return(false);
}
