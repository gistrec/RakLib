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

	const RAKLIB_TPS = 100;
	const RAKLIB_TIME_PER_TICK = 1 / self::RAKLIB_TPS;

	/** @var \SplFixedArray<Packet|null> */
	protected $packetPool;

	/** @var RakLibServer */
	protected $server;
	/** @var UDPServerSocket */
	public $externalSocket;
	/**
	 * Менеджер удаленных серверов
	 * @var RemoteServerManager
	 */
	public $remoteServerManager;

	/** @var int */
	protected $receiveBytes = 0;
	/** @var int */
	protected $sendBytes = 0;

	/** @var Session[] */
	protected $sessions = [];

	/** @var OfflineMessageHandler */
	protected $offlineMessageHandler;
	/** 
	 * Название сервера, отправляется в UnconnectedPing
	 * Структура: 
	 * MCPE;Название;две версии протокола через пробел;версия сервера;текущий онлайн;всего онлайн
	 */
	public $name = "MCPE;§b§lRaklibTest;10 10;1.1.0;0;1000";
	/** 
	* Лимит пакетов с одного адреса в течении 1го тика
	*/
	protected $packetLimit = 200;

	/** @var bool */
	protected $shutdown = false;

	/** @var int */
	protected $ticks = 0;

	/** 
	 * Время последнего тика раклиб сервера в милисекундах
	 * @var float
	 */
	protected $lastMeasure;

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
	 * Начало работы сервера в microtime(true) * 1000
	 * @var int 
	*/
	protected $startTimeMS;

	/** 
	 * Максимальный размер пакета
	 * @var int
	 */
	public $maxMtuSize;

	// Адрес, который будем перезаписывать при чтении пакетов
	protected $reusableAddress;

	public function __construct(RakLibServer $server,
								UDPServerSocket $externalSocket,
								RemoteServerManager $remoteServerManager,
								int $maxMtuSize){
		$this->server = $server;
		$this->externalSocket = $externalSocket;
		$this->remoteServerManager = $remoteServerManager;
		$this->remoteServerManager->setSessionManager($this);

		$this->startTimeMS = (int) (microtime(true) * 1000);
		$this->maxMtuSize = $maxMtuSize;

		$this->offlineMessageHandler = new OfflineMessageHandler($this, $remoteServerManager);

		$this->reusableAddress = clone $this->externalSocket->getBindAddress();
		
		$this->registerPackets();
		$this->run();
	}

	/**
	 * Возвращает время работы RakNet сервера в милисекундах
	 * @return int
	 */
	public function getRakNetTimeMS() : int{
		return ((int) (microtime(true) * 1000)) - $this->startTimeMS;
	}

	public function run() : void{
		$this->tickProcessor();
	}

	/**
	 * Основная функция RakLib сервера
	 * Должна выполняться RAKLIB_TPS раз в секунду
	 * В ней мы читаем входящие пакеты из сокета и
	 * отправляем на них ответ
	 */
	private function tickProcessor() : void{
		// Обновляем время последнего тика
		$this->lastMeasure = microtime(true);

		// Если сервер не остановлен
		while(!$this->shutdown){
			// Получаем время - начало обработки входящих пакетов
			$start = microtime(true);
			// Обрабатываем все входящие пакеты
			while($this->receivePacket()){}
			while($this->remoteServerManager->receiveStream()){}

			// Получаем время за которое обработались входящие пакеты
			$time = microtime(true) - $start;
			// Если они обработались слишком быстро - усыпляем скрипт
			if($time < self::RAKLIB_TIME_PER_TICK){
				@time_sleep_until(microtime(true) + self::RAKLIB_TIME_PER_TICK - $time);
			}
			$this->tick();
		}
	}

	/**
	 * Выполняется каждый 'тик' (вызывается из функции tickProcessor)
	 * Обновляем все сессии
	 * А так же каждую секунду уменьшаем время блокировки в $this->block
	 */
	private function tick() : void{
		$time = microtime(true);
		foreach($this->sessions as $session){
			$session->update($time);
		}

		$this->ipSec = [];

		// Каждую секунду
		if(($this->ticks % self::RAKLIB_TPS) === 0){
			// TODO: update statistic
			//$diff = max(0.005, $time - $this->lastMeasure);
			//$this->streamOption("bandwidth", serialize([
			//	"up" => $this->sendBytes / $diff,
			//	"down" => $this->receiveBytes / $diff
			//]));
			$this->lastMeasure = $time;
			$this->sendBytes = 0;
			$this->receiveBytes = 0;

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


		++$this->ticks;
	}

	
	/**
	 * Функция вызывается каждый 'тик' (вызывается из функции tickProcessor)
	 * Получаем данные из сокета
	 * И обрабаытваем их - получаем сессию, получаем Packet ID
	 */
	private function receivePacket() : bool{
		$address = $this->reusableAddress;

		// Получаем данные из сокета
		$len = $this->externalSocket->readPacket($buffer, $address->ip, $address->port);

		// Если данных нет
		// выходим из функции
		if($len === false){
			return false;
		}

		$this->receiveBytes += $len;
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
var_dump($buffer);
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
		}else{
			// TODO: WTF
			//$this->streamRaw($address, $buffer);
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
		$this->sendBytes += $this->externalSocket->writePacket($packet->buffer, $address->ip, $address->port);
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

	public function sessionExists(InternetAddress $address) : bool{
		return isset($this->sessions[$address->toString()]);
	}

	public function createSession(InternetAddress $address, int $clientId, int $mtuSize) : Session{
		// Проверка на наличие мест для сессии
		// И если нужно, освобождение неактивных
		$this->checkSessions();

		// Получаем сервер, к которому будет подключен новый игрок
		$server = $this->remoteServerManager->getMainServer();

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
			$session->remoteServer->closeSession($session);
		}
	}

	/*
	 * Удаление сессии на серверве
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

	public function getID() : int{
		return $this->server->getServerId();
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
