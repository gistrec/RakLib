<?php

/*
 * RakLib network library
 */

declare(strict_types=1);

namespace raklib\server;

use pocketmine\utils\Binary;
use raklib\protocol\ACK;
use raklib\protocol\AdvertiseSystem;
use raklib\protocol\Datagram;
use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\NACK;
use raklib\protocol\OfflineMessage;
use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\OpenConnectionRequest1;
use raklib\protocol\OpenConnectionRequest2;
use raklib\protocol\Packet;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPingOpenConnections;
use raklib\protocol\UnconnectedPong;
use raklib\RakLib;
use raklib\utils\InternetAddress;

class SessionManager{

	/** @var \SplFixedArray<Packet|null> */
	protected $packetPool;

	/** @var RakLibServer */
	public $server;
	/** @var UDPServerSocket */
	public $externalSocket;

	/** @var Session[] */
	protected $sessions = [];

	/** @var OfflineMessageHandler */
	protected $offlineMessageHandler;

	/** 
	* Лимит пакетов с одного адреса в течении 1го тика
	*/
	protected $packetLimit = 200;

	/** @var bool */
	protected $shutdown = false;

	/** 
	 * Заблокированные адреса
	 * @var float[] string (address) => float (unblock time) 
	*/
	protected $block = [];
	/** 
	 * Кол-во пакетов пришедших с одного адреса
	 * @var int[] string (address) => int (number of packets) 
	*/
	protected $ipSec = [];

	/** 
	 * Максимальный размер пакета
	 * @var int
	 */
	public $maxMtuSize;

	// Адрес, который будем перезаписывать при чтении пакетов
	protected $reusableAddress;

	public function __construct(RakLibServer $server,
								UDPServerSocket $externalSocket,
								int $maxMtuSize){
		$this->server = $server;
		$this->externalSocket = $externalSocket;

		$this->maxMtuSize = $maxMtuSize;

		$this->offlineMessageHandler = new OfflineMessageHandler($this);

		$this->reusableAddress = clone $this->externalSocket->getBindAddress();
		
		$this->registerPackets();
	}


	/**
	 * Выполняется каждый 'тик'
	 * Обновляем все сессии
	 * А так же каждую секунду уменьшаем время блокировки в $this->block
	 */
	public function tick() : void{
		$time = microtime(true);
		foreach($this->sessions as $session){
			$session->update($time);
		}

		$this->ipSec = [];

		// Каждую секунду
		if(($this->server->ticks % RakLibServer::RAKLIB_TPS) === 0){

			// Уменьшаем время блокировки для всех заблокированных клиентов
			if(count($this->block) > 0){
				asort($this->block);
				$now = microtime(true);
				foreach($this->block as $address => $timeout){
					if($timeout <= $now){
						unset($this->block[$address]);
					}else{
						break;
					}
				}
			}
		}
	}

	
	/**
	 * Функция вызывается каждый 'тик' (вызывается из функции tickProcessor)
	 * Получаем данные из сокета
	 * И обрабаытваем их - получаем сессию, получаем Packet ID
	 */
	public function receivePacket() : bool{
		$address = $this->reusableAddress;

		// Получаем данные из сокета
		$len = $this->externalSocket->readPacket($buffer, $address->ip, $address->port);

		// Если данных нет
		// выходим из функции
		if($len === false){
			return false;
		}

		// Todo: statistic
		// $this->receiveBytes += $len;
		
		// Если адрес заблокирован, т.е. его пакеты игнорируются
		// то выходим из функции
		if(isset($this->block[$address->ip])){
			return true;
		}

		// Если с этого адреса уже приходили пакеты
		if(isset($this->ipSec[$address->ip])){
			// Если кол-во пакетов больше, чем лимит
			if(++$this->ipSec[$address->ip] >= $this->packetLimit){
				// Блокируем адрес, с которого пришел пакет
				// И выходим из функции
				$this->blockAddress($address->ip);
				return true;
			}
		}else{
			// Если с этого адреса еще не приходили пакеты
			// Говорим, что с этого адреса пришел один пакет
			$this->ipSec[$address->ip] = 1;
		}

		// Если длина пакета меньше единицы (Это как) 
		// Выходим из функции
		if($len < 1){
			return true;
		}

		// Получаем PacketID
		$pid = ord($buffer{0});

		// Получаем сессию по адресу
		$session = $this->getSession($address);
		// Если сессия получена
		if($session !== null){
			if(($pid & Datagram::BITFLAG_VALID) !== 0){
				if($pid & Datagram::BITFLAG_ACK){
					$session->handlePacket(new ACK($buffer));
				}elseif($pid & Datagram::BITFLAG_NAK){
					$session->handlePacket(new NACK($buffer));
				}else{
					$session->handlePacket(new Datagram($buffer));
				}
			}else{
				var_dump("Ignored unconnected packet from $address due to session already opened (0x" . dechex($pid) . ")");
				$this->blockAddress($address->ip, 5);
			}
		// Если сессия не найдена, но пакет нужен для создания сесии
		}elseif(($pk = $this->getPacketFromPool($pid, $buffer)) instanceof OfflineMessage){
			/** @var OfflineMessage $pk */
			$pk->decode();
			// Проверка на magic в пакете
			// Если неправильное - блокируем адрес на 5 секунд
			if(!$pk->isValid()){
				var_dump("Packet magic is invalid");
				var_dump("Received garbage message from $address (" . $e->getMessage() . "): " . bin2hex($pk->buffer));
				$this->blockAddress($address->ip, 5);

			// Если пакет правильный - передаем его offlineMessageHandler
			}elseif(!$this->offlineMessageHandler->handle($pk, $address)){
				var_dump("Unhandled unconnected packet " . get_class($pk) . " received from $address");
			}
		}elseif(($pid & Datagram::BITFLAG_VALID) !== 0 and ($pid & 0x03) === 0){
			// Loose datagram, don't relay it as a raw packet
			// RakNet does not currently use the 0x02 or 0x01 bitflags on any datagram header, so we can use
			// this to identify the difference between loose datagrams and packets like Query.
			var_dump("Ignored connected packet from $address due to no session opened (0x" . dechex($pid) . ")");
		}
		//	var_dump("Packet from $address (" . strlen($buffer) . " bytes): 0x" . bin2hex($buffer));
		//	$this->blockAddress($address->ip, 5);

		return true;
	}

	/**
	 * Отправляем пакет клиенту 
	 * @param  Packet
	 * @param  InternetAddress
	 */
	public function sendPacket(Packet $packet, InternetAddress $address) : void{
		$packet->encode();
		$this->externalSocket->writePacket($packet->buffer, $address->ip, $address->port);
	}

	/**
	 * Заблокировать адрес на определенное время
	 *Адрес помещается в $this->block
	 */
	public function blockAddress(string $address, int $timeout = 300) : void{
		$final = microtime(true) + $timeout;
		if(!isset($this->block[$address]) or $timeout === -1){
			if($timeout === -1){
				$final = PHP_INT_MAX;
			}else{
				var_dump("Blocked $address for $timeout seconds");
			}
			$this->block[$address] = $final;
		}elseif($this->block[$address] < $final){
			$this->block[$address] = $final;
		}
	}

	public function unblockAddress(string $address) : void{
		unset($this->block[$address]);
		var_dump("Unblocked $address");
	}

	/**
	 * @param InternetAddress $address
	 *
	 * @return Session|null
	 */
	public function getSession(InternetAddress $address) : ?Session{
		return $this->sessions[$address->toString()] ?? null;
	}

	public function createSession(InternetAddress $address, int $clientId, int $mtuSize) : Session{
		// Проверка на наличие мест для сессии
		// И если нужно, освобождение неактивных
		$this->checkSessions();

		// Получаем сервер, к которому будет подключен новый игрок
		$server = $this->server->remoteServerManager->getMainServer();

		$session = new Session($this, clone $address, $server, $clientId, $mtuSize);
		$this->sessions[$address->toString()] = $session;
		var_dump("Создали сессию для $address c MTU=$mtuSize");

		return $session;
	}

	/**
	 * Удаление сессии из раклиба
	 */
	public function removeSession(Session $session, string $reason = "unknown") : void{
		$id = $session->getAddress()->toString();
		if(isset($this->sessions[$id])){
			$this->sessions[$id]->close();
			var_dump("Удалили сессию $id");
			// TODO: 
			// ВЫЗЫВАЕМ НА СЕРВЕРЕ МАЙНА closeSession($identifier, $reason);
			if ($session->remoteServer != null) {
				$session->remoteServer->streamСloseSession($session);
			}
		}
	}

	/*
	 * Сессия сперва удалилась на сервере
	 * Поэтому просто убираем из массива сессий
	 */
	public function removeSessionInternal(Session $session) : void{
		unset($this->sessions[$session->getAddress()->toString()]);
	}

	private function checkSessions() : void{
		if(count($this->sessions) > 4096){
			foreach($this->sessions as $i => $s){
				if($s->isTemporal()){
					unset($this->sessions[$i]);
					if(count($this->sessions) <= 4096){
						break;
					}
				}
			}
		}
	}

	// Функция вызывается при краше раклиба
	// Закрываем все сессии
	public function raklibCrash() {
		foreach ($this->sessions as $session) {
			$this->removeSession($session, "Краш прокси");
		}
	}

	/**
	 * @param int    $id
	 * @param string $class
	 */
	private function registerPacket(int $id, string $class) : void{
		$this->packetPool[$id] = new $class;
	}

	/**
	 * @param int    $id
	 * @param string $buffer
	 *
	 * @return Packet|null
	 */
	public function getPacketFromPool(int $id, string $buffer = "") : ?Packet{
		$pk = $this->packetPool[$id];
		if($pk !== null){
			$pk = clone $pk;
			$pk->buffer = $buffer;
			return $pk;
		}

		return null;
	}

	private function registerPackets() : void{
		$this->packetPool = new \SplFixedArray(256);

		$this->registerPacket(UnconnectedPing::$ID, UnconnectedPing::class);
		$this->registerPacket(UnconnectedPingOpenConnections::$ID, UnconnectedPingOpenConnections::class);
		$this->registerPacket(OpenConnectionRequest1::$ID, OpenConnectionRequest1::class);
		$this->registerPacket(OpenConnectionReply1::$ID, OpenConnectionReply1::class);
		$this->registerPacket(OpenConnectionRequest2::$ID, OpenConnectionRequest2::class);
		$this->registerPacket(OpenConnectionReply2::$ID, OpenConnectionReply2::class);
		$this->registerPacket(UnconnectedPong::$ID, UnconnectedPong::class);
		$this->registerPacket(AdvertiseSystem::$ID, AdvertiseSystem::class);
	}
}