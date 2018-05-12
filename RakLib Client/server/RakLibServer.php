<?php

declare(strict_types=1);

namespace raklib\server;


use raklib\RakLib;
use raklib\utils\InternetAddress;

class RakLibServer {
	//** @var InternetAddress */
	private $rakLibAddress;
	private $serverAddress;

	/** @var bool */
	protected $shutdown = false;

	protected $loaderPath;

	/** @var int */
	public $serverId = 0;

	public $isMain = true;

	protected $maxMtuSize = 1492;

	public $UDPServerSocket;
	public $socket;

	/**
	 * @param \ThreadedLogger $logger
	 * @param string          $autoloaderPath Path to Composer autoloader
	 * @param InternetAddress $server
	 * @param InternetAddress $raklib
	 */
	public function __construct(\ThreadedLogger $logger, $autoloaderPath, InternetAddress $server, InternetAddress $raklib, bool isMain){
		// Адрес этого сервера
		$this->serverAddress = $server;
		// Раклиб адрес
		$this->rakLibAddress = $raklib;

		$this->loaderPath = $autoloaderPath;

		$this->isMain = $isMain;
	}

	public function registerRakLibClient() {
		$buffer = chr(0x87) . chr(strlen(RakLib::REGISTER_SERVER_KEY)) .
				RakLib::REGISTER_SERVER_KEY . isMain;
		$this->sendToRakLib($buffer);
	}

	public function isShutdown() : bool{
		return $this->shutdown === true;
	}

	public function shutdown() : void{
		$this->shutdown = true;
	}

	public function isRunning() {
		return !($this->shutdown === true);
	}

	/**
	 * Returns the RakNet server ID
	 * @return int
	 */
	public function getServerId() : int{
		return $this->serverId;
	}

	// Добавляем к пакету id этого сервера первым байтом
	public function sendToRakLib($packet) {
		$packet = chr($this->serverId) . $packet;
		$this->UDPServerSocket->writePacket($packet, $this->rakLibAddress->ip,
			$this->rakLibAddress->port);
	}

	public function readPacketFromRakLib() {
		$this->UDPServerSocket->readPacket($buffer, $address->ip, $address->port);
	}

	public function shutdownHandler(){
		if($this->shutdown !== true){
			var_dump("RakLib crashed!");
		}
	}


	public function start() : void{
		try{
			//require __FILE__ . '/..';

			gc_enable();
			error_reporting(-1);
			ini_set("display_errors", '1');
			ini_set("display_startup_errors", '1');

			register_shutdown_function([$this, "shutdownHandler"]);


			$this->UDPServerSocket = new UDPServerSocket($this->serverAddress);
			$this->socket = $this->UDPServerSocket;

			$this->registerRakLibClient();
		}catch(\Throwable $e){
			var_dump($e);
		}
	}

}
