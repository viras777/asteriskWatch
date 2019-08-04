# asteriskWatch
asteriskWatch is an library for easily get current full asterisk extension status.

## Requires

PHP 5.3 or Higher
A POSIX compatible operating system (Linux, OSX, BSD)

## Installation

```
composer require viras777/asteriskWatch
```

## Basic Usage

```php
<?php
require_once __DIR__ . '/asteriskWatch/asteriskWatch.php';
use asteriskWatch\asteriskWatch;

// Create a listen server
if (false === ($server = new asteriskWatch('127.0.0.1', '5038', 'user', 'pass'))) {
    return;
}

// Info debug
$server->Debug = asteriskWatch::logInfo;

// Set the interesting extension
$server->setExtenList(array(118, 119, 230));

// Run server
$server->watch();
```

## LICENSE

asteriskWatch is released under the [Apache 2.0 license](https://opensource.org/licenses/Apache-2.0).
