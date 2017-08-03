# iTop API

包含以下功能
- web/ HTTP接口
- actions/ action-shell-exec调用的脚本

## 部署步骤
- 安装iTop插件 itop-extensions/action-shell-exec
- 安装action-shell-exec的文档配置触发器和动作
- 部署cmdbApi

## cmdbApi部署方式建议

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

在cmdb配置文件里做反向代理

```
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
