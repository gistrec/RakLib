<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace raklib\server;

use raklib\RakLib;
use raklib\utils\InternetAddress;

class RakLibServer {
	// Адрес раклиб
	private $rakLibAddress;
	private $serverAddress;

	/** @var bool */
	protected $shutdown = false;

	protected $loaderPath;

	/** @var int */
	// TODO: Получение serverId
	public $serverId = 0;

	protected $maxMtuSize = 1492;

	public $UDPServerSocket;
	public $socket;

	/**
	 * @param \ThreadedLogger $logger
	 * @param string          $autoloaderPath Path to Composer autoloader
	 * @param InternetAddress $address
	 * @param int             $maxMtuSize
	 * @param int|null        $overrideProtocolVersion Optional custom protocol version to use, defaults to current RakLib's protocol
	 */
	public function __construct(\ThreadedLogger $logger, $autoloaderPath, $port, $ip, $overrideProtocolVersion = null){
		// Раклиб Адрес
		$this->rakLibAddress = new InternetAddress('192.168.0.100', 19133);
		$this->serverAddress = new InternetAddress($ip, $port);

		$this->loaderPath = $autoloaderPath;

		//$this->run();
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
		}catch(\Throwable $e){
			var_dump($e);
		}
	}

}
