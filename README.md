# iTop API封装

二次封装iTop API便于调用

数据模型使用 https://github.com/annProg/itop-extensions

## 参数说明

| 参数 | 说明 |
| ---- | ---- |
|type | ip,app,server,url,domain,default等 (可以在config.php中定义config['map']来映射类型和对应的iTop类) |
|value |多个值用半角逗号分隔 |
|rankdir |dot图形方向, LR或者TB，默认TB |
|depth | relation深度,默认app为1, ip, default等为3 |
|direction | 关联方向，up(依赖),down(影响),both(依赖和影响),默认为down |
|show |控制relation显示的iTop类，逗号分隔|
|hide |控制relation隐藏的iTop类，逗号分隔|
