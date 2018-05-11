<?php

/*
 * RakLib network library
 */

declare(strict_types=1);

namespace raklib\server;

use raklib\RakLib;
use raklib\utils\InternetAddress;

class RakLibServer {
	/**
	 * Ip адрес для общения с внешним миром
	 * @var InternetAddress
	 */
	private $externalAddress;
	/**
	 * Ip адрес для общения с серверами майна
	 * Входящие пакеты фильтруются по ip
	 * @var InternetAddress
	 */
	private $internalAddress;

	/** @var bool */
	protected $shutdown = false;

	/** @var int */
	protected $serverId = 0;
	/** @var int */
	protected $maxMtuSize;
	/** @var int */
	private $protocolVersion;

	public function __construct(InternetAddress $externalAddress,
								InternetAddress $internalAddress,
								int $maxMtuSize = 1492){
		$this->externalAddress = $externalAddress;
		$this->internalAddress = $internalAddress;

		$this->serverId = mt_rand(0, PHP_INT_MAX);
		$this->maxMtuSize = $maxMtuSize;

		$this->protocolVersion = RakLib::DEFAULT_PROTOCOL_VERSION;
	}

	public function isShutdown() : bool{
		return $this->shutdown === true;
	}

	public function shutdown() : void{
		$this->shutdown = true;
	}

	/**
	 * Returns the RakNet server ID
	 * @return int
	 */
	public function getServerId() : int{
		return $this->serverId;
	}

	public function getProtocolVersion() : int{
		return $this->protocolVersion;
	}

	public function run() : void{
		try{
			$externalSocket = new UDPServerSocket($this->externalAddress);
			$internalSocket = new UDPServerSocket($this->internalAddress);

			$remoteServerManager = new RemoteServerManager($externalSocket, 
														   $internalSocket);
			new SessionManager($this, $externalSocket, $remoteServerManager, $this->maxMtuSize);
			echo 'RakLib server start on ' . $socket->getBindAddress() . PHP_EOL; 
		}catch(\Throwable $e){
			var_dump($e);
		}
	}

}
