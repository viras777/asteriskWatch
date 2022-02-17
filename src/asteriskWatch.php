<?php
/**
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author    Sergey<viras@yandex.ru>
 * @copyright Sergey<viras@yandex.ru>
 * @license   https://opensource.org/licenses/Apache-2.0
 */
namespace asteriskWatch;

/**
 * asteriskWatch class
 */
class asteriskWatch
{
	/**
	 * Version.
	 *
	 * @var string
	 */
	const VERSION = '1.6.2';
	
	/**
	 * No logging.
	 */
	public const logNone = 0;
	/**
	 * Log only sendDial event.
	 */
	public const logInfo = 1;
	/**
	 * Log sendDial, internal status
	 */
	public const logDebug = 2;
	/**
	 * Log sendDial, internal status, trace variables
	 */
	public const logTrace = 3;

	public const timeoutSec = 1;
	public const timeoutUsec = 0;
	
	# -1 = Екстеншен не найден
	# 0 = Idle
	# 1 = Используется (In Use)
	# 2 = Занят (Busy)
	# 4 = Не доступен (Unavailable)
	# 8 = Звонит (Ringing)
	# 16 = On Hold
	#
	# new status:
	# 32 = In Use on Redirection
	# 64 = Ringing to Redirection
	# 128 = Busy in Redirection
	#
	public const extenStatusNaN = -1;
	public const extenStatusIdle = 0;
	public const extenStatusInUse = 1;
	public const extenStatusBusy = 2;
	public const extenStatusUnavailable = 4;
	public const extenStatusRinging = 8;
	public const extenStatusOnHold = 16;
	public const extenStatusInUseRedir = 32;
	public const extenStatusRingingRedir = 64;
	public const extenStatusBusyRedir = 128;
	public const extenStatusMaskStd = 31;

	public const StatusTxt = [
		self::extenStatusNaN => 'Не определено',
		self::extenStatusIdle => 'Свободно',
		self::extenStatusInUse => 'Разговаривает',
		self::extenStatusBusy => 'Занято',
		self::extenStatusUnavailable => 'Недоступно',
		self::extenStatusRinging => 'Вызов',
		self::extenStatusOnHold => 'На удержании',
		self::extenStatusInUseRedir => 'Разговаривает (переадресация)',
		self::extenStatusRingingRedir => 'Вызов (переадресация)',
		self::extenStatusBusyRedir => 'Занято (переадресация)'
		];
		
	# ChannelState
	#0	Down		Channel is down and available.
	#1	Rsrvd		Channel is down, but reserved.
	#2	OffHook		Channel is off hook.
	#3	Dialing		The channel is in the midst of a dialing operation.
	#4	Ring		The channel is ringing.
	#5	Ringing		The remote endpoint is ringing. Note that for many channel technologies, this is the same as Ring.
	#6	Up			A communication path is established between the endpoint and Asterisk.
	#7	Busy		A busy indication has occurred on the channel.
	#8	Dialing		Offhook	Digits (or equivalent) have been dialed while offhook.
	#9	Pre-ring	The channel technology has detected an incoming call and is waiting for a ringing indication.
	#10	Unknown		The channel is an unknown state.
	#
	# Try decode ChannelState to Status
	private const ChannelState = [0 => 0, 1 => 0, 2 => 4, 3 => 8, 4 => 8, 5 => 8, 6 => 1, 7 => 2, 8 => 8, 9 => 0, 10 => -1];
	public $Debug = self::logNone;

	#  Redir Status
	# AddRedir		- добавили будущий редирект к номеру
	# RingRedir		- зазвонил редирект

	# DialStatus
	public const DialStatusTxt = [
		'ANSWER' => 'Вызов отвечен',
		'BUSY' => 'Номер занят',
		'NOANSWER' => 'На вызов не ответили',
		'CANCEL' => 'Вызов отменен',
		'CONGESTION' => 'Канал перегружен',
		'CHANUNAVAIL' => 'Канал недоступен',
		'DONTCALL' => 'Вызов отклонён',
		'TORTURE' => 'Голосовое меню',
		'INVALIDARGS' => 'Неправильный номер',
		'VOICEMAIL' => 'Голосовая почта',
		'REDIREND' => 'Переадресации завершена'
		];
		
	# File pointer to asterisk socket
	private $fp = false;

	private $host;
	private $port;
	private $user;
	private $pass;

	# Session action id for the AMI
	private $actionID;

	# Answer from AMI
	private $answer = [];

	# List of the monitoring exten
	private $extenList = [];

	# Assoc array: [ChannelID] = UniqueID
	private $extenListChannelID = [];

	# Assoc array: [UniqueID] = MainExten, MainLineNum
	private $extenListCallID = [];

	# Assoc array: [dialing number] = array of properties:
	#	[MainExten] =>,
	#	[UniqueID] =>,
	#	[Status] =>
	private $extenListRedirID = [];

	# Assoc array: [UniqueID] = From, To
	private $extenListCheckBusy = [];

	# magic const for cleanExten, temp lineNum
	private const magicLineNum = -1;
	
	# Array of initial exten
	private $initExten = [];

	# Callable function array
	private $callbackFunc = [];
	
	public function __construct($host = '127.0.0.1', $port = 5038, $user = '', $pass = '')
	{
		$this->host = $host;
		$this->port = $port;
		$this->user = $user;
		$this->pass = $pass;
	}

	public function setExtenList($newSet) {
		foreach(array_diff($newSet, $this->initExten) as $exten) {
			$this->log("Add exten: {$exten}".PHP_EOL, self::logDebug);
			$this->initExten[] = $exten;
			$this->cleanExten($exten, 0);
		}
		foreach(array_diff($this->initExten, $newSet) as $index => $exten) {
			$this->log("Del exten: {$index}:{$exten}".PHP_EOL, self::logDebug);
			unset($this->initExten[$index]);
			if (isset($this->extenList[$exten])) {
				unset($this->extenList[$exten]);
			}
			fclose($this->fp);
		}
	}

	public function setFuncSendDialEvent($func) {
        if (!is_callable($func)) {
            $this->log(new Exception("function is not callable (sendDialEvent)".PHP_EOL), self::logInfo);
            return false;
        }
		$this->callbackFunc['sendDialEvent'] = $func;
	}
	
	public function setFuncSaveCDR($func) {
        if (!is_callable($func)) {
            $this->log(new Exception("function is not callable (saveCDR)".PHP_EOL), self::logInfo);
            return false;
        }
		$this->callbackFunc['saveCDR'] = $func;
	}
	
	public function setFuncTick($func) {
        if (!is_callable($func)) {
            $this->log(new Exception("function is not callable (tick)".PHP_EOL), self::logInfo);
            return false;
        }
		$this->callbackFunc['tick'] = $func;
	}

	public function setFuncGetAllExtenStatus($func) {
        if (!is_callable($func)) {
            $this->log(new Exception("function is not callable (getAllExtenStatus)".PHP_EOL), self::logInfo);
            return false;
        }
		$this->callbackFunc['getAllExtenStatus'] = $func;
	}

	public function setFuncGetAllLine0Status($func) {
        if (!is_callable($func)) {
            $this->log(new Exception("function is not callable (getAllLine0Status)".PHP_EOL), self::logInfo);
            return false;
        }
		$this->callbackFunc['getAllLine0Status'] = $func;
	}

	
	public function watch()
	{
		while (true) {
			if (false == $this->connect()) {
				break;
			}
			$this->log('Connect to asterisk - DONE', self::logInfo);
			$this->extenListChannelID = [];
			$this->extenListCallID = [];
			$this->extenListRedirID = [];
			$this->extenList = [];
			$this->extenListCheckBusy = [];
			# Get list of monitoring exten.
			foreach ($this->initExten as $exten) {
				$this->cleanExten($exten, 0);
			}
			$this->logStatus('Start main loop', self::logInfo);

			# Main loop
			while (is_resource($this->fp) && !feof($this->fp)) {
				$this->actionID = '';
				if ($this->getAsteriskBlock() === false) {
					break;
				}
				switch ($this->answer['Event'] ?? '') {
					case 'Newchannel':
						if ($this->answer['CallerIDNum'] == '' || $this->answer['Exten'] == ''
								|| isset($this->extenListCheckBusy[$this->answer['Uniqueid']])) {
							break;
						}
						$this->extenListCheckBusy[$this->answer['Uniqueid']]['From'] = $this->answer['CallerIDNum'];
						$this->extenListCheckBusy[$this->answer['Uniqueid']]['To'] = $this->answer['Exten'];
						break;
					case 'Hangup':
						# Завершение отслеживания звонка может быть тут(ivr), Newstate(busy) и Dial
						if (isset($this->extenListCheckBusy[$this->answer['Uniqueid']])) {
							unset($this->extenListCheckBusy[$this->answer['Uniqueid']]);
						}
						break;
					case 'Newstate':
						if (isset($this->extenListCheckBusy[$this->answer['Uniqueid']]) && self::extenStatusBusy == self::ChannelState[$this->answer['ChannelState']]) {
							$from = $this->extenListCheckBusy[$this->answer['Uniqueid']]['From'];
							$to = $this->extenListCheckBusy[$this->answer['Uniqueid']]['To'];
							if (isset($this->extenList[$from])) {
								$this->cleanExten($from, self::magicLineNum);
								$this->extenList[$from][self::magicLineNum]['Status'] = self::extenStatusBusy;
								$this->extenList[$from][self::magicLineNum]['StatusTxt'] = $this->getStatusTxt(self::extenStatusBusy);
								$this->extenList[$from][self::magicLineNum]['From'] = $from;
								$this->extenList[$from][self::magicLineNum]['To'] = $to;
								$this->extenList[$from][self::magicLineNum]['Direction'] = 'out';
								$this->extenList[$from][self::magicLineNum]['CallTimeFrom'] = time();
								if (isset($this->extenList[$to])) {
									$this->extenList[$from][self::magicLineNum]['SecondSideExten'] = $to;
								} else {
									$this->extenList[$from][self::magicLineNum]['SecondSideExten'] = 0;
								}
								$this->extenList[$from][self::magicLineNum]['SecondSideLineNum'] = 0;
								$this->extenList[$from][self::magicLineNum]['CallerIDName'] = $this->answer['CallerIDName'];
								$this->extenList[$from][self::magicLineNum]['UniqueID'] = $this->answer['Uniqueid'];
								$this->sendDialEvent(['Exten' => $from, 'LineNum' => self::magicLineNum,
													'CallTimeTo' => time(), 'EndCallStatus' => 'BUSY', 
													'EndCallStatusTxt' => self::DialStatusTxt['BUSY'] ]);
								$this->saveCDR(['Exten' => $from, 'LineNum' => self::magicLineNum,
												'CallTimeTo' => time(), 'EndCallStatus' => 'BUSY']);
								unset($this->extenList[$from][self::magicLineNum]);
							}
							if (isset($this->extenList[$to])) {
								$this->cleanExten($to, self::magicLineNum);
								$this->extenList[$to][self::magicLineNum]['Status'] = self::extenStatusBusy;
								$this->extenList[$to][self::magicLineNum]['StatusTxt'] = $this->getStatusTxt(self::extenStatusBusy);
								$this->extenList[$to][self::magicLineNum]['From'] = $from;
								$this->extenList[$to][self::magicLineNum]['To'] = $to;
								$this->extenList[$to][self::magicLineNum]['Direction'] = 'in';
								$this->extenList[$to][self::magicLineNum]['CallTimeFrom'] = time();
								if (isset($this->extenList[$from])) {
									$this->extenList[$to][self::magicLineNum]['SecondSideExten'] = $from;
								} else {
									$this->extenList[$to][self::magicLineNum]['SecondSideExten'] = 0;
								}
								$this->extenList[$to][self::magicLineNum]['SecondSideLineNum'] = 0;
								$this->extenList[$to][self::magicLineNum]['CallerIDName'] = $this->answer['CallerIDName'];
								$this->extenList[$to][self::magicLineNum]['UniqueID'] = $this->answer['Uniqueid'];
								$this->sendDialEvent(['Exten' => $to, 'LineNum' => self::magicLineNum,
													'CallTimeTo' => time(), 'EndCallStatus' => 'BUSY', 
													'EndCallStatusTxt' => self::DialStatusTxt['BUSY'] ]);
								if (!isset($this->extenList[$from])) {
									$this->saveCDR(['Exten' => $to, 'LineNum' => self::magicLineNum,
												'CallTimeTo' => time(), 'EndCallStatus' => 'BUSY']);
								}
								unset($this->extenList[$to][self::magicLineNum]);
							}
							unset($this->extenListCheckBusy[$this->answer['Uniqueid']]);
							break;
						}
						# break не нужен, т.к. продолжаем ниже
					case 'ExtensionStatus':
						$lineNum = 0;
						$uid = 0;
						if ('Newstate' == $this->answer['Event']) {
							# Тут мы смотрим статус того екстеншена, куда звонит целевой
							if (!isset($this->extenListCallID[$this->answer['Uniqueid']])) {
								break;
							}
							$status = self::ChannelState[$this->answer['ChannelState']];
							$channelID = $this->extractChannelID($this->answer['Channel']);
							if (!isset($this->extenListChannelID[$channelID]) || !isset($this->extenListCallID[$this->extenListChannelID[$channelID]])) {
								break;
							}
							$exten = $this->extractExten($this->answer['Channel']);
							if ($exten == false) {
								$exten = $this->extenListCallID[$this->extenListChannelID[$channelID]]['Exten'];
								$lineNum = $this->extenListCallID[$this->extenListChannelID[$channelID]]['LineNum'];
							} else {
								# Взять из события номер линии никак, найдём второй екстен, зайдём в него и там посмотрим SecondSideLineNum
								if ($exten == $this->extenListCallID[$this->extenListChannelID[$channelID]]['Exten']) {
									$lineNum = $this->extenListCallID[$this->extenListChannelID[$channelID]]['LineNum'];
								} elseif (isset($this->extenList[$this->extenListCallID[$this->extenListChannelID[$channelID]]['Exten']][$this->extenListCallID[$this->extenListChannelID[$channelID]]['LineNum']]['SecondSideExten'])
											&& '0' != $this->extenList[$this->extenListCallID[$this->extenListChannelID[$channelID]]['Exten']][$this->extenListCallID[$this->extenListChannelID[$channelID]]['LineNum']]['SecondSideExten']) {
									# Аналогично этому:
									#$_exten = $this->extenListCallID[$this->extenListChannelID[$channelID]]['Exten'];
									#$_lineNum = $this->extenListCallID[$this->extenListChannelID[$channelID]]['LineNum'];
									#$lineNum = $this->extenList[$_exten][$_lineNum]['SecondSideLineNum'];
									$lineNum = $this->extenList[$this->extenListCallID[$this->extenListChannelID[$channelID]]['Exten']][$this->extenListCallID[$this->extenListChannelID[$channelID]]['LineNum']]['SecondSideLineNum'];
								} else {
									break;
								}
							}
						} elseif ('ExtensionStatus' == $this->answer['Event']) {
							# Тут мы смотрим целевой екстеншен
							$exten = $this->answer['Exten'];
							$status = $this->answer['Status'];
							$channelID = false;
							if (!isset($this->extenListRedirID[$exten]) || 1 < count($this->extenListRedirID[$exten])) {
								# Если активно больше одной линии, то не определить куда писать статус
								break;
							}
							reset($this->extenListRedirID[$exten]);
							$lineNum = key($this->extenListRedirID[$exten]);
							$uid = $this->extenListRedirID[$exten][$lineNum]['UniqueID'];
						} else {
							break;
						}
						if (!isset($this->extenListRedirID[$exten][$lineNum])) {
							break;
						}
						$mainExten = $this->extenListRedirID[$exten][$lineNum]['MainExten'];
						$mainLineNum = $this->extenListRedirID[$exten][$lineNum]['MainExtenLineNum'];
						# Заранее предположим кто ответил
						if (isset($this->answer['ConnectedLineNum'])) {
							$answeredExten = $this->extractExten($this->answer['ConnectedLineNum']);
							if ($answeredExten === false) {
								$answeredExten = $exten;
							}
						} else {
							$answeredExten = $exten;
						}
						if (isset($this->extenList[$mainExten][$mainLineNum])) {
							if ($this->extenList[$mainExten][$mainLineNum]['From'] == $answeredExten) {
								$answeredExten = $this->extenList[$mainExten][$mainLineNum]['To'];
							}
						}
						$this->log("Newstate01: exten={$exten} lineNum={$lineNum} status={$status} channelID={$channelID} mainExten={$mainExten} mainLineNum={$mainLineNum} uid={$uid} answeredExten={$answeredExten}".PHP_EOL, self::logTrace);
						# Если основной exten уже в этом статусе, то только меняем статус текущего exten
						if (isset($this->extenList[$exten][$lineNum]) && isset($this->extenList[$mainExten][$mainLineNum]) 
								&& ($this->extenList[$mainExten][$mainLineNum]['Status'] & self::extenStatusMaskStd) == $status 
								&& $this->extenList[$mainExten][$mainLineNum]['To'] == $exten) {
							$this->extenListRedirID[$exten][$lineNum]['Status'] = $status;
							# Обновим статус у связанных exten
							if ($channelID !== false) {
								$uid = $this->extenListChannelID[$channelID];
								foreach ($this->extenListRedirID as $to => $properties) {
									foreach ($properties as $line => $lineProperties) {
										if ($lineProperties['UniqueID'] == $uid) {
											$this->extenListRedirID[$to][$line]['Status'] = $status;
										}
									}
								}
							}
							$this->logStatus('Changed exten status, main exten status already has same status. Exten='.$exten.' lineNum='.$lineNum.' mainExten='.$mainExten.' mainLineNum='.$mainLineNum, self::logDebug);
							break;
						}
						# Если касается основного соединения. Если статус уже или стал extenStatusIdle, то меняем НЕ тут, а в евенте Dial
						if ((isset($this->extenList[$exten][$lineNum]) && $exten == $mainExten && $this->extenList[$mainExten][$mainLineNum]['To'] == $exten)
								&& (self::extenStatusIdle != $this->extenList[$mainExten][$mainLineNum]['Status'] && self::extenStatusIdle != $status)) {
							if (($this->extenList[$mainExten][$mainLineNum]['Status'] & self::extenStatusMaskStd) != $status) {
								if ($this->extenList[$mainExten][$mainLineNum]['To'] == $exten) {
									$newStatus = $status;
								} else {
									# Новый статус в стандартных битах только, остальные сохраним как было, 
									# за исключением комбинации редиректа с InUse, Busy, Ringing
									if (($this->extenList[$mainExten][$mainLineNum]['Status'] & (self::extenStatusInUse | self::extenStatusInUseRedir)) ==
											(self::extenStatusInUse | self::extenStatusInUseRedir) ||
										($this->extenList[$mainExten][$mainLineNum]['Status'] & (self::extenStatusBusy | self::extenStatusBusyRedir)) ==
											(self::extenStatusBusy | self::extenStatusBusyRedir) ||
										($this->extenList[$mainExten][$mainLineNum]['Status'] & (self::extenStatusRinging | self::extenStatusRingingRedir)) ==
											(self::extenStatusRinging | self::extenStatusRingingRedir)) {
										if ($status == self::extenStatusInUse) {
											$newStatus = (self::extenStatusInUse | self::extenStatusInUseRedir);
										} elseif ($status == self::extenStatusBusy) {
											$newStatus = (self::extenStatusBusy | self::extenStatusBusyRedir);
										} elseif ($status == self::extenStatusRinging) {
											$newStatus = (self::extenStatusRinging | self::extenStatusRingingRedir);
										} else {
											$newStatus = $status | ((self::extenStatusMaskStd ^ 255) & $this->extenList[$mainExten][$mainLineNum]['Status']);
										}
									} else {
										$newStatus = $status | ((self::extenStatusMaskStd ^ 255) & $this->extenList[$mainExten][$mainLineNum]['Status']);
									}
								}
								if ('' == $this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] && self::extenStatusInUse == ($newStatus & self::extenStatusMaskStd)) {
									$this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] = time();
									$this->extenList[$mainExten][$mainLineNum]['AnsweredExten'] = $answeredExten;
								}
								$this->extenListRedirID[$exten][$lineNum]['Status'] = $newStatus;
								$this->extenListRedirID[$mainExten][$mainLineNum]['Status'] = $newStatus;
								$this->extenList[$mainExten][$mainLineNum]['Status'] = $newStatus;
								$this->extenList[$mainExten][$mainLineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
								if (isset($this->extenList[$exten][$lineNum])) {
									$this->extenList[$exten][$lineNum]['Status'] = $newStatus;
									$this->extenList[$exten][$lineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
								}
								# Повторим тоже самое для secondSideExten
								$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
								$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
								$this->log("Newstate02: newStatus={$newStatus} secondSideExten={$secondSideExten} secondLineNum={$secondLineNum}".PHP_EOL, self::logTrace);
								if (0 != $secondSideExten && isset($this->extenList[$secondSideExten][$secondLineNum])
										&& ($this->extenList[$secondSideExten][$secondLineNum]['Status'] & self::extenStatusMaskStd) != $status) {
									$newStatus = $status | ((self::extenStatusMaskStd ^ 255) & $this->extenList[$secondSideExten][$secondLineNum]['Status']);
									if ('' == $this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom'] && self::extenStatusInUse == ($newStatus & self::extenStatusMaskStd)) {
										$this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom'] = time();
										$this->extenList[$secondSideExten][$secondLineNum]['AnsweredExten'] = $answeredExten;
									}
									$this->extenList[$secondSideExten][$secondLineNum]['Status'] = $newStatus;
									$this->extenList[$secondSideExten][$secondLineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
									$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum ]);
								}
								# Обновим статус у связанных exten
								if (false !== $channelID) {
									$uid = $this->extenListChannelID[$channelID];
								} elseif (isset($this->answer['Uniqueid'])) {
									$uid = $this->answer['Uniqueid'];
								}
								if ($uid != 0) {
									foreach ($this->extenListRedirID as $to => $properties) {
										foreach ($properties as $line => $lineProperties) {
											if ($lineProperties['UniqueID'] == $uid) {
												$this->log("Newstate03: change status to={$to} line={$line} status={$status} uid={$uid}".PHP_EOL, self::logTrace);
												$this->extenListRedirID[$to][$line]['Status'] = $status;
											}
										}
									}
								}
								$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum ]);
								$this->logStatus('Changed main exten status. Exten='.$exten.' lineNum='.$lineNum.' SecondSideExten='.$secondSideExten.' secondLineNum='.$secondLineNum, self::logDebug);

								break;
							}
						}
						# Если изменился статус одного из редиректов
						if (isset($this->extenListRedirID[$exten])) {
							if (($this->extenListRedirID[$exten][$lineNum]['Status'] & self::extenStatusMaskStd) != $status) {
								if (self::extenStatusIdle == $status) {
									$newStatus = $status;
								} else {
									# Новый статус в стандартных битах только, остальные сохраним как было, 
									# за исключением комбинации редиректа с InUse, Busy, Ringing
									if (($this->extenListRedirID[$exten][$lineNum]['Status'] & (self::extenStatusInUse | self::extenStatusInUseRedir)) ==
											(self::extenStatusInUse | self::extenStatusInUseRedir) ||
										($this->extenListRedirID[$exten][$lineNum]['Status'] & (self::extenStatusBusy | self::extenStatusBusyRedir)) ==
											(self::extenStatusBusy | self::extenStatusBusyRedir) ||
										($this->extenListRedirID[$exten][$lineNum]['Status'] & (self::extenStatusRinging | self::extenStatusRingingRedir)) ==
											(self::extenStatusRinging | self::extenStatusRingingRedir)) {
										if ($status == self::extenStatusInUse) {
											$newStatus = (self::extenStatusInUse | self::extenStatusInUseRedir);
										} elseif ($status == self::extenStatusBusy) {
											$newStatus = (self::extenStatusBusy | self::extenStatusBusyRedir);
										} elseif ($status == self::extenStatusRinging) {
											$newStatus = (self::extenStatusRinging | self::extenStatusRingingRedir);
										} else {
											$newStatus = $status | ((self::extenStatusMaskStd ^ 255) & $this->extenListRedirID[$exten][$lineNum]['Status']);
										}
									} else {
										$newStatus = $status | ((self::extenStatusMaskStd ^ 255) & $this->extenListRedirID[$exten][$lineNum]['Status']);
									}
								}
								$this->extenListRedirID[$exten][$lineNum]['Status'] = $newStatus;
								$count = 0;
								foreach ($this->extenListRedirID as $to => $properties) {
									foreach ($properties as $line => $lineProperties) {
										if ($lineProperties['MainExten'] == $mainExten && $lineProperties['MainExtenLineNum'] == $mainLineNum) {
											++$count;
										}
									}
								}
								$this->log("Newstate04: newStatus={$newStatus} count={$count}".PHP_EOL, self::logTrace);
								# Если куча редиректов у основного екстеншена, то меняем статус екстеншену не при любом статусе
								# Если отвалился редирект, стал Idle
								if ($count > 1 && self::extenStatusIdle == $newStatus) {
									# Проверим, а не завершился ли mainExten
									if ($this->extenListRedirID[$exten][$lineNum]['UniqueID'] == $this->extenList[$mainExten][$mainLineNum]['UniqueID']) {
										# Тут мы не знаем статуса завершения, надо ждать события Dial
										break;
									}
									if (isset($this->answer['UniqueID'])) {
										$this->cleanRedirLine($this->answer['UniqueID'], $exten, $lineNum);
									} else {
										$this->cleanRedirLine(0, $exten, $lineNum);
									}
									# Нет смысла посылать sendDialEvent, т.к. для абонента видимых изменений нет
									$this->logStatus('Changed RedirID exten status to Idle. Exten='.$exten.' LineNum='.$lineNum, self::logDebug);
									break;
								}
								# Если зазвонил редирект
								if ($count > 1 && self::extenStatusRinging == ($newStatus & self::extenStatusMaskStd)) {
									$newStatus |= self::extenStatusRingingRedir;
									$this->extenListRedirID[$exten][$lineNum]['Status'] = $newStatus;
									if (isset($this->extenList[$exten])) {
										$this->log("Newstate05: cleanExten exten={$exten} lineNum={$lineNum}".PHP_EOL, self::logTrace);
										$this->cleanExten($exten, $lineNum);
										$this->extenList[$exten][$lineNum]['From'] = $this->extenList[$mainExten][$mainLineNum]['From'];
										$this->extenList[$exten][$lineNum]['To'] = $this->extenList[$mainExten][$mainLineNum]['To'];
										$this->extenList[$exten][$lineNum]['CallTimeFrom'] = time();
										if (0 != $this->extenList[$mainExten][$mainLineNum]['SecondSideExten']) {
											$this->extenList[$exten][$lineNum]['Direction'] = ('in' == $this->extenList[$mainExten][$mainLineNum]['Direction'] ? 'out' : 'in');
											$this->extenList[$exten][$lineNum]['SecondSideExten'] = $mainExten;
											$this->extenList[$exten][$lineNum]['SecondSideLineNum'] = $mainLineNum;
										} else {
											$this->extenList[$exten][$lineNum]['Direction'] = $this->extenList[$mainExten][$mainLineNum]['Direction'];
											$this->extenList[$exten][$lineNum]['SecondSideExten'] = 0;
											$this->extenList[$exten][$lineNum]['SecondSideLineNum'] = 0;
										}
										$this->extenList[$exten][$lineNum]['CallerIDName'] = $this->extenList[$mainExten][$mainLineNum]['CallerIDName'];
										$this->extenList[$exten][$lineNum]['Status'] = $newStatus;
										$this->extenList[$exten][$lineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
										$this->extenList[$exten][$lineNum]['UniqueID'] = $this->extenList[$mainExten][$mainLineNum]['UniqueID'];
										$this->sendDialEvent(['Exten' => $exten, 'LineNum' => $lineNum]);
									}
									$this->extenList[$mainExten][$mainLineNum]['Status'] = $newStatus;
									$this->extenList[$mainExten][$mainLineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
									$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum, 
										'Status' => $newStatus, 'StatusTxt' => $this->getStatusTxt($newStatus),
										'RingRedir' => $exten ]);
									$this->logStatus('Changed RedirID exten status to Ringing. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' RingRedir(exten)='.$exten, self::logDebug);
									break;
								}
								$pass = 0;
								# Если стало занято или начат разговор в редиректе
								if ($count > 1 && (self::extenStatusBusy == ($newStatus & self::extenStatusMaskStd) 
														|| self::extenStatusInUse == ($newStatus & self::extenStatusMaskStd))) {
									$pass = 1;
								}
								# Если остался только 1 редирект у екстеншена (может быть и сам екстеншен остался)
								# extenStatusIdle будем обрабатывать только в событии Dial
								elseif (1 == $count && self::extenStatusIdle != $newStatus) {
									$pass = 1;
								}
								if ($pass > 0) {
									$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
									$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
									if ($this->extenList[$mainExten][$mainLineNum]['To'] != $answeredExten) {
										if (self::extenStatusInUse == $newStatus) {
											$newStatus |= self::extenStatusInUseRedir;
										}
										if (self::extenStatusBusy == $newStatus) {
											$newStatus |= self::extenStatusBusyRedir;
										}
										if (self::extenStatusRinging == $newStatus) {
											$newStatus |= self::extenStatusRingingRedir;
										}
									}
									$this->log("Newstate06: newStatus={$newStatus} exten={$exten} mainExten={$mainExten} pass={$pass} answeredExten={$answeredExten}".PHP_EOL, self::logTrace);
									if ('' == $this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] 
											&& self::extenStatusInUse == ($newStatus & self::extenStatusMaskStd)) {
										$this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] = time();
										$this->extenList[$mainExten][$mainLineNum]['AnsweredExten'] = $answeredExten;
										if ($mainExten != $exten) {
											if (isset($this->extenList[$exten])) {
												$this->extenList[$exten][$lineNum]['TalkTimeFrom'] = time();
												$this->extenList[$exten][$lineNum]['AnsweredExten'] = $this->extenList[$mainExten][$mainLineNum]['AnsweredExten'];
											}
										}
									}
									if ($this->extenList[$mainExten][$mainLineNum]['Status'] != $newStatus) {
										$this->extenList[$mainExten][$mainLineNum]['Status'] = $newStatus;
										$this->extenList[$mainExten][$mainLineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
										foreach ($this->extenListRedirID[$this->extenList[$mainExten][$mainLineNum]['From']] as $line => $lineProperties) {
											if ($lineProperties['UniqueID'] == $this->extenList[$mainExten][$mainLineNum]['UniqueID']) {
												$this->extenListRedirID[$this->extenList[$mainExten][$mainLineNum]['From']][$line]['Status'] = $this->extenList[$mainExten][$mainLineNum]['Status'];
											}
										}
										if ($mainExten != $exten && isset($this->extenList[$exten])) {
											$this->extenList[$exten][$lineNum]['Status'] = $newStatus;
											$this->extenList[$exten][$lineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
										}
										$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum ]);
										if ($mainExten != $exten && isset($this->extenList[$exten])) {
											$this->sendDialEvent(['Exten' => $exten, 'LineNum' => $lineNum ]);
										}
										$this->logStatus('Changed RedirID exten status. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$exten.' lineNum='.$lineNum. ' newStatus='.$newStatus, self::logDebug);
									}
									# Повторим тоже самое для secondSideExten
									$this->log("Newstate07: mainExten={$mainExten} mainLineNum={$mainLineNum} secondSideExten={$secondSideExten} secondLineNum={$secondLineNum}".PHP_EOL, self::logTrace);
									if (isset($this->extenList[$secondSideExten][$secondLineNum])) {
										if ('' == $this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom']
												&& self::extenStatusInUse == ($newStatus & self::extenStatusMaskStd)) {
											$this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom'] = time();
											$this->extenList[$secondSideExten][$secondLineNum]['AnsweredExten'] = $this->extenList[$mainExten][$mainLineNum]['AnsweredExten'];
										}
										if ($this->extenList[$secondSideExten][$secondLineNum]['Status'] != $newStatus) {
											$this->extenList[$secondSideExten][$secondLineNum]['Status'] = $newStatus;
											$this->extenList[$secondSideExten][$secondLineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
											$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum ]);
											$this->logStatus('Changed SecondSide RedirID exten status. SecondExten='.$secondSideExten.' LineNum='.$secondLineNum, self::logDebug);
										}
									}
								}
							}
						}

						break;
					case 'Dial':
						if (isset($this->extenListCheckBusy[$this->answer['UniqueID']])) {
							unset($this->extenListCheckBusy[$this->answer['UniqueID']]);
						}
						switch ($this->answer['SubEvent']) {
							case 'Begin':
								# Extract destination exten
								if (false === ($to = $this->extractExten($this->answer['Destination']))) {
									break;
								}
								$channelID = $this->extractChannelID($this->answer['Channel']);
								$destChannelID = $this->extractChannelID($this->answer['Destination']);
								# Если звонок транковый, например, на шлюз, надо выловить содержимое
								$altTo = substr($this->answer['Dialstring'], strlen($to) + 1);
								$from = $this->answer['CallerIDNum'];
								# В канале иногда бывает не От, а К, поэтому проверяем его в последнюю очередь
								$exten = $this->extractExten($this->answer['Channel']);

								$secondSideExten = 0;
								$secondLineNum = 0;
								if (!is_numeric($to)) {
									if (!is_numeric($altTo)) {
										$to = $exten;
									} else {
										$to = $altTo;
									}
								}
								# Ищем что мониторим
								# Если окажутся оба направления, то берём "Откуда" вызов
								if (!isset($this->extenList[$exten])) {
									# Всё плохо, ищем альтернативу
									if (!isset($this->extenList[$from])) {
										if (!isset($this->extenList[$to])) {
											if (!isset($this->extenList[$altTo])) {
												$exten = 0;
												$dialDirection = '';
											} else {
												$exten = $altTo;
												$dialDirection = 'in';
											}
										} else {
											$exten = $to;
											$dialDirection = 'in';
										}
									} else {
										$exten = $from;
										$dialDirection = 'out';
									}
								} else {
									if ($exten == $to) {
										$dialDirection = 'in';
									} else {
										$dialDirection = 'out';
									}
								}
								if (isset($this->extenList[$from]) && isset($this->extenList[$to])) {
									$dialDirection = 'out';
								}
								# Найдём secondSideExten. Линию secondLineNum найдём позже, сейчас надо знать только наличие второго конца.
								if (isset($this->extenList[$to]) && $dialDirection == 'out') {
									$secondSideExten = $to;
								} elseif (isset($this->extenList[$from]) && $dialDirection == 'in') {
									$secondSideExten = $from;
								}

								$mainExten = 0;
								$mainLineNum = 0;
								$lineNum = 0;
								# Если звонили с внешнего номера и сработала переадресация, то номер переадресации должен быть в extenListRedirID
								if (0 == $exten && isset($this->extenListRedirID[$to])) {
									foreach ($this->extenListRedirID[$to] as $line => $properties) {
										if ($this->answer['UniqueID'] == $properties['UniqueID']
												|| (isset($this->extenListChannelID[$channelID]) && $this->extenListChannelID[$channelID] == $properties['UniqueID'])) {
											$exten = $to;
											$lineNum = $line;
											$mainExten = $properties['MainExten'];
											$mainLineNum = $properties['MainExtenLineNum'];
											break;
										}
									}
								}
								if ($exten == $secondSideExten) {
									$secondSideExten = 0;
								}
								if (0 == $mainExten && isset($this->extenListRedirID[$exten])) {
									foreach ($this->extenListRedirID[$exten] as $line => $properties) {
										if ($this->answer['UniqueID'] == $properties['UniqueID']
												|| (isset($this->extenListChannelID[$channelID]) && $this->extenListChannelID[$channelID] == $properties['UniqueID'])) {
											$lineNum = $line;
											$mainExten = $properties['MainExten'];
											$mainLineNum = $properties['MainExtenLineNum'];
											break;
										}
									}
								}

								$this->log("Dial01: exten={$exten} lineNum={$lineNum} mainExten={$mainExten} mainLineNum={$mainLineNum} dialDirection={$dialDirection} from={$from} to={$to} secondSideExten={$secondSideExten} secondLineNum={$secondLineNum} channelID={$channelID} destChannelID={$destChannelID}".PHP_EOL, self::logTrace);
								# Первое вхождение, может быть звонок как К абоненту, так и ОТ абонента
								if (0 != $exten && !isset($this->extenListCallID[$this->answer['UniqueID']]) 
										&& !isset($this->extenListChannelID[$channelID])) {
									$pass = 1;
									# Если уже есть, то не создаём, такое может быть при переадресации во внешний мир
									foreach ($this->extenList[$exten] as $line => $properties) {
										if ($from == $properties['From']) {
											$pass = 0;
											break;
										}
									}
									if ($pass == 1) {
										$this->extenListCallID[$this->answer['UniqueID']]['MainExten'] = $exten;
										$this->extenListCallID[$this->answer['DestUniqueID']]['MainExten'] = $exten;
										$lineNum = 0;
										while (isset($this->extenList[$exten][$lineNum])) {
											if (self::extenStatusIdle == $this->extenList[$exten][$lineNum]['Status']) {
												break;
											}
											$lineNum++;
										}
										$this->log("Dial02: lineNum={$lineNum}".PHP_EOL, self::logTrace);
										if (false !== $channelID) {
											$this->extenListChannelID[$channelID] = $this->answer['UniqueID'];
										}
										if (false !== $destChannelID) {
											$this->extenListChannelID[$destChannelID] = $this->answer['UniqueID'];
										}
										$this->extenListCallID[$this->answer['UniqueID']]['MainExtenLineNum'] = $lineNum;
										$this->extenListCallID[$this->answer['UniqueID']]['Exten'] = $exten;
										$this->extenListCallID[$this->answer['UniqueID']]['LineNum'] = $lineNum;
										$this->extenListCallID[$this->answer['DestUniqueID']]['MainExtenLineNum'] = $lineNum;
										$this->extenListCallID[$this->answer['DestUniqueID']]['Exten'] = $exten;
										$this->extenListCallID[$this->answer['DestUniqueID']]['LineNum'] = $lineNum;
										$this->extenListRedirID[$exten][$lineNum]['MainExten'] = $exten;
										$this->extenListRedirID[$exten][$lineNum]['MainExtenLineNum'] = $lineNum;
										$this->extenListRedirID[$exten][$lineNum]['UniqueID'] = $this->answer['UniqueID'];
										$this->extenListRedirID[$exten][$lineNum]['Status'] = self::extenStatusRinging;
										if ($to != $exten) {
											$lineNum2 = 0;
											while (isset($this->extenListRedirID[$to][$lineNum2])) {
												if (self::extenStatusIdle == $this->extenListRedirID[$to][$lineNum2]['Status']) {
													break;
												}
												$lineNum2++;
											}
											$this->log("Dial03: lineNum2={$lineNum2}".PHP_EOL, self::logTrace);
											$this->extenListRedirID[$to][$lineNum2]['MainExten'] = $exten;
											$this->extenListRedirID[$to][$lineNum2]['MainExtenLineNum'] = $lineNum;
											$this->extenListRedirID[$to][$lineNum2]['UniqueID'] = $this->answer['UniqueID'];
											$this->extenListRedirID[$to][$lineNum2]['Status'] = self::extenStatusRinging;
										}
										if ($from != $exten) {
											$lineNum2 = 0;
											while (isset($this->extenListRedirID[$from][$lineNum2])) {
												if (self::extenStatusIdle == $this->extenListRedirID[$from][$lineNum2]['Status']) {
													break;
												}
												$lineNum2++;
											}
											$this->log("Dial04: lineNum2={$lineNum2}".PHP_EOL, self::logTrace);
											$this->extenListRedirID[$from][$lineNum2]['MainExten'] = $exten;
											$this->extenListRedirID[$from][$lineNum2]['MainExtenLineNum'] = $lineNum;
											$this->extenListRedirID[$from][$lineNum2]['UniqueID'] = $this->answer['UniqueID'];
											$this->extenListRedirID[$from][$lineNum2]['Status'] = self::extenStatusRinging;
										}
										$pass = $this->extractExten($this->answer['Channel']);
										if ($pass !== false && $from != $pass && $exten != $pass) {
											$lineNum2 = 0;
											while (isset($this->extenListRedirID[$pass][$lineNum2])) {
												if (self::extenStatusIdle == $this->extenListRedirID[$pass][$lineNum2]['Status']) {
													break;
												}
												$lineNum2++;
											}
											$this->log("Dial04.1: lineNum2={$lineNum2}".PHP_EOL, self::logTrace);
											$this->extenListRedirID[$pass][$lineNum2]['MainExten'] = $exten;
											$this->extenListRedirID[$pass][$lineNum2]['MainExtenLineNum'] = $lineNum;
											$this->extenListRedirID[$pass][$lineNum2]['UniqueID'] = $this->answer['UniqueID'];
											$this->extenListRedirID[$pass][$lineNum2]['Status'] = self::extenStatusRinging;
										}
										$this->cleanExten($exten, $lineNum);
										$this->extenList[$exten][$lineNum]['Status'] = self::extenStatusRinging;
										$this->extenList[$exten][$lineNum]['StatusTxt'] = $this->getStatusTxt(self::extenStatusRinging);
										$this->extenList[$exten][$lineNum]['From'] = $from;
										$this->extenList[$exten][$lineNum]['To'] = $to;
										$this->extenList[$exten][$lineNum]['Direction'] = $dialDirection;
										$this->extenList[$exten][$lineNum]['CallTimeFrom'] = time();
										$this->extenList[$exten][$lineNum]['SecondSideExten'] = 0;
										$this->extenList[$exten][$lineNum]['SecondSideLineNum'] = 0;
										$this->extenList[$exten][$lineNum]['CallerIDName'] = $this->answer['CallerIDName'];
										$this->extenList[$exten][$lineNum]['UniqueID'] = $this->answer['UniqueID'];
										$secondLineNum = 0;
										if (0 != $secondSideExten) {
											while (isset($this->extenListRedirID[$secondSideExten][$secondLineNum])) {
												if (self::extenStatusIdle == $this->extenListRedirID[$secondSideExten][$secondLineNum]['Status']
														|| $this->answer['UniqueID'] == $this->extenListRedirID[$secondSideExten][$secondLineNum]['UniqueID']) {
													break;
												}
												$secondLineNum++;
											}
											$this->log("Dial05: secondSideExten={$secondSideExten} secondLineNum={$secondLineNum}".PHP_EOL, self::logTrace);
											$this->extenListRedirID[$secondSideExten][$secondLineNum]['MainExten'] = $exten;
											$this->extenListRedirID[$secondSideExten][$secondLineNum]['MainExtenLineNum'] = $lineNum;
											$this->extenListRedirID[$secondSideExten][$secondLineNum]['UniqueID'] = $this->answer['UniqueID'];
											$this->extenListRedirID[$secondSideExten][$secondLineNum]['Status'] = self::extenStatusRinging; 
											$this->extenList[$exten][$lineNum]['SecondSideExten'] = $secondSideExten;
											$this->extenList[$exten][$lineNum]['SecondSideLineNum'] = $secondLineNum;
											$this->cleanExten($secondSideExten, $secondLineNum);
											$this->extenList[$secondSideExten][$secondLineNum]['Status'] = self::extenStatusRinging;
											$this->extenList[$secondSideExten][$secondLineNum]['StatusTxt'] = $this->getStatusTxt(self::extenStatusRinging);
											$this->extenList[$secondSideExten][$secondLineNum]['From'] = $from;
											$this->extenList[$secondSideExten][$secondLineNum]['To'] = $to;
											$this->extenList[$secondSideExten][$secondLineNum]['Direction'] = ('in' == $dialDirection ? 'out' : 'in');
											$this->extenList[$secondSideExten][$secondLineNum]['CallTimeFrom'] = time();
											$this->extenList[$secondSideExten][$secondLineNum]['SecondSideExten'] = $exten;
											$this->extenList[$secondSideExten][$secondLineNum]['SecondSideLineNum'] = $lineNum;
											$this->extenList[$secondSideExten][$secondLineNum]['CallerIDName'] = $this->answer['CallerIDName'];
											$this->extenList[$secondSideExten][$secondLineNum]['UniqueID'] = $this->answer['UniqueID'];
											$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum ]);
										}
										$this->sendDialEvent(['Exten' => $exten, 'LineNum' => $lineNum ]);
										$this->logStatus('Dial event && first entry. Exten='.$exten.' LineNum='.$lineNum.' secondSideExten='.$secondSideExten.' SecondLineNum='.$secondLineNum.' from='.$from.' to='.$to, self::logDebug);
										break;
									}
								}
								# Добавляем редиректы для последующего соединения
								if (isset($this->extenListCallID[$this->answer['UniqueID']])) {
									if (false !== $destChannelID) {
										$this->extenListChannelID[$destChannelID] = $this->answer['DestUniqueID'];
									}
									$secondSideExten = 0;
									if (0 == $mainExten) {
										$mainExten = $this->extenListCallID[$this->answer['UniqueID']]['MainExten'];
										$mainLineNum = $this->extenListCallID[$this->answer['UniqueID']]['MainExtenLineNum'];
									}
									$this->extenListCallID[$this->answer['DestUniqueID']]['MainExten'] = $mainExten;
									$this->extenListCallID[$this->answer['DestUniqueID']]['MainExtenLineNum'] = $mainLineNum;
									$this->extenListCallID[$this->answer['DestUniqueID']]['Exten'] = $to;
									$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
									$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
									$this->log("Dial06: mainExten={$mainExten} mainLineNum={$mainLineNum} to={$to} secondSideExten={$secondSideExten} secondLineNum={$secondLineNum}".PHP_EOL, self::logTrace);
									$lineNum = 0;
									while (isset($this->extenListRedirID[$to][$lineNum])) {
										if ($mainExten == $this->extenListRedirID[$to][$lineNum]['MainExten'] 
												&& $mainLineNum == $this->extenListRedirID[$to][$lineNum]['MainExtenLineNum']) {
											$this->extenListCallID[$this->answer['DestUniqueID']]['LineNum'] = $lineNum;
											$this->logStatus('Not add redir to main call, already exist same. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$to.' from='.$from.' to='.$to.' dialDirection='.$dialDirection, self::logDebug);
											break 2;
										}
										$lineNum++;
									}
									$this->log("Dial07: lineNum={$lineNum}".PHP_EOL, self::logTrace);
									$this->extenListCallID[$this->answer['DestUniqueID']]['LineNum'] = $lineNum;
									$this->extenListRedirID[$to][$lineNum]['MainExten'] = $mainExten;
									$this->extenListRedirID[$to][$lineNum]['MainExtenLineNum'] = $mainLineNum;
									$this->extenListRedirID[$to][$lineNum]['UniqueID'] = $this->answer['DestUniqueID'];
									$this->extenListRedirID[$to][$lineNum]['Status'] = self::extenStatusIdle;
									$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum,
										'AddRedir' => $to ]);
									$this->logStatus('Add redir to main call. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$to.' from='.$from.' to='.$to.' dialDirection='.$dialDirection, self::logDebug);
									break;
								}
								$pass = 0;
								# Если канал уже использовался, то найдём его или если новое соединение в уже зарегистрированном канале
								# Возможно нужна менее строгая проверка !isset($this->extenListRedirID[$exten]
								if ((!isset($this->extenListRedirID[$exten][$lineNum]) && isset($this->extenListChannelID[$channelID]))
										|| (isset($this->extenListChannelID[$channelID]) && !isset($this->extenListCallID[$this->answer['UniqueID']]))) {
									$uniqueID = $this->extenListChannelID[$channelID];
									$mainExten = $this->extenListCallID[$uniqueID]['MainExten'];
									$mainLineNum = $this->extenListCallID[$uniqueID]['MainExtenLineNum'];
									# Тут специально не надо, будут дубли... Получилась ситуация, когда связи нет, всё равно надо добавлять, но с проверкой.
									if (!isset($this->extenListChannelID[$destChannelID])) {
										$this->extenListChannelID[$destChannelID] = $this->answer['UniqueID'];
									}
									$this->extenListCallID[$this->answer['UniqueID']]['MainExten'] = $mainExten;
									$this->extenListCallID[$this->answer['UniqueID']]['MainExtenLineNum'] = $mainLineNum;
									$this->extenListCallID[$this->answer['UniqueID']]['Exten'] = $to;
									$this->extenListCallID[$this->answer['UniqueID']]['LineNum'] = $lineNum;
									$this->logStatus('Find and add redir to main call. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$to.' from='.$from.' to='.$to.' dialDirection='.$dialDirection, self::logDebug);
									break;
								}
								# Заполняем конечные соединения ID'шниками в момент соединения
								if (isset($this->extenListRedirID[$exten])) {
									$lineNum = 0;
									# Если пришло ещё одно событие на то же соединение - игнорируем
									foreach ($this->extenList[$exten] as $line => $properties) {
										if ($properties['From'] == $from && $properties['To'] == $to
												&& self::extenStatusRinging == $this->extenListRedirID[$exten][$line]['Status']) {
											$mainExten = $this->extenListRedirID[$exten][$line]['MainExten'];
											$mainLineNum = $this->extenListRedirID[$exten][$line]['MainExtenLineNum'];
											$this->log("Dial08: mainExten={$mainExten} mainLineNum={$mainLineNum}".PHP_EOL, self::logTrace);
											if (false !== $destChannelID) {
												$this->extenListChannelID[$destChannelID] = $this->answer['UniqueID'];
											}
											$this->extenListCallID[$this->answer['UniqueID']]['MainExten'] = $mainExten;
											$this->extenListCallID[$this->answer['UniqueID']]['MainExtenLineNum'] = $mainLineNum;
											$this->extenListCallID[$this->answer['UniqueID']]['Exten'] = $exten;
											$this->extenListCallID[$this->answer['UniqueID']]['LineNum'] = $line;
											$this->logStatus('Skip assign additional UniqueID to redir call.  Exten='.$exten.' secondSideExten='.$secondSideExten.' from='.$from.' to='.$to.' dialDirection='.$dialDirection.' lineNum='.$lineNum, self::logDebug);
											break 2;
										}
									}
									while (isset($this->extenListRedirID[$exten][$lineNum])) {
										if (0 == $this->extenListRedirID[$exten][$lineNum]['UniqueID'] || 
												(0 != $this->extenListRedirID[$exten][$lineNum]['UniqueID'] 
												&& $this->extenListRedirID[$exten][$lineNum]['MainExten'] == $exten
												&& ($this->extenListRedirID[$exten][$lineNum]['Status'] == self::extenStatusRinging 
													|| $this->extenListRedirID[$exten][$lineNum]['Status'] == self::extenStatusIdle))) {
											$mainExten = $this->extenListRedirID[$exten][$lineNum]['MainExten'];
											$mainLineNum = $this->extenListRedirID[$exten][$lineNum]['MainExtenLineNum'];
											$pass = 1;
											break;
										}
										$lineNum++;
									}
									$this->log("Dial09: exten={$exten} lineNum={$lineNum} mainExten={$mainExten} mainLineNum={$mainLineNum}".PHP_EOL, self::logTrace);
									if ($pass == 1) {
										$this->extenListCallID[$this->answer['UniqueID']]['MainExten'] = $mainExten;
										$this->extenListCallID[$this->answer['UniqueID']]['MainExtenLineNum'] = $mainLineNum;
										$this->extenListCallID[$this->answer['UniqueID']]['Exten'] = $exten;
										$this->extenListCallID[$this->answer['UniqueID']]['LineNum'] = $lineNum;
										$this->extenListRedirID[$exten][$lineNum]['UniqueID'] = $this->answer['UniqueID'];
										$this->extenListRedirID[$exten][$lineNum]['Status'] = $this->extenList[$mainExten][$mainLineNum]['Status'];
										if (0 != $secondSideExten && isset($this->extenList[$secondSideExten])) {
											$secondLineNum = 0;
											while (isset($this->extenList[$secondSideExten][$secondLineNum])) {
												if ((self::extenStatusIdle == $this->extenList[$secondSideExten][$secondLineNum]['Status']) ||
													(self::extenStatusRinging == $this->extenList[$secondSideExten][$secondLineNum]['Status'] 
														&& $secondSideExten == $exten)) {
													break;
												}
												$secondLineNum++;
											}
											$this->log("Dial10: secondSideExten={$secondSideExten} secondLineNum={$secondLineNum}".PHP_EOL, self::logTrace);
											$this->extenList[$secondSideExten][$secondLineNum]['From'] = $this->extenList[$mainExten][$mainLineNum]['From'];
											$this->extenList[$secondSideExten][$secondLineNum]['To'] = $this->extenList[$mainExten][$mainLineNum]['To'];
											$this->extenList[$secondSideExten][$secondLineNum]['Direction'] = ('in' == $this->extenList[$mainExten][$mainLineNum]['Direction'] ? 'out' : 'in');
											$this->extenList[$secondSideExten][$secondLineNum]['CallTimeFrom'] = time();
											$this->extenList[$secondSideExten][$secondLineNum]['SecondSideExten'] = $mainExten;
											$this->extenList[$secondSideExten][$secondLineNum]['SecondSideLineNum'] = $mainLineNum;
											$this->extenList[$secondSideExten][$secondLineNum]['Status'] = $this->extenListRedirID[$exten][$lineNum]['Status'];
											$this->extenList[$secondSideExten][$secondLineNum]['StatusTxt'] = $this->getStatusTxt($this->extenListRedirID[$exten][$lineNum]['Status']);
											$this->extenList[$secondSideExten][$secondLineNum]['UniqueID'] = $this->extenList[$mainExten][$mainLineNum]['UniqueID'];
											$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum ]);
										}

										$this->logStatus('Assign UniqueID to redir call and change status from redir.  Exten='.$exten.' secondSideExten='.$secondSideExten.' from='.$from.' to='.$to.' dialDirection='.$dialDirection.' lineNum='.$lineNum.' mainExten='.$mainExten.' mainLineNum='.$mainLineNum, self::logDebug);
									} else {
										$this->log('Skip EventBlock from Asterisk ->'.PHP_EOL, self::logDebug, $this->answer);
									}
									break;
								}

								break;
							case 'End':
								if (!isset($this->extenListCallID[$this->answer['UniqueID']])) {
									break;
								}
								$mainExten = $this->extenListCallID[$this->answer['UniqueID']]['MainExten'];
								$mainLineNum = $this->extenListCallID[$this->answer['UniqueID']]['MainExtenLineNum'];
								$channelID = $this->extractChannelID($this->answer['Channel']);
								# № который реально звонит
								$exten = $this->extenListCallID[$this->answer['UniqueID']]['Exten'];;
								$lineNum = $this->extenListCallID[$this->answer['UniqueID']]['LineNum'];;
								$count = 0;
								foreach ($this->extenListRedirID as $to => $properties) {
									foreach ($properties as $line => $lineProperties) {
										if ($lineProperties['MainExten'] == $mainExten && $lineProperties['MainExtenLineNum'] == $mainLineNum 
												&& $lineProperties['UniqueID'] != 0 && $to != $mainExten) {
											++$count;
										}
									}
								}
								$this->log("Dial11: mainExten={$mainExten} mainLineNum={$mainLineNum} channelID={$channelID} exten={$exten} lineNum={$lineNum} count={$count}".PHP_EOL, self::logTrace);
								# Тут может быть как основной ответ 'CANCEL', так и хрень типа 'CHANUNAVAIL'
								if ('ANSWER' != $this->answer['DialStatus']) {
									# Проверим завершился один из редиректов или весь разговор
									# Завершился остаток от редиректа
									if (!isset($this->extenListRedirID[$exten])
											&& $this->answer['UniqueID'] != $this->extenList[$mainExten][$mainLineNum]['UniqueID']) {
										$this->cleanRedirLine($this->answer['UniqueID'], $exten, $lineNum);
										$this->logStatus('End line garbage. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$exten.' LineNum='.$lineNum.' count='.$count, self::logDebug);
										break;
									}
									# Если завершился вызов на отслеживаемый екстеншн (он сам в массиве редиректов и других редиректов нет)
									#	или его вообще нет, т.к. завершился главный вызов
									if ((isset($this->extenListRedirID[$mainExten][$mainLineNum]) && $this->extenListRedirID[$mainExten][$mainLineNum]['UniqueID'] == $this->answer['UniqueID'])
												|| 0 == $exten) {
										if (0 == $exten && 0 < $count && self::extenStatusInUse != $this->extenListRedirID[$mainExten][$mainLineNum]['Status']) {
											$this->cleanRedirLine($this->answer['UniqueID'], $mainExten, $mainLineNum);
											$this->logStatus('End exten line, redir line in use. mainExten='.$mainExten.' LineNum='.$lineNum.' exten='.$exten.' count='.$count, self::logDebug);
											break;
										}
										if ($this->answer['UniqueID'] == $this->extenList[$mainExten][$mainLineNum]['UniqueID']
													|| self::extenStatusIdle != $this->extenList[$mainExten][$mainLineNum]['Status']) {
											$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum,
												'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'],
												'EndCallStatusTxt' => self::DialStatusTxt[$this->answer['DialStatus']] ]);
											# Повторим тоже самое для secondSideExten
											$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
											$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
											$this->log("Dial12: secondSideExten={$secondSideExten} secondLineNum={$secondLineNum}".PHP_EOL, self::logTrace);
											if (isset($this->extenList[$secondSideExten][$secondLineNum])) {
												$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum, 
													'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'], 
													'EndCallStatusTxt' => self::DialStatusTxt[$this->answer['DialStatus']] ]);
												if (self::extenStatusIdle == $this->extenList[$secondSideExten][$secondLineNum]['Status']) {
													$this->cleanExten($secondSideExten, $secondLineNum);
												}
											}
										}
										$this->saveCDR(['Exten' => $mainExten, 'LineNum' => $mainLineNum,
												'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus']]);
										$this->cleanAllLine($this->answer['UniqueID']);
										$this->logStatus('Exten dial end (Commited). mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$exten.' lineNum='.$lineNum.' count='.$count, self::logDebug);

										break;
									}
									# Завершилось основное соединение
									if ($this->answer['UniqueID'] == $this->extenList[$mainExten][$mainLineNum]['UniqueID']) {
										$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum,
												'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'],
												'EndCallStatusTxt' => self::DialStatusTxt[$this->answer['DialStatus']] ]);
										# Повторим тоже самое для secondSideExten
										$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
										$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
										$this->log("Dial13: secondSideExten={$secondSideExten} secondLineNum={$secondLineNum}".PHP_EOL, self::logTrace);
										if (isset($this->extenList[$secondSideExten][$secondLineNum]) && isset($this->extenListRedirID[$secondSideExten][$secondLineNum])) {
											$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum,
													'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'],
													'EndCallStatusTxt' => self::DialStatusTxt[$this->answer['DialStatus']] ]);
											$this->cleanExten($secondSideExten, $secondLineNum);
										}
										$this->saveCDR(['Exten' => $mainExten, 'LineNum' => $mainLineNum,
												'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus']]);
										$this->cleanAllLine($this->answer['UniqueID']);
										$this->logStatus('Redir dial end, call end. (Commited). mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$exten.' LineNum='.$lineNum.' count='.$count, self::logDebug);
										break;
									}
									$this->cleanRedirLine($this->answer['UniqueID'], $exten, $lineNum);
									$this->logStatus('Exten dial Redirect end (Commited). mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$exten.' LineNum='.$lineNum.' count='.$count, self::logDebug);
									break;
								}
								if ('ANSWER' == $this->answer['DialStatus']) {
									if (1 <= $count && $this->answer['UniqueID'] != $this->extenList[$mainExten][$mainLineNum]['UniqueID']) {
										if (($this->extenList[$mainExten][$mainLineNum]['Status'] & self::extenStatusMaskStd) != self::extenStatusInUse) {
											if (isset($this->extenListRedirID[$mainExten][$mainLineNum]) 
													&& ($this->extenListRedirID[$mainExten][$mainLineNum]['UniqueID'] == $this->answer['UniqueID']
														|| $this->extenList[$mainExten][$mainLineNum]['To'] == $exten)) {
												# Соединение произошло с самим екстеншеном
												$this->extenList[$mainExten][$mainLineNum]['Status'] = self::extenStatusInUse;
												$this->extenList[$mainExten][$mainLineNum]['StatusTxt'] = $this->getStatusTxt(self::extenStatusInUse);
											} else {
												# Соединение произошло с кем-то из редиректа
												$this->extenList[$mainExten][$mainLineNum]['Status'] = self::extenStatusInUseRedir;
												$this->extenList[$mainExten][$mainLineNum]['StatusTxt'] = $this->getStatusTxt(self::extenStatusInUseRedir);
											}
											if (isset($this->answer['ConnectedLineNum'])) {
												$answeredExten = $this->extractExten($this->answer['ConnectedLineNum']);
												if ($answeredExten === false) {
													$answeredExten = $exten;
												}
											} else {
												$answeredExten = $exten;
											}
											if (isset($this->extenList[$mainExten][$mainLineNum])) {
												if ($this->extenList[$mainExten][$mainLineNum]['From'] == $answeredExten) {
													$answeredExten = $this->extenList[$mainExten][$mainLineNum]['To'];
												}
											}
											if ('' == $this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom']) {
												$this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] = time();
												$this->extenList[$mainExten][$mainLineNum]['AnsweredExten'] = $answeredExten;
											}
											$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum ]);
											# Повторим тоже самое для secondSideExten
											$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
											$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
											$this->log("Dial14: secondSideExten={$secondSideExten} secondLineNum={$secondLineNum}".PHP_EOL, self::logTrace);
											if (isset($this->extenList[$secondSideExten][$secondLineNum])) {
												if ('' == $this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom']) {
													$this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom'] = time();
													$this->extenList[$secondSideExten][$secondLineNum]['AnsweredExten'] = $answeredExten;
												}
												$this->extenList[$secondSideExten][$secondLineNum]['Status'] = $this->extenList[$mainExten][$mainLineNum]['Status'];
												$this->extenList[$secondSideExten][$secondLineNum]['StatusTxt'] = $this->getStatusTxt($this->extenList[$mainExten][$mainLineNum]['Status']);
												$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum ]);
											}
										}
										$this->cleanRedirLine($this->answer['UniqueID'], $exten, $lineNum);
										$this->logStatus('Close one of additional Redir exten. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$exten.' LineNum='.$lineNum.' count='.$count.' answeredExten='.$answeredExten, self::logDebug);

										break;
									}

									# Вызов завершён
									$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum,
										'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'], 
										'EndCallStatusTxt' => self::DialStatusTxt[$this->answer['DialStatus']] ]);
									# Повторим тоже самое для secondSideExten
									$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
									$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
									$this->log("Dial15: secondSideExten={$secondSideExten} secondLineNum={$secondLineNum}".PHP_EOL, self::logTrace);
									# Отправляем только если вторая сторона есть и ей ещё не отправляли ранее
									if (isset($this->extenList[$secondSideExten][$secondLineNum]) && $this->extenList[$secondSideExten][$secondLineNum]['Status'] != self::extenStatusIdle) {
										$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum,
											'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'],
											'EndCallStatusTxt' => self::DialStatusTxt[$this->answer['DialStatus']] ]);
										$this->cleanExten($secondSideExten, $secondLineNum);
									}
									$this->saveCDR(['Exten' => $mainExten, 'LineNum' => $mainLineNum,
											'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus']]);
									$this->cleanAllLine($this->answer['UniqueID']);
									$this->logStatus('Call is finished. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$exten.' LineNum='.$lineNum.' count='.$count, self::logDebug);

									break;
								}

								break;
						}

						break;
				}
				/*
				 * Call tick user function
				 */
				if (isset($this->callbackFunc['tick'])) {
					try {
						call_user_func($this->callbackFunc['tick']);
					} catch (\Throwable $e) {
						$this->log($e->getMessage(), self::logInfo);
					}
				}
			}
			if (is_resource($this->fp)) {
				fclose($this->fp);
			}
		}
	}

	private function cleanRedirLine($uniqueID, $exten, $lineNum) {
		$this->log("clearRedirLine: uniqueID={$uniqueID} exten={$exten} lineNum={$lineNum}".PHP_EOL, self::logTrace);
		if (0 == $uniqueID) {
			if (isset($this->extenListRedirID[$exten][$lineNum])) {
				$mainExten = $this->extenListRedirID[$exten][$lineNum]['MainExten'];
				$mainLineNum = $this->extenListRedirID[$exten][$lineNum]['MainExtenLineNum'];
				$uniqueID = $this->extenListRedirID[$exten][$lineNum]['UniqueID'];
			} else {
				return;
			}
		} else {
			$mainExten = $this->extenListCallID[$uniqueID]['MainExten'];
			$mainLineNum = $this->extenListCallID[$uniqueID]['MainExtenLineNum'];
		}
		foreach ($this->extenListChannelID as $contextID => $uid) {
			if ($uniqueID == $uid) {
				unset($this->extenListChannelID[$contextID]);
			}
		}
		unset($this->extenListCallID[$uniqueID]);
		if (isset($this->extenList[$exten]) && $mainExten != $exten) {
			foreach ($this->extenList[$exten] as $line => $properties) {
				if ($uniqueID == $properties['UniqueID']) {
					$this->cleanExten($exten, $lineNum);
					break;
				}
			}
		}
		if (isset($this->extenListRedirID[$exten])) {
			if (isset($this->answer['DialStatus'])) {
				$status = $this->answer['DialStatus'];
			} else {
				$status = 'REDIREND';
			}
			foreach ($this->extenListRedirID[$exten] as $line => $properties) {
				if ($mainExten == $properties['MainExten'] && $mainLineNum == $properties['MainExtenLineNum'] && $uniqueID == $properties['UniqueID']) {
					if (isset($this->extenList[$exten][$lineNum]) && self::extenStatusIdle != $this->extenList[$exten][$lineNum]['Status']
							&& !($exten == $mainExten && $line == $mainLineNum)) {
						if (!($exten == $this->extenList[$exten][$lineNum]['SecondSideExten'] 
								&& $line == $this->extenList[$exten][$lineNum]['SecondSideLineNum'])) {
							$this->sendDialEvent(['Exten' => $exten, 'LineNum' => $lineNum,
									'CallTimeTo' => time(), 'EndCallStatus' => $status,
									'EndCallStatusTxt' => self::DialStatusTxt[$status] ]);
							$this->cleanExten($exten, $lineNum);
						}
					}
					unset($this->extenListRedirID[$exten][$line]);
					break;
				}
			}
			if (0 == count($this->extenListRedirID[$exten])) {
				unset($this->extenListRedirID[$exten]);
			}
		}
	}

	private function cleanAllLine($uniqueID) {
		$mainExten = $this->extenListCallID[$uniqueID]['MainExten'];
		$mainLineNum = $this->extenListCallID[$uniqueID]['MainExtenLineNum'];
		$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
		$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
		$this->log("cleanAllLine: uniqueID={$uniqueID} mainExten={$mainExten} mainLineNum={$mainLineNum} secondSideExten={$secondSideExten} secondLineNum={$secondLineNum}".PHP_EOL, self::logTrace);
		# Если есть другие отслеживаемые exten, то их тоже оповестим
		foreach ($this->extenList as $to => $properties) {
			foreach ($properties as $line => $lineProperties) {
				if ($lineProperties['UniqueID'] == $uniqueID) {
					if (!($to == $mainExten && $line == $mainLineNum)) {
						if (!($to == $secondSideExten && $line == $secondLineNum)) {
							$this->sendDialEvent(['Exten' => $to, 'LineNum' => $line, 
									'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'], 
									'EndCallStatusTxt' => self::DialStatusTxt[$this->answer['DialStatus']] ]);
						}
					}
				}
			}
		}
		foreach ($this->extenListChannelID as $contextID => $uid) {
			if ($uniqueID == $uid) {
				unset($this->extenListChannelID[$contextID]);
			}
		}
		unset($this->extenListCallID[$uniqueID]);
		foreach ($this->extenListRedirID as $to => $properties) {
			foreach ($properties as $line => $lineProperties) {
				if ($mainExten == $lineProperties['MainExten'] && $mainLineNum == $lineProperties['MainExtenLineNum']) {
					if ($uniqueID != $lineProperties['UniqueID']) {
						foreach ($this->extenListChannelID as $contextID => $uid) {
							if ($lineProperties['UniqueID'] == $uid) {
								unset($this->extenListChannelID[$contextID]);
							}
						}
						unset($this->extenListCallID[$lineProperties['UniqueID']]);
					}
					unset($this->extenListRedirID[$to][$line]);
				}
			}
			if (0 == count($this->extenListRedirID[$to])) {
				unset($this->extenListRedirID[$to]);
			}
		}
		foreach ($this->extenListCallID as $id => $mainLine) {
			if ($mainLine['MainExten'] == $mainExten && $mainLine['MainExtenLineNum'] == $mainLineNum) {
				$Found = 0;
				foreach ($this->extenListRedirID as $to => $properties) {
					foreach ($properties as $line => $lineProperties) {
						if ($id == $lineProperties['UniqueID']) {
							$Found = 1;
						}
					}
				}
				if (0 == $Found) {
					foreach ($this->extenListChannelID as $contextID => $uid) {
						if ($id == $uid) {
							unset($this->extenListChannelID[$contextID]);
						}
					}
					unset($this->extenListCallID[$id]);
				}
			}
		}
		$this->cleanExten($mainExten, $mainLineNum);
		foreach ($this->extenList as $to => $properties) {
			foreach ($properties as $line => $lineProperties) {
				if ($uniqueID == $lineProperties['UniqueID']) {
					$this->cleanExten($to, $line);
				}
			}
		}
	}
	
	private function cleanExten($exten, $lineNum) {
		$this->log("cleanExten: exten={$exten} lineNum={$lineNum}".PHP_EOL, self::logTrace);
		$this->extenList[$exten][$lineNum]['From'] = '';
		$this->extenList[$exten][$lineNum]['To'] = '';
		$this->extenList[$exten][$lineNum]['Direction'] = '';
		$this->extenList[$exten][$lineNum]['CallTimeFrom'] = '';
		$this->extenList[$exten][$lineNum]['TalkTimeFrom'] = '';
		$this->extenList[$exten][$lineNum]['SecondSideExten'] = '';
		$this->extenList[$exten][$lineNum]['SecondSideLineNum'] = '';
		$this->extenList[$exten][$lineNum]['CallerIDName'] = '';
		$this->extenList[$exten][$lineNum]['Status'] = self::extenStatusIdle;
		$this->extenList[$exten][$lineNum]['StatusTxt'] = $this->getStatusTxt($this->extenList[$exten][$lineNum]['Status']);
		$this->extenList[$exten][$lineNum]['UniqueID'] = '';
		$this->extenList[$exten][$lineNum]['AnsweredExten'] = '';
		if (self::magicLineNum == $lineNum) {
			return;
		}
		$maxUsedLine = $lineNum;
		foreach ($this->extenList[$exten] as $line => $properties) {
			if ($maxUsedLine < $line && $properties['Status'] != self::extenStatusIdle) {
				$maxUsedLine = $line;
			}
		}
		foreach ($this->extenList[$exten] as $line => $properties) {
			if ($maxUsedLine < $line) {
				unset($this->extenList[$exten][$line]);
            }
		}
	}

	private function saveCDR($event) {
		if (isset($event['Exten']) && isset($event['LineNum']) && isset($this->extenList[$event['Exten']][$event['LineNum']])) {
			$ret = $this->extenList[$event['Exten']][$event['LineNum']];
			foreach ($event as $key => $value) {
				$ret[$key] = $value;
			}
		} else {
			$ret = $event;
		}
		if (isset($event['TalkTimeFrom']) && isset($event['EndCallStatus']) && $event['TalkTimeFrom'] == '' && $event['EndCallStatus'] == 'ANSWER') {
			$event['EndCallStatus'] = 'VOICEMAIL';
		}
		$this->log("saveCDR ->\n", self::logInfo, $ret, 1);
		/*
		 * Call 'saveCDR' user function
		 */
		if (isset($this->callbackFunc['saveCDR'])) {
			try {
				call_user_func_array($this->callbackFunc['saveCDR'], array($ret));
			} catch (\Throwable $e) {
				$this->log($e->getMessage(), self::logInfo);
			}
		}
	}

	private function sendDialEvent($event) {
		if (isset($event['Exten']) && isset($event['LineNum']) && isset($this->extenList[$event['Exten']][$event['LineNum']])) {
			$ret = $this->extenList[$event['Exten']][$event['LineNum']];
			foreach ($event as $key => $value) {
				$ret[$key] = $value;
			}
		} else {
			$ret = $event;
		}
		if (isset($ret['Exten']) && isset($ret['LineNum']) 
				&& (!isset($ret['Direction']) || (isset($ret['Direction']) && $ret['Direction'] == 'out'))) {
			foreach ($this->extenListRedirID as $to => $properties) {
				if ($ret['Exten'] == $to || (isset($ret['To']) && $ret['To'] == $to) || (isset($ret['From']) && $ret['From'] == $to)) {
					continue;
				}
				foreach ($properties as $line => $lineProperties) {
					if (($ret['Exten'] == $lineProperties['MainExten'] && $ret['LineNum'] == $lineProperties['MainExtenLineNum'])) {
						$ret['Redir'][$to]['LineNum'] = $line;
						$ret['Redir'][$to]['Status'] = $lineProperties['Status'];
						$ret['Redir'][$to]['StatusTxt'] = $this->getStatusTxt($lineProperties['Status']);
					}
				}
			}
		}
		$this->log("sendDialEvent ->\n", self::logInfo, $ret, 1);
		/*
		 * Call 'sendDialEvent' user function
		 */
		if (isset($this->callbackFunc['sendDialEvent'])) {
			try {
				call_user_func_array($this->callbackFunc['sendDialEvent'], array($ret));
			} catch (\Throwable $e) {
				$this->log($e->getMessage(), self::logInfo);
			}
		}
		/*
		 * Call getAllExtenStatus user functions
		 */
		if (isset($this->callbackFunc['getAllExtenStatus'])) {
			try {
				call_user_func_array($this->callbackFunc['getAllExtenStatus'], array($ret, $this->extenList));
			} catch (\Throwable $e) {
				$this->log($e->getMessage(), self::logInfo);
			}
		}
		/*
		 * Call getAllLine0Status user functions
		 */
		if (isset($this->callbackFunc['getAllLine0Status'])) {
			try {
				call_user_func_array($this->callbackFunc['getAllLine0Status'], array($ret, array_combine(array_keys($this->extenList), array_column(array_column($this->extenList, 0), 'Status'))));
			} catch (\Throwable $e) {
				$this->log($e->getMessage(), self::logInfo);
			}
		}
	}

	private function extractExten($str) {
		$to = false;
		if (0 === strncmp($str, 'SIP', 3)) {
			$to = substr($str, 4, strrpos($str, '-') - 4);
		} elseif (0 === strncmp($str, 'Local', 5)) {
			$str = substr($str, 6, strpos($str, '@') - 6);
		}
		if (is_numeric($str)) {
			return $str;
		}
		if (0 === strncmp($str, 'FMPR', 4)) {
			$to = substr($str, 5);
		} elseif (0 === strncmp($str, 'FMGL', 4)) {
			$to = substr($str, 5);
		} elseif (0 === strncmp($str, 'LC', 2)) {
			$to = substr($str, 3);
		}

		if ($to !== false && strpos($to, '#')) {
			$to = substr($to, 0, strpos($to, '#'));
		}

		return $to;
	}

	private function extractChannelID($str) {
		if (false === ($posStart = strrpos($str, '-'))) {
			return false;
		}
		if (false === ($posEnd = strpos($str, ';', $posStart))) {
			if (false === ($posEnd = strpos($str, '<ZOMBIE>', $posStart))) {
				return substr($str, $posStart+1);
			} else {
				return substr($str, $posStart+1, $posEnd-$posStart-1);
			}
		} else {
			return substr($str, $posStart+1, $posEnd-$posStart-1);
		}
	}

	private function getAsteriskBlock() {
		$ret = [];
		$this->answer = $ret;
		while (true) {
			while (is_resource($this->fp) && !feof($this->fp)) {
				$fpRead = array($this->fp);
				$fpWrite = NULL;
				$fpExcept = NULL;
				if (false === ($numChangedStreams = stream_select($fpRead, $fpWrite, $fpExcept, self::timeoutSec, self::timeoutUsec))) {
					return false;

				} elseif ($numChangedStreams == 0) {
					$this->ping();

				} else {
					if (false === ($line = fgets($this->fp))) {
						continue;
					}
					if ('' == trim($line)) {
						break;
					}
					if (false === ($pos = strpos($line, ':'))) {
						continue;
					}
					$ret[substr($line, 0, $pos)] = trim(substr($line, $pos + 1));
				}
			}
			if (!is_resource($this->fp) || feof($this->fp)) {
				return false;
			}
			if (empty($ret)) {
				continue;
			}
			if ('' == $this->actionID || (isset($ret['ActionID']) && $ret['ActionID'] == $this->actionID)) {
				break;
			}
		}
		$this->answer = $ret;
		return $ret;
	}

	private function getStatusTxt($status = 0) {
		$ret = '';
		$sep = '';
		if (self::extenStatusNaN == $status) {
			return self::StatusTxt[self::extenStatusNaN];
		}
		if (self::extenStatusIdle == $status) {
			return self::StatusTxt[self::extenStatusIdle];
		}
		if (0 != ($status & (self::extenStatusMaskStd ^ 255))) {
			$status &= (self::extenStatusMaskStd ^ 255);
		}
		for ($i = 1; $i < 255; $i *= 2) {
			if (0 < ($i & $status)) {
				$ret .= $sep.self::StatusTxt[$i];
				$sep = ', ';
			}
		}
		return $ret;
	}
	
	private function logStatus($str = '', $debLvl = self::logDebug) {
		$this->log('=== Status start ========================================================='.PHP_EOL, $debLvl);
		$this->log('EventBlock from Asterisk ->'.PHP_EOL, $debLvl, $this->answer, 1);
		if ('' != $str) {
			$this->log($str.PHP_EOL, $debLvl);
		}
		$this->log('extenListCheckBusy: '.PHP_EOL, $debLvl, $this->extenListCheckBusy);
		$this->log('extenListChannelID: '.PHP_EOL, $debLvl, $this->extenListChannelID);
		$this->log('extenListCallID: '.PHP_EOL, $debLvl, $this->extenListCallID);
		$this->log('extenListRedirID: '.PHP_EOL, $debLvl, $this->extenListRedirID);
		$this->log('extenList: '.PHP_EOL, $debLvl, $this->extenList);
		$this->log('=== Status end ==========================================================='.PHP_EOL, $debLvl);
	}

	private function log($str, $debLvl = self::logInfo, $debValue = '', $indent = 0) {
		if ($debLvl > $this->Debug) {
			return;
		}
		echo date('[Y-m-d H:i:s]').' '.$str;
		if ($debValue != '') {
			$this->print_r_reverse(print_r($debValue, true), $indent);
			echo "\n";
		}
	}

	private function connect() {
		$errno = '';
		$errstr = '';

		while (false === ($this->fp = @fsockopen($this->host, $this->port, $errno, $errstr, 3))) {
			$this->log('Cant connect', self::logDebug);
			sleep (1);
		}
		stream_set_timeout($this->fp, self::timeoutSec, self::timeoutUsec);
		$this->actionID = rand();
		# Open connetion and auth
		fwrite($this->fp, "Action: login\r\n");
		fwrite($this->fp, "Username: {$this->user}\r\n");
		fwrite($this->fp, "Secret: {$this->pass}\r\n");
		fwrite($this->fp, "ActionID: {$this->actionID}\r\n");
		# fwrite($this->fp, "Events: system,call,log,verbose,command,agent,user,config,dtmf,reporting,cdr,dialplan,originate\r\n");
		fwrite($this->fp, "Events: call\r\n");
		fwrite($this->fp, "\r\n");
		$this->getAsteriskBlock();
		if ('Success' != $this->answer['Response']) {
			$this->log('Auth failed', self::logInfo);
			fclose($this->fp);
			$this->fp = false;
			return false;
		}
		return true;
	}

	private function ping() {
		$this->log('ping', self::logTrace);
		fwrite($this->fp, "Action: ping\r\n");
		fwrite($this->fp, "ActionID: {$this->actionID}\r\n");
		fwrite($this->fp, "\r\n");
	}

	/* Modified function from https://www.php.net/manual/ru/function.print-r.php#121259 */
	private function print_r_reverse($in, $indent = 0, $lvl = 0) {
		$lines = explode("\n", trim($in));
		if (trim($lines[0]) != 'Array') {
			// bottomed out to something that isn't an array
			echo "'$in'";
			return 1;
		} else {
			// this is an array, lets parse it
			if (preg_match("/(\s{5,})\(/", $lines[1], $match)) {
				// this is a tested array/recursive call to this function
				// take a set of spaces off the beginning
				$spaces = $match[1];
				$spaces_length = strlen($spaces);
				$lines_total = count($lines);
				for ($i = 0; $i < $lines_total; $i++) {
					if (substr($lines[$i], 0, $spaces_length) == $spaces) {
						$lines[$i] = substr($lines[$i], $spaces_length);
					}
				}
			}
			array_shift($lines); // Array
			array_shift($lines); // (
			array_pop($lines); // )
			$in = implode("\n", $lines);
			// make sure we only match stuff with 4 preceding spaces (stuff for this array and not a nested one)
			preg_match_all("/^\s{4}\[(.+?)\] \=\> /m", $in, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
			$pos = array();
			$previous_key = '';
			$in_length = strlen($in);
			// store the following in $pos:
			// array with key = key of the parsed array's item
			// value = array(start position in $in, $end position in $in)
			foreach ($matches as $match) {
				$key = $match[1][0];
				$start = $match[0][1] + strlen($match[0][0]);
				$pos[$key] = array($start, $in_length);
				if ($previous_key != '') $pos[$previous_key][1] = $match[0][1] - 1;
				$previous_key = $key;
			}
			$ret = 0;
			foreach ($pos as $key => $where) {
				// recursively see if the parsed out value is an array too
				if ($ret == 0) {
					if ($lvl == 0) {
						echo str_repeat("\t", $indent+$lvl);
					}
					if ($lvl != 0) {
						echo "\n".str_repeat("\t", $indent+$lvl).'[';
					}
				} elseif ($ret == 1) {
					echo ",\n".str_repeat("\t", $indent+$lvl);
					if ($res == 0) {
					}
				}
				echo "'$key' => ";
				$ret = 1;
				$res = self::print_r_reverse(substr($in, $where[0], $where[1] - $where[0]), $indent, $lvl+1);
			}
			if ($lvl != 0) {
				echo "]";
			}
		}
		if ($lvl == 0) {
			echo "\n";
		}
		return 0;
	} 
}
