#!/bin/bash

############################
# Usage: 重置用户的服务器登录密码
# File Name: resetpwd.sh
# Author: annhe  
# Mail: i@annhe.net
# Created Time: 2018-07-09 21:30:31
############################

basedir=$(cd `dirname $0`; pwd)
cd $basedir
source ./conf.sh

TmpQueue="/tmp/reset_queue.txt"

function queryResetQueue() {
	curl -s "$CMDB_RESETPWDAPI?action=query" -o $TmpQueue
	echo >>$TmpQueue
}

function updateCmdb() {
	curl -s "$CMDB_RESETPWDAPI?action=update&id=$1"
}

function sendMail() {
	email=$1
	user=`echo $email|cut -f1 -d'@'`
	password=$2
	gpgKey=$3
	keyId=`echo "$gpgKey" |sed 's/#/\r\n/g' |gpg --import 2>&1|grep "<"|awk '{print $3}' |sed 's/：/:/g'|awk -F':' '{print $1}'`
	
	content="您登陆服务器的用户名为  $user；密码为  $password\r\n\r\n正常情况下1小时内生效，如超过一小时还未生效请联系运维\r\n\r\n如果着急使用，请联系服务器管理员执行puppet同步命令: puppet agent --config /etc/puppet/letv.conf --onetime --verbose --no-daemonize"
	# cron中使用gpg加密需要设置--homedir
	content=`echo -e "$content" |gpg --homedir /root/.gnupg --trust-model always --yes --local-user $LOCAL_USER -a -r $keyId --sign -e |sed ':a;N;s/\n/<br>/g;ba'|sed 's/\+/%2B/g'`

	if [ "$content"x == ""x ];then
		content="加密失败，可能是您的GPG公钥有误，请确认在CMDB中填写了正确的GPG公钥"
	fi
	curl -s -XPOST "$MAIL_API" -d "tos=$email&cc=$CC_LIST&subject=服务器密码-$user-请使用您的gpg私钥解密后查看&content=$content&format=html"
}

function genPwd() {
	special[0]="#"
	special[1]="$"
	special[2]="&"
	special[3]="^"
	special[4]="!"
	special[5]="%"
	special[6]="@"
 
	index=0
	str=""
	for i in {a..z}; do arr[index]=$i; index=`expr ${index} + 1`; done
	for i in {A..Z}; do arr[index]=$i; index=`expr ${index} + 1`; done
	for i in {0..9}; do arr[index]=$i; index=`expr ${index} + 1`; done
	for i in ${special[@]}; do arr[index]=$i; index=`expr ${index} + 1`; done
	for i in {1..20}; do 
		((random=$RANDOM%$index))
		str="$str${arr[random]}"
	done
	echo $str
}

function resetPwd() {
	username=$1
	password=$2
	shadowPass=`python -c "import crypt,getpass;pw='$password';print(crypt.crypt(pw))"`
	grep -w "$username\":" $USER_PP &>/dev/null && r=1 || r=0
	if [ $r -eq 1 ];then
		sed -i "/$username\":/!b;n;n;c\\\t\t\tpassword => '$shadowPass';" $USER_PP
	else
		sed -i '/^}/d' $USER_PP
		cat >>$USER_PP <<EOF
	user {
		"$username":
			ensure => present, home => '/home/$username', managehome => true, groups => ['docker'],
			password => '$shadowPass';
	}
}
EOF
	fi
}

function run() {
	dt=`date "+%Y-%m-%d %H:%M:%S"`
	pid=`echo $line |cut -f1 -d','|cut -f2 -d'-'`
	email=`echo $line |cut -f2 -d','`
	username=`echo $email |cut -f1 -d'@'`
	gpgkey=`echo $line |cut -f3 -d','`
	
	[ "$email"x == ""x ] && return

	password=`genPwd`
	resetPwd "$username" "$password"

	echo -n "$dt $username "
	sendMail "$email" "$password" "$gpgkey"
	echo -n " "
	updateCmdb $pid
	echo
}

queryResetQueue
grep "QUERYOK" $TmpQueue &>/dev/null && r=1 || r=0
[ $r -eq 0 ] && echo "QUERY ERROR" && exit 1
while read line;do
	run "$line"
done <$TmpQueue

rm -f $TmpQueue
