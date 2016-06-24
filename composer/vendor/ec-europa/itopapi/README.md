# iTopApi PHP

iTopApi PHP is an helper class to use and query the [iTop](https://wiki.openitop.org/doku.php) API from your PHP scripts.



## Installation **(with composer)** :

```
composer require ec-europa/itopapi
```

## Usage

### Getting data

```php
require 'vendor/autoload.php';

$iTopAPI = new \iTopApi\iTopClient( 'http://localhost/itop', 'itopUser', 'iTopPassword' );

//disable SSL checks ?
//$iTopAPI->setCertificateCheck(false);

$query = sprintf("SELECT Servers WHERE environment = '%s'",'development');

$serversRequest = $iTopAPI->coreGet("Servers",$query);

$servers = $serverRequest['objects'];
```

### Creating data

```php
require 'vendor/autoload.php';

$iTopAPI = new \iTopApi\iTopClient( 'http://localhost/itop', 'itopUser', 'iTopPassword' );

//disable SSL checks ?
//$iTopAPI->setCertificateCheck(false);

$request = $iTopAPI->coreCreate("Servers",array(
  'hostname' => 'localhost',
  'memory' => 2048,
  'cpu' => 4,
  'location' => 'dc1'
));

```



### Updating data

```php
require 'vendor/autoload.php';

$iTopAPI = new \iTopApi\iTopClient( 'http://localhost/itop', 'itopUser', 'iTopPassword' );

//disable SSL checks ?
//$iTopAPI->setCertificateCheck(false);

$request = $iTopAPI->coreUpdate("Servers",array(
  'hostname' => 'localhost'
),array(
  'memory' => 1024
));

```

