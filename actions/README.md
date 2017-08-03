# 此目录为iTop插件action-shell-exec的脚本

action设置SCRIPT_NAME变量为要执行的php脚本，然后使用一个统一的shell脚本作为包裹脚本来异步调用php脚本. 另外，统一的shell脚本还需要其他统一的变量名(主要方便调试，例如在日志中输出工单id或者服务器主机名)，因此iTop设置action parameters时要使用`变量名=值`的方式定义变量，变量列表如下

| 变量名 | 变量值 |
| -----  | ------ |
| SCRIPT_NAME | example.php |
| ID | $this->id$(工单)|

注意 logs目录需要可写权限，最好将actions目录属主改为php运行账号

## action-shell-exec插件介绍
执行脚本的动作, 需要php开启shell_exec函数

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


## task对应触发器动作参考

### 动作
```
名称,类别,名称,描述,状态,Path,Parameters
ticket_robot,Script execution,ticket_robot,工单操作,生产中,/wwwroot/cmdbApi/actions/demo.sh,SCRIPT_NAME=ticket_robot.php ID=$this->id$
```

### ticket_robot
```
描述,目标类,状态,类别,过滤器
工单已解决,Ticket,resolved,触发器 (进入一个状态时),SELECT Ticket AS t WHERE t.finalclass IN ('UserRequest','Incident')
```
