<?php

/*
 * RakLib network library
 */

declare(strict_types=1);

namespace raklib\server;

use raklib\RakLib;
use raklib\scheduler\ServerScheduler;
use raklib\utils\InternetAddress;

class RakLibServer {
	const RAKLIB_TPS = 100;
	const RAKLIB_TIME_PER_TICK = 1 / self::RAKLIB_TPS;

	/**
	 * Ip адрес для общения с внешним миром
	 * @var InternetAddress
	 */
	private $externalAddress;
	/**
	 * Ip адрес для общения с серверами майна
	 * @var InternetAddress
	 */
	private $internalAddress;

	public $sessionManager;
	public $remoteServerManager;

	/** @var ServerScheduler */
	public $scheduler;

	/** @var int */
	protected $maxMtuSize;

	/**
	 * Начало работы сервера в microtime(true) * 1000
	 * @var int 
	*/
	protected $startTimeMS;
	
	/** @var int */
	public $ticks = 0;

	/** 
	 * TODO:
	 * Название сервера, отправляется в UnconnectedPing
	 * MCPE;Название;две версии протокола через пробел;версия сервера;текущий онлайн;всего онлайн
	 */
	public $name = "MCPE;§b§lRaklibTest;10 10;1.1.0;0;1000";

	public function __construct(InternetAddress $externalAddress,
								InternetAddress $internalAddress,
								int $maxMtuSize = 1492){
		$this->externalAddress = $externalAddress;
		$this->internalAddress = $internalAddress;

		$this->scheduler = new ServerScheduler();

		$this->serverId = mt_rand(0, PHP_INT_MAX);
		$this->maxMtuSize = $maxMtuSize;

		$this->startTimeMS = (int) (microtime(true) * 1000);

		$this->protocolVersion = RakLib::DEFAULT_PROTOCOL_VERSION;
	}

	public function shutdown() : void{
		// TODO: А нужно ли выключать?
	}

	/**
	 * Возвращает время работы RakNet сервера в милисекундах
	 * @return int
	 */
	public function getRakNetTimeMS() : int{
		return ((int) (microtime(true) * 1000)) - $this->startTimeMS;
	}

	/**
	 * Основная функция RakLib сервера
	 * Должна выполняться RAKLIB_TPS раз в секунду
	 * Обновляем sessionManager и removeServerManager
	 */
	private function tickProcessor() : void{
		// Обновляем время последнего тика
		$this->lastMeasure = microtime(true);

		while(true){
			// Получаем время - начало обработки входящих пакетов
			$start = microtime(true);
			// Обрабатываем все входящие пакеты от клиентов и от серверов
			while($this->sessionManager->receivePacket()){}
			while($this->remoteServerManager->receivePacket()){}

			// Получаем время за которое всё обработали
			$time = microtime(true) - $start;

			// Если они обработались слишком быстро - ненадолго останавливаемся
			if($time < self::RAKLIB_TIME_PER_TICK){
				@time_sleep_until(microtime(true) + self::RAKLIB_TIME_PER_TICK - $time);
			}

			$this->sessionManager->tick();
			$this->remoteServerManager->tick();
			
			$this->scheduler->mainThreadHeartbeat($this->ticks);

			$this->ticks += 1;
		}
	}

	// TODO:
	// Функция вызывается при выключении/краше раклиба 
	public function shutdownHandler() {
		echo "Производится отключение раклиба" . PHP_EOL;
		$this->sessionManager->raklibCrash();
		$this->remoteServerManager->raklibCrash();
	}

	public function run() : void{
		try{
			$externalSocket = new UDPServerSocket($this->externalAddress);
			$internalSocket = new UDPServerSocket($this->internalAddress);

			$this->remoteServerManager = new RemoteServerManager($this, $internalSocket);

			$this->sessionManager = new SessionManager($this, $externalSocket, 
				                                       $this->maxMtuSize);

			//register_shutdown_function([$this, "shutdownHandler"]);

			echo 'Раклиб запущен '    . PHP_EOL;
			echo 'Внешний адрес: '    . $externalSocket->getBindAddress() . PHP_EOL;
			echo 'Внутренний адрес: ' . $internalSocket->getBindAddress() . PHP_EOL; 

			$this->tickProcessor();
		}catch(\Throwable $e){
			var_dump($e);
		}
	}

}
