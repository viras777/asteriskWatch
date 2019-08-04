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
	const VERSION = '1.1.0';
	
	/**
	 * No logging.
	 *
	 * @var int
	 */
	public const logNone = 0;

	/**
	 * Log only sendDial event.
	 *
	 * @var int
	 */
	public const logInfo = 1;


	/**
	 * Log full, sendDial && internal status
	 *
	 * @var int
	 */
	public const logDebug = 2;

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
		self::extenStatusRinging => 'Звонит',
		self::extenStatusOnHold => 'На удержании',
		self::extenStatusInUseRedir => 'Разговаривает (переадресация)',
		self::extenStatusRingingRedir => 'Звонит (переадресация)',
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

	#   Redir Status
	# AddRedir		- добавили будущий редирект к номеру
	# RingRedir		- зазвонил редирект
	# ConnRedir		- произошло соединение с редиректом

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

	# Array of initial exten
	private $initExten = [];

	public function __construct($host = '127.0.0.1', $port = 5038, $user = '', $pass = '')
	{
		$this->host = $host;
		$this->port = $port;
		$this->user = $user;
		$this->pass = $pass;
		return $this->connect();
	}

	public function setExtenList($arr) {
		$this->initExten = [];
		foreach ($arr as $exten) {
			$this->initExten[] = $exten;
		}
	}
	
	public function watch()
	{
		if (false === $this->fp) {
			return false;
		}

		while (true) {
			unset($extenListChannelID);
			unset($extenListCallID);
			unset($extenListRedirID);
			$extenListChannelID = [];
			$extenListCallID = [];
			$extenListRedirID = [];
			# Get list of monitoring exten.
			foreach ($this->initExten as $exten) {
				$this->cleanExten($exten, 0);
			}

			# Get exten status
			foreach ($this->extenList as $exten => &$properties) {
				fwrite($this->fp, "Action: ExtensionState\r\n");
				fwrite($this->fp, "ActionID: {$this->actionID}\r\n");
				fwrite($this->fp, "Exten: {$exten}\r\n");
				fwrite($this->fp, "\r\n");
				$this->getAsteriskBlock();
				$this->log("event ->\n", $this->answer, self::logDebug, 1);
				$properties['0']['Status'] = $this->answer['Status'];
				$properties['0']['StatusTxt'] = $this->getStatusTxt($properties['0']['Status']);
				$this->sendDialEvent(['Exten' => $exten, 'LineNum' => 0 ]);
			}
			unset($properties);
			$this->logStatus('Start from here.', self::logDebug);
			
			# Main loop
			while (!feof($this->fp)) {
				$this->actionID = '';
				$this->getAsteriskBlock();
				switch ($this->answer['Event']) {
					case 'Newstate':
					case 'ExtensionStatus':
						$lineNum = 0;
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
							$exten = $this->extenListCallID[$this->extenListChannelID[$channelID]]['Exten'];
							$lineNum = $this->extenListCallID[$this->extenListChannelID[$channelID]]['LineNum'];
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
							if (isset($this->extenListRedirID[$exten][$lineNum]) 
									&& $exten != $this->extenListRedirID[$exten][$lineNum]['MainExten']
									&& !isset($this->extenList[$exten])) {
								$this->extenListRedirID[$exten][$lineNum]['Status'] = $status;
								break;
							}
						} else {
							break;
						}
						$this->log('TEST event ->', $this->answer, self::logDebug);
						if (!isset($this->extenListRedirID[$exten][$lineNum])) {
							break;
						}
						$mainExten = $this->extenListRedirID[$exten][$lineNum]['MainExten'];
						$mainLineNum = $this->extenListRedirID[$exten][$lineNum]['MainExtenLineNum'];
						# Если основной exten уже в этом статусе, то только меняем статус текущего exten
						if (($this->extenList[$mainExten][$mainLineNum]['Status'] & self::extenStatusMaskStd) == $status 
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
						# Если касается основного соединения. Если статус уже или стал extenStatusIdle, то меняем не тут, а в евенте Dial
						if ((isset($this->extenList[$exten]) || $this->extenList[$mainExten][$mainLineNum]['To'] == $exten)
								&& (self::extenStatusIdle != $this->extenList[$mainExten][$mainLineNum]['Status'] && self::extenStatusIdle != $status)) {
							if (($this->extenList[$mainExten][$mainLineNum]['Status'] & self::extenStatusMaskStd) != $status) {
								if ($this->extenList[$mainExten][$mainLineNum]['To'] == $exten) {
									$newStatus = $status;
								} else {
									# Новый статус в стандартных битах только, остальные сохраним как было
									$newStatus = $status | ((self::extenStatusMaskStd ^ 255) & $this->extenList[$mainExten][$mainLineNum]['Status']);
								}
								if ('' == $this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] && self::extenStatusInUse == ($newStatus & self::extenStatusMaskStd)) {
									$this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] = time();
									$this->extenList[$mainExten][$mainLineNum]['AnsweredExten'] = $exten;
								}
								$this->extenListRedirID[$exten][$lineNum]['Status'] = $newStatus;
								$this->extenListRedirID[$mainExten][$mainLineNum]['Status'] = $newStatus;
								$this->extenList[$mainExten][$mainLineNum]['Status'] = $newStatus;
								$this->extenList[$mainExten][$mainLineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
								# Повторим тоже самое для secondSideExten
								$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
								$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
								if (0 != $secondSideExten && isset($this->extenList[$secondSideExten][$secondLineNum])
										&& ($this->extenList[$secondSideExten][$secondLineNum]['Status'] & self::extenStatusMaskStd) != $status) {
									$newStatus = $status | ((self::extenStatusMaskStd ^ 255) & $this->extenList[$secondSideExten][$secondLineNum]['Status']);
									if ('' == $this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom'] && self::extenStatusInUse == ($newStatus & self::extenStatusMaskStd)) {
										$this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom'] = time();
										$this->extenList[$secondSideExten][$secondLineNum]['AnsweredExten'] = $exten;
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
								} else {
									$uid = 0;
								}
								if ($uid != 0) {
									foreach ($this->extenListRedirID as $to => $properties) {
										foreach ($properties as $line => $lineProperties) {
											if ($lineProperties['UniqueID'] == $uid) {
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
									# Новый статус в стандартных битах только, остальные сохраним как было
									$newStatus = $status | ((self::extenStatusMaskStd ^ 255) & $this->extenListRedirID[$exten][$lineNum]['Status']);
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
								elseif ($count > 1 && self::extenStatusRinging == ($newStatus & self::extenStatusMaskStd)) {
									$newStatus |= self::extenStatusRingingRedir;
									$this->extenListRedirID[$exten][$lineNum]['Status'] = $newStatus;
									if (isset($this->extenList[$exten])) {
										$this->cleanExten($exten, $lineNum);
										$this->extenList[$exten][$lineNum]['From'] = $this->extenList[$mainExten][$mainLineNum]['From'];
										$this->extenList[$exten][$lineNum]['To'] = $this->extenList[$mainExten][$mainLineNum]['To'];
										$this->extenList[$exten][$lineNum]['CallTimeFrom'] = time();
										if (0 != $this->extenList[$mainExten][$mainLineNum]['SecondSideExten']) {
											$this->extenList[$exten][$lineNum]['Direction'] = ('in' == $this->extenList[$mainExten][$mainLineNum]['Direction'] ? 'out' : 'in');
											$this->extenList[$exten][$lineNum]['SecondSideExten'] = $mainExten;
											$this->extenList[$exten][$lineNum]['SecondSideLineNum'] = $mainLineNum;
										}
										else {
											$this->extenList[$exten][$lineNum]['Direction'] = $this->extenList[$mainExten][$mainLineNum]['Direction'];
											$this->extenList[$exten][$lineNum]['SecondSideExten'] = 0;
											$this->extenList[$exten][$lineNum]['SecondSideLineNum'] = 0;
										}
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
								}
								# Если стало занято или начат разговор в редиректе
								elseif ($count > 1 && (self::extenStatusBusy == $newStatus || self::extenStatusInUse == $newStatus)) {
									if (self::extenStatusBusy == $newStatus) {
										$newStatus = ($this->extenList[$mainExten][$mainLineNum]['Status'] & self::extenStatusMaskStd) | self::extenStatusBusyRedir;
									}
									if (self::extenStatusInUse == $newStatus) {
										if (false != $channelID) {
											$uid = $this->extenListChannelID[$channelID];
										} elseif (isset($this->answer['Uniqueid'])) {
											$uid = $this->answer['Uniqueid'];
										} else {
											$uid = 0;
										}
										$pass = 1;
										if (0 != $uid) {
											$to = $this->extenList[$mainExten][$mainLineNum]['To'];
											foreach ($this->extenListRedirID[$to] as $line => $properties) {
												if ($uid == $properties['UniqueID']) {
													$pass = 0;
													break;
												}
											}
										}
										if ($pass == 1) {
											$newStatus |= self::extenStatusInUseRedir;
										}
										if ('' == $this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom']) {
											$this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] = time();
											$this->extenList[$mainExten][$mainLineNum]['AnsweredExten'] = $exten;
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
										$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum,
											'TalkRedir' => $exten ]);
										$this->logStatus('Changed main RedirID exten status. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$exten, self::logDebug);
									}
									# Повторим тоже самое для secondSideExten
									$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
									$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
									if (isset($this->extenList[$secondSideExten][$secondLineNum])
											&& ($this->extenList[$secondSideExten][$secondLineNum]['Status'] & self::extenStatusMaskStd) != $status) {
										$newStatus = $status | ((self::extenStatusMaskStd ^ 255) & $this->extenList[$secondSideExten][$secondLineNum]['Status']);
										if (self::extenStatusBusy == $newStatus) {
											$newStatus = ($this->extenList[$secondSideExten][$secondLineNum]['Status'] & self::extenStatusMaskStd) | self::extenStatusBusyRedir;
										}
										if (self::extenStatusInUse == $newStatus) {
											$newStatus |= self::extenStatusInUseRedir;
											if ('' == $this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom']) {
												$this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom'] = time();
												$this->extenList[$secondSideExten][$secondLineNum]['AnsweredExten'] = $exten;
											}
										}
										if ($this->extenList[$secondSideExten][$secondLineNum]['Status'] != $newStatus) {
											$this->extenList[$secondSideExten][$secondLineNum]['Status'] = $newStatus;
											$this->extenList[$secondSideExten][$secondLineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
											$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum,
												'TalkRedir' => $exten ]);
											$this->logStatus('Changed SecondSide main RedirID exten status. SecondExten='.$secondSideExten.' LineNum='.$secondLineNum.' TalkRedir='.$exten, self::logDebug);
										}
									}
								}
								# Если остался только 1 редирект у екстеншена (может быть и сам екстеншен остался)
								# extenStatusIdle будем обрабатывать только в событии Dial
								elseif (1 == $count && self::extenStatusIdle != $newStatus) {
									if (($this->extenList[$mainExten][$mainLineNum]['Status'] & self::extenStatusMaskStd) != $status) {
										if (self::extenStatusInUse == $newStatus && 0 != $this->extenListRedirID[$exten][$mainLineNum]['UniqueID']) {
											$newStatus |= self::extenStatusInUseRedir;
										}
										if (self::extenStatusBusy == $newStatus && 0 != $this->extenListRedirID[$exten][$mainLineNum]['UniqueID']) {
											$newStatus |= self::extenStatusBusyRedir;
										}
										if (self::extenStatusRinging == $newStatus && 0 != $this->extenListRedirID[$exten][$mainLineNum]['UniqueID']) {
											$newStatus |= self::extenStatusRingingRedir;
										}
										if ('' == $this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] && self::extenStatusInUse == ($newStatus & self::extenStatusMaskStd)) {
											$this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] = time();
											$this->extenList[$mainExten][$mainLineNum]['AnsweredExten'] = $exten;
										}
										$this->extenList[$mainExten][$mainLineNum]['Status'] = $newStatus;
										$this->extenList[$mainExten][$mainLineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
										$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum ]);
										$this->logStatus('Changed RedirID exten status. redirExten='.$exten.' LineNum='.$mainLineNum, self::logDebug);
										# Повторим тоже самое для secondSideExten
										$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
										$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
										if (isset($this->extenList[$secondSideExten][$secondLineNum])
												&& ($this->extenList[$secondSideExten][$secondLineNum]['Status'] & self::extenStatusMaskStd) != $status) {
											$newStatus = $status | ((self::extenStatusMaskStd ^ 255) & $this->extenList[$secondSideExten][$secondLineNum]['Status']);
											if (self::extenStatusInUse == $newStatus && 0 != $this->extenListRedirID[$exten][$secondLineNum]['UniqueID']) {
												$newStatus |= self::extenStatusInUseRedir;
											}
											if (self::extenStatusBusy == $newStatus && 0 != $this->extenListRedirID[$exten][$secondLineNum]['UniqueID']) {
												$newStatus |= self::extenStatusBusyRedir;
											}
											if (self::extenStatusRinging == $newStatus && 0 != $this->extenListRedirID[$exten][$secondLineNum]['UniqueID']) {
												$newStatus |= self::extenStatusRingingRedir;
											}
											if ('' == $this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom'] && self::extenStatusInUse == ($newStatus & self::extenStatusMaskStd)) {
												$this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom'] = time();
												$this->extenList[$secondSideExten][$secondLineNum]['AnsweredExten'] = $exten;
											}
											$this->extenList[$secondSideExten][$secondLineNum]['Status'] = $newStatus;
											$this->extenList[$secondSideExten][$secondLineNum]['StatusTxt'] = $this->getStatusTxt($newStatus);
											$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum ]);
											$this->logStatus('Changed RedirID SecondSide exten status. redirExten='.$secondSideExten.' LineNum='.$secondLineNum, self::logDebug);
										}
									}
								}
							}
						}

						break;
					case 'Dial':
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
									}
									else {
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
									$secondSideExten = $to;
								}

								$mainExten = 0;
								$mainLineNum = 0;
								$lineNum = 0;
								# Если звонили с внешнего номера и сработала переадресация, то номер переадресации должен быть в extenListRedirID
								if (0 == $exten && isset($this->extenListRedirID[$to])) {
									foreach ($this->extenListRedirID[$to] as $line => $properties) {
										if ($this->answer['UniqueID'] == $properties['UniqueID']) {
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
										if ($this->answer['UniqueID'] == $properties['UniqueID']) {
											$lineNum = $line;
											$mainExten = $properties['MainExten'];
											$mainLineNum = $properties['MainExtenLineNum'];
											break;
										}
									}
								}

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
											$this->extenListRedirID[$from][$lineNum2]['MainExten'] = $exten;
											$this->extenListRedirID[$from][$lineNum2]['MainExtenLineNum'] = $lineNum;
											$this->extenListRedirID[$from][$lineNum2]['UniqueID'] = $this->answer['UniqueID'];
											$this->extenListRedirID[$from][$lineNum2]['Status'] = self::extenStatusRinging;
										}
										$this->cleanExten($exten, $lineNum);
										$this->extenList[$exten][$lineNum]['Status'] = self::extenStatusRinging;
										$this->extenList[$exten][$lineNum]['StatusTxt'] = $this->getStatusTxt(self::extenStatusRinging);
										$this->extenList[$exten][$lineNum]['From'] = $from;
										$this->extenList[$exten][$lineNum]['To'] = $to;
										$this->extenList[$exten][$lineNum]['Direction'] = $dialDirection;
										$this->extenList[$exten][$lineNum]['CallTimeFrom'] = time();
										$this->extenList[$exten][$lineNum]['SecondSideExten'] = $secondSideExten;
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
											$this->extenListRedirID[$secondSideExten][$secondLineNum]['MainExten'] = $exten;
											$this->extenListRedirID[$secondSideExten][$secondLineNum]['MainExtenLineNum'] = $lineNum;
											$this->extenListRedirID[$secondSideExten][$secondLineNum]['UniqueID'] = $this->answer['UniqueID'];
											$this->extenListRedirID[$secondSideExten][$secondLineNum]['Status'] = self::extenStatusRinging; 
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
								if ((!isset($this->extenListRedirID[$exten]) && isset($this->extenListChannelID[$channelID]))
										|| (isset($this->extenListChannelID[$channelID]) && !isset($this->extenListCallID[$this->answer['UniqueID']]))) {
									$uniqueID = $this->extenListChannelID[$channelID];
									$mainExten = $this->extenListCallID[$uniqueID]['MainExten'];
									$mainLineNum = $this->extenListCallID[$uniqueID]['MainExtenLineNum'];
									# Тут специально не надо, будут дубли
									# $this->extenListChannelID[$destChannelID] = $this->answer['UniqueID'];
									$this->extenListCallID[$this->answer['UniqueID']]['MainExten'] = $mainExten;
									$this->extenListCallID[$this->answer['UniqueID']]['MainExtenLineNum'] = $mainLineNum;
									$this->extenListCallID[$this->answer['UniqueID']]['Exten'] = $to;
									$this->extenListCallID[$this->answer['UniqueID']]['LineNum'] = $lineNum;
									$this->logStatus('Find and add redir to main call. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$to.' from='.$from.' to='.$to.' dialDirection='.$dialDirection, self::logDebug);
									break;
								}
								if (isset($this->extenListChannelID[$channelID]) && !isset($this->extenListCallID[$this->answer['UniqueID']])) {
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
									if ($pass == 1) {
/*
										# Есть исключение, когда звонок с неотслеживаемого расширения, нет возможности связать по UniqueID
										# Единственный вариант - эвристика. В event смотрим поле CallerIDNum, оно равно вызывающей стороне,
										# этот номер смотрим в extenListRedirID, берём там Status (он должен быть Ringing),
										# берём MainExten и MainExtenLineNum, идём в extenList, там Status == Ringing && Direction == in && From == $from
										# Если всё так, то это наш случай.
										if (isset($this->answer['CallerIDNum']) && isset($this->extenListRedirID[$this->answer['CallerIDNum']])) {
											foreach ($this->extenListRedirID[$this->answer['CallerIDNum']] as $line => $properties) {
												if (self::extenStatusRinging == $properties['Status']) {
													if (self::extenStatusRinging == $this->extenList[$properties['MainExten']][$properties['MainExtenLineNum']]['Status']
															&& 'in' == $this->extenList[$properties['MainExten']][$properties['MainExtenLineNum']]['Direction']
															&& $from == $this->extenList[$properties['MainExten']][$properties['MainExtenLineNum']]['From']) {
Наверно надо не exten присваивать, а mainExten														$exten = $properties['MainExten'];
														$lineNum = 0;
														foreach ($this->extenListRedirID[$to] as $line2 => $properties2) {
															if ($properties['MainExten'] == $properties2['MainExten']
																	&& $properties['MainExtenLineNum'] == $properties2['MainExtenLineNum']
																	&& self::extenStatusRinging == $properties2['Status']) {
																break;
															}
															$lineNum++;
														}
														$mainExten = $properties['MainExten'];
														$mainLineNum = $properties['MainExtenLineNum'];
													}
												}
											}
										}
*/
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
												'EndCallStatusTxt' => "Local call is finished with '{$this->answer['DialStatus']}'(Idle)" ]);
											# Повторим тоже самое для secondSideExten
											$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
											$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
											if (isset($this->extenList[$secondSideExten][$secondLineNum])) {
												$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum, 
													'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'], 
													'EndCallStatusTxt' => "Local call is finished with '{$this->answer['DialStatus']}'(Idle)" ]);
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
												'EndCallStatusTxt' => 'Call is finished (Idle)', ]);
										# Повторим тоже самое для secondSideExten
										$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
										$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
										if (isset($this->extenList[$secondSideExten][$secondLineNum]) && isset($this->extenListRedirID[$secondSideExten][$secondLineNum])) {
											$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum,
													'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'],
													'EndCallStatusTxt' => 'Call is finished (Idle)' ]);
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
											if ('' == $this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom']) {
												$this->extenList[$mainExten][$mainLineNum]['TalkTimeFrom'] = time();
												$this->extenList[$mainExten][$mainLineNum]['AnsweredExten'] = $exten;
											}
											$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum,
												'ConnRedir' => $exten ]);
											# Повторим тоже самое для secondSideExten
											$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
											$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
											if (isset($this->extenList[$secondSideExten][$secondLineNum])) {
												if ('' == $this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom']) {
													$this->extenList[$secondSideExten][$secondLineNum]['TalkTimeFrom'] = time();
													$this->extenList[$secondSideExten][$secondLineNum]['AnsweredExten'] = $exten;
												}
												$this->extenList[$secondSideExten][$secondLineNum]['Status'] = $this->extenList[$mainExten][$mainLineNum]['Status'];
												$this->extenList[$secondSideExten][$secondLineNum]['StatusTxt'] = $this->getStatusTxt($this->extenList[$mainExten][$mainLineNum]['Status']);
												$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum,
													'ConnRedir' => $exten ]);
											}
										}
										$this->cleanRedirLine($this->answer['UniqueID'], $exten, $lineNum);
										$this->logStatus('Close one of additional Redir exten. mainExten='.$mainExten.' mainLineNum='.$mainLineNum.' exten='.$exten.' LineNum='.$lineNum.' count='.$count, self::logDebug);

										break;
									}

									# Вызов завершён
									$this->sendDialEvent(['Exten' => $mainExten, 'LineNum' => $mainLineNum,
										'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'], 
										'EndCallStatusTxt' => 'Call is finished with "Answer"(Idle)' ]);
									# Повторим тоже самое для secondSideExten
									$secondSideExten = $this->extenList[$mainExten][$mainLineNum]['SecondSideExten'];
									$secondLineNum = $this->extenList[$mainExten][$mainLineNum]['SecondSideLineNum'];
									if (isset($this->extenList[$secondSideExten][$secondLineNum])) {
										$this->sendDialEvent(['Exten' => $secondSideExten, 'LineNum' => $secondLineNum,
											'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'],
											'EndCallStatusTxt' => 'Call is finished with "Answer"(Idle)' ]);
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
			}
			fclose($this->fp);
			$this->connect();
		}
	}

	private function cleanRedirLine($uniqueID, $exten, $lineNum) {
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
				$status = 'extenStatusIdle';
			}
			foreach ($this->extenListRedirID[$exten] as $line => $properties) {
				if ($mainExten == $properties['MainExten'] && $mainLineNum == $properties['MainExtenLineNum']) {
					if (isset($this->extenList[$exten][$lineNum]) && self::extenStatusIdle != $this->extenList[$exten][$lineNum]['Status']
							&& !($exten == $mainExten && $line == $mainLineNum)) {
						if (!($exten == $this->extenList[$exten][$lineNum]['SecondSideExten'] 
								&& $line == $this->extenList[$exten][$lineNum]['SecondSideLineNum'])) {
							$this->sendDialEvent(['Exten' => $exten, 'LineNum' => $lineNum,
									'CallTimeTo' => time(), 'EndCallStatus' => $status,
									'EndCallStatusTxt' => 'Redir exten line end' ]);
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
		# Если есть другие отслеживаемые exten, то их тоже оповестим
		foreach ($this->extenList as $to => $properties) {
			foreach ($properties as $line => $lineProperties) {
				if ($lineProperties['UniqueID'] == $uniqueID) {
					if (!($to == $mainExten && $line == $mainLineNum)) {
						if (!($to == $secondSideExten && $line == $secondLineNum)) {
							$this->sendDialEvent(['Exten' => $to, 'LineNum' => $line, 
									'CallTimeTo' => time(), 'EndCallStatus' => $this->answer['DialStatus'], 
									'EndCallStatusTxt' => "Local call is finished with '{$this->answer['DialStatus']}'(Idle)" ]);
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
		if (!isset($this->extenList[$exten][$lineNum]) || 1 >= count($this->extenList[$exten])) {
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
			if (0 != $lineNum) {
				if (!isset($this->extenList[$exten][0]) || $this->extenList[$exten][0]['Status'] == self::extenStatusIdle) {
					$this->extenList[$exten][0] = $this->extenList[$exten][$lineNum];
					unset($this->extenList[$exten][$lineNum]);
				}
			}
		}
		else {
			unset($this->extenList[$exten][$lineNum]);
		}
	}

	private function saveCDR($event) {
		if (isset($event['Exten']) && isset($event['LineNum']) && isset($this->extenList[$event['Exten']][$event['LineNum']])) {
			$ret = $this->extenList[$event['Exten']][$event['LineNum']];
			foreach ($event as $key => $value) {
				$ret[$key] = $value;
			}
		}
		else {
			$ret = $event;
		}
		if (isset($event['TalkTimeFrom']) && isset($event['EndCallStatus']) && $event['TalkTimeFrom'] == '' && $event['EndCallStatus'] == 'ANSWER') {
			$event['EndCallStatus'] = 'VOICEMAIL';
		}
		$this->log("saveCDR ->\n", $ret, self::logInfo, 1);
		return $ret;
	}

	private function sendDialEvent($event) {
		if (isset($event['Exten']) && isset($event['LineNum']) && isset($this->extenList[$event['Exten']][$event['LineNum']])) {
			$ret = $this->extenList[$event['Exten']][$event['LineNum']];
			foreach ($event as $key => $value) {
				$ret[$key] = $value;
			}
		}
		else {
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
		$this->log("sendDialEvent ->\n", $ret, self::logInfo, 1);
		return $ret;
	}

	private function extractExten($str) {
		if (0 === strncmp($str, 'SIP', 3)) {
			$to = substr($str, 4, strrpos($str, '-') - 4);
		} elseif (0 === strncmp($str, 'Local', 5)) {
			$to = substr($str, 6, strpos($str, '@') - 6);
			if (0 === strncmp($to, 'FMPR', 4)) {
				$to = substr($to, 5);
			} elseif (0 === strncmp($to, 'FMGL', 4)) {
				$to = substr($to, 5);
			} elseif (0 === strncmp($to, 'LC', 2)) {
				$to = substr($to, 3);
			}

			if (strpos($to, '#')) {
				$to = substr($to, 0, strpos($to, '#'));
			}
		} else {
			$to = false;
		}

		return $to;
	}

	private function extractChannelID($str) {
		if (false === ($posStart = strrpos($str, '-'))) {
			return false;
		}
		if (false === ($posEnd = strpos($str, ';', $posStart))) {
			return substr($str, $posStart+1);
		}
		else {
			return substr($str, $posStart+1, $posEnd-$posStart-1);
		}
	}

	private function getAsteriskBlock() {
		$ret = [];
		while (true) {
			while (!feof($this->fp)) {
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
		$this->log('=== Status start ========================================================='.PHP_EOL, '', $debLvl);
		$this->log('EventBlock from Asterisk ->'.PHP_EOL, $this->answer, $debLvl, 1);
		if ('' != $str) {
			$this->log($str.PHP_EOL, '', $debLvl);
		}
		$this->log('extenListChannelID: '.PHP_EOL, $this->extenListChannelID, $debLvl);
		$this->log('extenListCallID: '.PHP_EOL, $this->extenListCallID, $debLvl);
		$this->log('extenListRedirID: '.PHP_EOL, $this->extenListRedirID, $debLvl);
		$this->log('extenList: '.PHP_EOL, $this->extenList, $debLvl);
		$this->log('=== Status end ==========================================================='.PHP_EOL, '', $debLvl);
	}

	private function log($str, $log = '', $debLvl = self::logInfo, $indent = 0) {
		if ($debLvl > $this->Debug) {
			return;
		}
		echo date('[Y-m-d H:i:s]').' '.$str;
		if ($log != '') {
			$this->print_r_reverse(print_r($log,true), $indent);
			echo "\n";
		}
	}

	private function connect() {
		$errno = '';
		$errstr = '';

		if (false === ($this->fp = fsockopen($this->host, $this->port, $errno, $errstr, 3))) {
			return;
		}
		stream_set_timeout($this->fp, 0, 500000);
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
			$this->log('Auth failed');
			fclose($this->fp);
			$this->fp = false;
		}
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
				}
				elseif ($ret == 1) {
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
