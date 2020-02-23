# asteriskWatch
asteriskWatch is an library for easily get current full asterisk extension status.

## About

This library processes events of Newchannel, Hangup, Newstate, ExtensionStatus and Dial. Collects and keeps the status by each extension. Is able to process redirect, group calls and conferences.

## Requires

PHP 5.3 or Higher
A POSIX compatible operating system (Linux, OSX, BSD)

## Installation

```
composer require viras777/asteriskWatch
```

## Basic Usage

For example get asterisk status and send it to the RabbitMQ

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

use asteriskWatch\asteriskWatch;

$amqpConnection = new AMQPStreamConnection($rmq_host, $rmq_port, $rmq_user, $rmq_pass, $rmq_vhost);
$channel = $amqpConnection->channel();
$channel->exchange_declare('_sys_phoneEvent', 'direct', false, true, false);

if (false === ($server = new asteriskWatch($asterisk_host, $asterisk_port, $asterisk_user, $asterisk_pass))) {
	echo "Can not connect to the asterisk\n";
	return;
}
if (getenv('asteriskWatch_debug') != '') {
	switch (getenv('asteriskWatch_debug')) {
		case asteriskWatch::logInfo:
			$server->Debug = asteriskWatch::logInfo;
			break;
		case asteriskWatch::logDebug:
			$server->Debug = asteriskWatch::logDebug;
			break;
		case asteriskWatch::logTrace:
			$server->Debug = asteriskWatch::logTrace;
			break;
	}
}

$server->setExtenList(array(100, 101, 102));

$endCallStatus = array( 
	'ANSWER'		=> 0,
	'BUSY'			=> 1,
	'NOANSWER'		=> 2
	'CANCEL'		=> 3,
	'CONGESTION'	=> 4,
	'CHANUNAVAIL'	=> 5,
	'DONTCALL'		=> 6,
	'TORTURE'		=> 7,
	'INVALIDARGS'	=> 8,
	'VOICEMAIL'		=> 9,
	'REDIREND'		=> 10
);
$direction = array('in' => 'true', 'out' => 'false');

$saveCDR = function ($event) {
	global $endCallStatus, $direction;
	
	$pgsql = new db($USER, $PASS);
	$pgsql->prepare_sql('INSERT INTO phoneEventCDR (
					fromNum,
					toNum, 
					direction, 
					endCallStatus, 
					callTimeFrom, 
					talkTimeFrom,
					callTimeTo, 
					answeredExten, 
					exten, 
					lineNum, 
					secondSideExten, 
					secondSideLineNum) 
				VALUES (
					?, 
					?, 
					?::boolean, 
					?, 
					to_timestamp(?), 
					to_timestamp(?), 
					to_timestamp(?), 
					?, 
					?, 
					?, 
					?, 
					?)', 
			array($event['From'], 
					$event['To'], 
					$direction[$event['Direction']],
					$endCallStatus[$event['EndCallStatus']], 
					$event['CallTimeFrom'], 
					($event['TalkTimeFrom'] != '' ? $event['TalkTimeFrom'] : NULL), 
					$event['CallTimeTo'],
					$event['AnsweredExten'], 
					$event['Exten'], 
					$event['LineNum'],
					$event['SecondSideExten'], 
					$event['SecondSideLineNum']), 'num');
	unset($pgsql);
};
$server->setFuncSaveCDR($saveCDR);

$sendDialEvent = function ($event) use ($channel) {
	$msg = new AMQPMessage(json_encode($event));
	$channel->basic_publish($msg, '_sys_phoneEvent', $event['Exten']);
};
$server->setFuncSendDialEvent($sendDialEvent);

$timeToReload = strtotime('now')+600;
$tick = function () use ($server) {
	global $timeToReload;

	if (strtotime('now') < $timeToReload) {
		return;
	}
	$server->setExtenList(array(100, 103));
	$timeToReload = strtotime('now')+60;
};
$server->setFuncTick($tick);

$server->watch();

```

## Example logs

```log
[2020-01-01 00:04:56] sendDialEvent ->
	'From' => '84951608738',
	'To' => '135',
	'Direction' => 'in',
	'CallTimeFrom' => '1582466696',
	'TalkTimeFrom' => '',
	'SecondSideExten' => '0',
	'SecondSideLineNum' => '0',
	'CallerIDName' => '4951608738',
	'Status' => '8',
	'StatusTxt' => 'Вызов',
	'UniqueID' => '1582466696.72081',
	'AnsweredExten' => '',
	'Exten' => '135',
	'LineNum' => '0'

[2020-01-01 00:05:14] saveCDR ->
    'From' => '84951608738',
    'To' => '135',
    'Direction' => 'in',
    'CallTimeFrom' => '1582466696',
    'TalkTimeFrom' => '1582466700',
    'SecondSideExten' => '0',
    'SecondSideLineNum' => '0',
    'CallerIDName' => '4951608738',
    'Status' => '1',
    'StatusTxt' => 'Разговаривает',
    'UniqueID' => '1582466696.72081',
    'AnsweredExten' => '135',
    'Exten' => '135',
    'LineNum' => '0',
    'CallTimeTo' => '1582466714',
    'EndCallStatus' => 'ANSWER'
```

## LICENSE

asteriskWatch is released under the [Apache 2.0 license](https://opensource.org/licenses/Apache-2.0).
