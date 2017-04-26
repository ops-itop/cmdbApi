# accounts.php

## 用途
账号管理接口，查询某个IP的账号列表

参数列表

| 参数 | 说明 |
| ---  | ---- |
| ip   | IP地址 |
| cache | 可选. false: 不适用cache; set: 更新缓存 |

## 流程

### 触发更新缓存
账号管理工单审批完成，通过触发器调用 `cmdbApi/actions/tasks/update_account.php`, 此脚本中使用 `cache=set` 参数更新工单涉及IP的缓存

### 定时更新缓存
防止意外情况触发失败，使用 `cmdbApi/cron/update_accounts_cache.php` 来更新所有IP的缓存, 一秒一个，根据IP的数量决定执行频率

