#!/bin/bash

############################
# Usage: 定期随机生成root密码
# File Name: rootpwd.sh
# Author: annhe  
# Mail: i@annhe.net
# Created Time: 2018-07-09 21:30:31
############################

# crontab需要设置PATH
export PATH=$PATH:/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

basedir=$(cd `dirname $0`; pwd)
cd $basedir
source ./conf.sh

ROOTAPI="${CMDB_API}root.php"

TmpQueue="/tmp/reset_rootpwd.txt"
TmpKeys="/tmp/reset_rootpwd_gpgkeys.txt"

function query() {
	curl -s -XPOST "$ROOTAPI" -d 'action=query' -o $TmpQueue
	echo >>$TmpQueue
}

function myip() {
	ip add |grep "inet 10\." |grep -v "/32" |head -n 1 |awk '{print $2}' |cut -f1 -d'/'	
}

function mysn() {
	sn=`sudo dmidecode -s system-serial-number |grep -v "^#"`
	uuid=`sudo dmidecode -s system-uuid |grep -v "^#"`
	echo $sn |grep -E " |-" &>/dev/null && assettag=$uuid || assettag=$sn
	echo $assettag
}

function importKeys() {
	cat $TmpQueue | sed '1,2'd > $TmpKeys
	keyId=""
	while read line;do
		keyId="$keyId,`echo "$line" |sed 's/#/\r\n/g' |gpg --import 2>&1|grep "<"|awk '{print $3}' |sed 's/：/:/g'|awk -F':' '{print $1}'`"
	done < $TmpKeys
	keyId=`echo $keyId|sed -r 's/^,//g'`
	echo $keyId
}

function update() {
	password=$1
	keyIds=`importKeys`
	recipient=""
	for id in `echo $keyIds |tr ',' ' '`;do
		recipient="-r $id $recipient"
	done
	content=`echo $password |gpg --homedir /root/.gnupg --trust-model always --yes -a $recipient -e|sed ':a;N;s/\n/<br>/g;ba'|sed 's/\+/%2B/g'`
	if [ "$content"x == ""x ];then
		content="加密失败，可能是您的GPG公钥有误，请确认在CMDB中填写了正确的GPG公钥"
	fi
	ip=`myip`
	sn=`mysn`
	dt=`date +%Y-%m-%d`
	upStat=`curl -s -XPOST "$ROOTAPI" -d "action=update&ip=$ip&sn=$sn&pwd=$content&date=$dt"`
	if [ "$upStat"x == "SUCC"x ];then
		echo "$password" |passwd --stdin root
	fi
	echo $upStat
	# cron中使用gpg加密需要设置--homedir
	#content=`echo -e "$content" |gpg --homedir /root/.gnupg --trust-model always --yes --local-user $LOCAL_USER -a -r $keyId --sign -e |sed ':a;N;s/\n/<br>/g;ba'|sed 's/\+/%2B/g'`
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

function checkSn() {
	sn=`mysn`
	grep "NotManagedServer" $TmpQueue |grep -w "$sn" &>/dev/null && r=1 || r=0
	if [ $r -eq 1 ];then
		echo "NotManaged"
		exit 1
	fi
}

function checkQuery() {
	status=`head -n 1 $TmpQueue`
	if [ "$status"x != "OK"x ];then
		echo "query error"
		exit 1
	fi
}

query
checkQuery
checkSn
rootpasswd=`genPwd`
update $rootpasswd
rm -f $TmpQueue
