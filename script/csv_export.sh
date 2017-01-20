#!/bin/bash

############################
# Usage:导出带联系人的functionalci列表
# File Name: app_level.sh
# Author: annhe  
# Mail: i@annhe.net
# Created Time: 2017-01-13 18:03:31
############################

[ $# -lt 1 ] && echo "$0 list_file type"
list=$1
tp=$2

basedir=$(cd `dirname $0`; pwd)
cd $basedir
source ./conf.sh
show="ApplicationSolution,Person"

function csv()
{
	depth_data=`eval echo "$"$(echo "Depth_$tp")`
	depth=`echo $depth_data |cut -f1 -d','`
	show="$show,"`echo $depth_data |cut -f2 -d','`
	
	while read obj;do
		data=`curl -s "$CMDB_PUBAPI?type=$tp&value=$obj&show=$show&depth=$depth" | jq .relations`
		keys=`echo $data |jq keys[] |tr -d '"'`

		impact_app=""
		impact_person=""
		for item in $keys;do
			class=`echo $item |cut -f1 -d':' |sort -u`
			friendlyname=`echo $item |cut -f5 -d ':'`
			if [ $class = "ApplicationSolution" ];then
				impact_app="$friendlyname|"$impact_app
			fi
		done
		
		persons=`echo $data |jq .[][].key |tr -d '"' |sort -u`
		for item in $persons;do
			class=`echo $item |cut -f1 -d':'`
			friendlyname=`echo $item |cut -f5 -d':'`
			if [ $class = "Person" ];then
				impact_person="$friendlyname|"$impact_person
			fi
		done

		impact_app=`echo $impact_app |sed 's/|$//g'`
		impact_person=`echo $impact_person |sed 's/|$//g'`
		echo "$obj,$impact_app,$impact_person"
	done <$list
}

csv
