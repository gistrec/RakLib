<?php

declare(strict_types=1);

namespace raklib\server;


use raklib\RakLib;
use raklib\utils\InternetAddress;

class RakLibServer {
	// Зарегестрирован ли сервер
	public $isRegister = false;

	//** @var InternetAddress */
	private $rakLibAddress;
	private $serverAddress;

	/** @var bool */
	protected $shutdown = false;

	protected $loaderPath;

	public $isMain = true;

	protected $maxMtuSize = 1492;

	public $UDPServerSocket;
	public $socket;

	public $logger;

	/**
	 * @param \ThreadedLogger $logger
	 * @param InternetAddress $serverAddress
	 * @param InternetAddress $raklibAddress
	 */
	public function __construct(\ThreadedLogger $logger, InternetAddress $serverAddress, 
								InternetAddress $raklibAddress, bool $isMain){
		$this->logger = $logger;

		// Адрес этого сервера
		$this->serverAddress = $serverAddress;
		// Раклиб адрес
		$this->rakLibAddress = $raklibAddress;

		$this->isMain = $isMain;	
	}

	public function transfer($player) {
	    // LoginPacket
		$buffer = chr(RakLib::PACKET_SEND_LOGIN);
		$parts = explode(".", (string)$player->getAddress());
		assert(count($parts) === 4, "Wrong number of parts in IPv4 IP, expected 4, got " . count($parts));
		foreach($parts as $b){
			$buffer .= chr((~((int) $b)) & 0xff);
		}
		$buffer .= pack("n", $player->getPort());
		// Конец
		$buffer .= $player->loginPacket;
		$this->sendToRakLib($buffer);

		// ChunkRequestPacket
		$buffer = chr(RakLib::PACKET_SEND_CHUNK_REQUEST);
		$parts = explode(".", (string)$player->getAddress());
		assert(count($parts) === 4, "Wrong number of parts in IPv4 IP, expected 4, got " . count($parts));
		foreach($parts as $b){
			$buffer .= chr((~((int) $b)) & 0xff);
		}
		$buffer .= pack("n", $player->getPort());
		// Конец
		$buffer .= $player->requestChunkRadiusPacket;
		$this->sendToRakLib($buffer);
	}

	public function registerRakLibClient() {
		$buffer = chr(RakLib::PACKET_AUTH_REQUEST) . 
				RakLib::REGISTER_SERVER_KEY . ($this->isMain);
		$this->sendToRakLib($buffer);
	}

	public function isShutdown() : bool{
		return $this->shutdown === true;
	}

	public function shutdown() : void{
		$this->shutdown = true;
		// TODO: Переместить игроков куда-нить
	}

	public function isRunning() {
		return !($this->shutdown === true);
	}

	// Добавляем к пакету id этого сервера первым байтом
	public function sendToRakLib($packet) {
		$this->UDPServerSocket->writePacket($packet, $this->rakLibAddress->ip,
			$this->rakLibAddress->port);
	}

	public function readPacketFromRakLib() {
		$this->UDPServerSocket->readPacket($buffer, $address->ip, $address->port);
	}

	public function shutdownHandler(){
		if($this->shutdown !== true){
			var_dump("RakLib crashed!");
			// TODO: Послать пакет EMERGENCY_SHUTDOWN
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
