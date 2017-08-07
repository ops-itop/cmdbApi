# api.php

## 用途
iTop关闭或重开工单接口

参数列表

| 参数 | 说明 | 备注
| ---  | ---- | ---- |
| action   | close,reopen | |
| id | 工单id | |
| user_satisfaction | 1,2,3,4 (1 => 非常满意, 2 => 满意, 3 => 不满意, 4 => 非常不满意) | action为close时需要此参数 |
| user_comment | |action为close时需要此参数 |
| sign | 签名 |sha1(md5($action, $id, $sk)) |

## 返回值
|code |含义|
|---- |----- |
| 0 | 成功 |
| 100 | itop执行错误 |
| 101 | 参数错误,缺少action |
| 102 | 参数错误,缺少id, user_satisfaction或user_comment |
| 103 | 参数错误,缺少id |
| 110 | action类型错误 |
| 130 | 缺少sign或sign错误 |

## sign生成算法
`sha1(md5($action, $id, $sk))`

其中客户端SK需要和服务端 etc/config.php中的 `$config['api']['sk']` 保持一致
