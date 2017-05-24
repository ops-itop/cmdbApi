# 此目录为iTop插件action-shell-exec的脚本

action设置SCRIPT_NAME变量为要执行的php脚本，然后使用一个统一的shell脚本作为包裹脚本来异步调用php脚本. 另外，统一的shell脚本还需要其他统一的变量名(主要方便调试，例如在日志中输出工单id或者服务器主机名)，因此iTop设置action parameters时要使用`变量名=值`的方式定义变量，变量列表如下

| 变量名 | 变量值 |
| -----  | ------ |
| SCRIPT_NAME | example.php |
| ID | $this->ref$(工单), $this->friendlyname$(FunctinalCI) |
| TITLE | $this->title$(工单), $this->hostname$(FunctinalCI) |
| DEBUG | true or false, update_functionalci_contacts.php 支持此变量，用于延迟更新，防止缓存造成的影响, 当设置为true时，用于命令行手动更新时取消延迟 |

注意 logs目录需要可写权限，最好将actions目录属主改为php运行账号

## 插件介绍
执行脚本的动作, 需要php开启shell_exec函数

Fork from https://github.com/itop-itsm-ru/action-shell-exec

demo script(shell)

```
#!/bin/bash
d=`cd $(dirname $0);pwd`
cd $d
ds=`date +%Y%m%d-%H%M%S`

echo "$ds  $THIS_NAME - $THIS_HOSTNAME"
echo "$ds  $THIS_NAME - $THIS_HOSTNAME" >> demo.log
```

demo script(php). 需要读取环境变量

```
#!/usr/bin/php
<?
$THIS_HOSTNAME = getenv("THIS_HOSTNAME");
$THIS_NAME = getenv("THIS_NAME");
echo "$THIS_HOSTNAME $THIS_NAME";
?>
```

### 异步任务

假设脚本需要执行很长时间

```
#!/usr/bin/php
<?
$THIS_HOSTNAME = getenv("THIS_HOSTNAME");
$THIS_NAME = getenv("THIS_NAME");
$log = "php.log";
sleep(15);
file_put_contents($log, "$THIS_HOSTNAME $THIS_NAME\n", FILE_APPEND);
?>
```

如上代码，实测此时itop前端要等待15至16秒之间，因此考虑用shell脚本包裹一下，实现后台异步执行真正的任务

```
#!/bin/bash
d=`cd $(dirname $0);pwd`
cd $d
ds=`date +%Y%m%d-%H%M%S`

echo "$ds  $THIS_NAME - $THIS_HOSTNAME"
echo "$ds  $THIS_NAME - $THIS_HOSTNAME" >> demo.log
#./demo.php &   # 这种做法无效，需要下一行那样(启动子脚本，必须要使用&和指定输出(只好是定向到/dev/null)
./demo.php &>/dev/null  &
```

### 自定义变量
对插件做了修改，支持自定义变量，例如定义 `SCRIPT_NAME=demo.php`，然后在shell脚本中调用 `./$SCRIPT_NAME &>/dev/null &`，这样做可以避免为每一个异步php脚本重复写shell包裹脚本.

注意，变量定义时不要带引号，插件会自动加上引号。

