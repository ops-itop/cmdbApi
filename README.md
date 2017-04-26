# iTop API封装

二次封装iTop API便于调用

数据模型使用 https://github.com/annProg/itop-extensions

## 参数说明

| 参数 | 说明 |
| ---- | ---- |
|type | ip,app,server,url,domain,default等 (可以在config.php中定义config['map']来映射类型和对应的iTop类) |
|value |多个值用半角逗号分隔 |
|rankdir |dot图形方向, LR或者TB，默认TB |
|depth | relation深度,默认app为1, ip, default等为3 |
|direction | 关联方向，up(依赖),down(影响),both(依赖和影响),默认为down |
|show |控制relation显示的iTop类，逗号分隔|
|hide |控制relation隐藏的iTop类，逗号分隔|
|filter |控制objects显示的类型,逗号分隔|

## 示例

使用以下参数调用

```
public.php?type=ip&value=10.0.0.2&filter=Server&show=Server,Cluster,Rack,ApplicationSolution&direction=both&depth=2
```

查询`IP`为`10.0.0.2`的服务器的关联关系，并且objects只显示`Server`类，relations类显示`Server,Cluster,Rack,ApplicationSolution&direction`,并且同时显示此服务器的上下游关联，关联深度只显示2级，返回类似如下结果

```
{
  "relations": {
    "Server::2::op.node.22": [
      {
        "key": "Cluster::3::op1"
      },
      {
        "key": "ApplicationSolution::54::op.appname"
      }
    ],
    "Cluster::3::op1": [
      {
        "key": "ApplicationSolution::53::op.monitor"
      }
    ],
    "Rack::11::土城4F.M1": [
      {
        "key": "Server::2::op.node.22"
      }
    ]
  },
  "objects": {
    "Server::2": {
      "code": 0,
      "message": "",
      "class": "Server",
      "key": "2",
      "fields": {
        "id": "2",
        "friendlyname": "op.node.22"
      }
    }
  },
  "code": 0,
  "message": "Scope: 1; Related objects: Server= 1",
  "imgurl": "http://cmdb.cn/chart/api.php?cht=gv:dot&chl=digraph+G..."
}
```

图片显示如下

![](preview/preview.png)

## 部署方式建议

cmdbApi监听本地端口

```
server {
	listen      127.0.0.1:8090;
	access_log logs/cmdbapi.log main;
	root /opt/wwwroot/cmdb.xxx.cn/cmdbApi/web;

	include enable-php.conf;

	location / {
		index  index.html index.htm index.php;
	}

	location ~ /\.
	{
		deny all;
	}

}
```
cmdbApi画图依赖 [chart](https://github.com/annProg/chart)接口，此接口用于将dot源码转换为图片

```
server {
	listen      127.0.0.1:8091;
	access_log logs/cmdbchart.log main;
	root /opt/wwwroot/cmdb.xxx.cn/chart/;

	include enable-php.conf;

	location / {
		index  index.html index.htm index.php;
	}

	location ~ /\.
	{
		deny all;
	}

}
```
在cmdb配置文件里做反向代理

```
upstream graphviz-api {
	server 127.0.0.1:8091;
}

upstream cmdb-pubapi {
	server 127.0.0.1:8090;
}

server {
	listen      80;
	server_name  cmdb.xxx.cn;
	access_log logs/cmdb.xxx.cn.log main;
	root /opt/wwwroot/cmdb.xxx.cn/web;

	include enable-php.conf;

	location / {
		index  index.html index.htm index.php;
	}
	
	location /data/ {
		deny all;
	}
	
	location ^~ /api/ {
		proxy_pass http://cmdb-pubapi/;
		proxy_set_header     X-Forwarded-For $proxy_add_x_forwarded_for;
	}

	location ^~ /chart/ {
		proxy_pass http://graphviz-api/;
	}
	location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)$
	{
		expires      30d;
	}

	location ~ .*\.(js|css)?$
	{
		expires      12h;
	}

	location ~ /\.
	{
		deny all;
	}

}
```
