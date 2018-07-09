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

function sendMail() {
	email=$1
	user=`echo $email|cut -f1 -d'@'`
	password=$2
	gpgKey=$3
	tmpKeyfile="/tmp/gpgkey-$user"
	echo $gpgKey > $tmpKeyfile
	sed -i 's/#/\r\n/g' $tmpKeyfile
	keyId=`gpg --import $tmpKeyfile 2>&1|grep "<"|awk '{print $3}' |sed 's/：/:/g'|awk -F':' '{print $1}'`
	
	tmpPassFile="/tmp/passwdFile-$user"
	tmpEmail="/tmp/passwdMail-$user"
	echo $password >$tmpPassFile
	gpg --trust-model always --yes -a -r $keyId -o $tmpEmail -e $tmpPassFile

	content="请使用GnuPG解密您的密码<br><br><hr>`cat $tmpEmail`<br><br><hr>正常情况下1小时内生效，如超过一小时还未生效请联系运维<br>如果着急使用，请联系服务器管理员执行puppet同步命令: puppet agent --config /etc/puppet/letv.conf --onetime --verbose --no-daemonize"
	curl -s -XPOST "$MAIL_API" -d "tos=$email&cc=$CC_LIST&subject=服务器密码设置-$user&content=$content&format=html"
	#rm -f $tmpPassFile $tmpEmail
}

function genPwd() {
	echo "ss"
}

function resetPwd() {
	username=$1
	password=$2

}

function run() {
	queryResetQueue
	while read line;do
		pid=`echo $line |cut -f1 -d','`
		email=`echo $line |cut -f2 -d','`
		username=`echo $email |cut -f1 -d'@'`
		gpgkey=`echo $line |cut -f3 -d','`

		password=`genPwd`
		resetPwd "$username" "$password"
		sendMail "$email" "$password" "$gpgkey"
	done <$TmpQueue
}

run
