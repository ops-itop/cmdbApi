#!/bin/bash

############################
# Usage:
# File Name: pam_account.sh
# Created Time: 2017-03-29 09:59:55
############################

# 2018.6.14更新 弃用pam方式，改为profile.d下脚本判断用户是否有权限
# crontab需要设置PATH
export PATH=$PATH:/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

CMDBAPI="http://cmdb.cn/api/accounts.php"
CONF="/etc/xxx_sshduser"
SUDO="/etc/sudoers.d/cmdb"
SCRIPT="/etc/profile.d/z_xxx_cmdb_login_control.sh"
IPADDR=`ip add |grep -E "10\." |grep -v "/32" |head -n 1 |awk '{print $2}' |awk -F'/' '{print $1}'`

# 随机等待一段时间，防止cmdbapi请求量过大
if [ $# -lt 1 ];then
	waitS=$((RANDOM%50))
	sleep $waitS
fi

# 接口返回说明
# Permission Deny : 无权限查询，直接退出
# ERROR: 错误，直接退出
# NOT FOUND: 未找到 直接退出

# 非root退出
if [ `id -u` -ne 0 ];then
	echo "must run as root"
	exit 1
fi

function pam_off()
{
	[ -f $SCRIPT ] && mv -f $SCRIPT /tmp/
}

function pam_on()
{
	cat >$SCRIPT <<EOF
#!/bin/bash
# 这里用who am i，su到其他用户时，who am i显示的用户不变，whoami会变，切换到一些系统用户,比如nginx, zabbix时会受限
u=\`who am i|awk '{print \$1}'\`
grep -w "^\$u$" $CONF &>/dev/null && r=1 || r=0
if [ \$r -eq 0 ];then
	echo -e "\033[47;5;31m\n     用户 \$u 没有权限登录当前机器\033[0m"
	# Signal 2 is Ctrl+C，禁用ctrl+c，否则sleep时用户可以通过ctrl+c进入系统
	trap '' 2
	# 等5秒 否则通过堡垒机登录服务器的用户看不到提示信息
	sleep 5
	exit
fi
EOF
	# mpaas_node机器不启用登录限制	
	hostname |grep -E "\.mpaas_node\." &>/dev/null && pam_off

	# 管理员账号
	cat > $CONF <<EOF
root
ansible
EOF
	
	# 管理员账号
	cat > $SUDO <<EOF
ansible        ALL=(ALL) 	NOPASSWD: ALL
EOF

	for u in `echo $1 | tr ',' ' '`;do
		echo "$u" >> $CONF
	done
	
	for s in `echo $2 |tr ',' ' '`;do
		echo "$s          ALL=(ALL)    NOPASSWD: ALL" >> $SUDO
	done
}

userlist=`curl --connect-timeout 3 -s "$CMDBAPI?ip=$IPADDR" |head -n 1`

users=`echo "$userlist" |cut -f1 -d'|'`
sudoers=`echo "$userlist" |cut -f2 -d'|'`

echo $userlist
case $users in
	"ERROR") exit 1;;
	"NOT FOUND") exit 1;;
	"Permission denied") exit 1;;
	*) pam_on "$users" "$sudoers";;
esac
