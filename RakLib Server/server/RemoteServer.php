<?php

declare(strict_types=1);

namespace raklib\server;


use raklib\protocol\EncapsulatedPacket;
use raklib\protocol\RegisterRemoteServerAccepted;
use raklib\utils\InternetAddress;

use raklib\utils\Binary;
use raklib\RakLib;
/*
 * Класс представляет собой сущность mcpe сервера
 */
class RemoteServer {
	// Главный сервер или нет
	public $main;

	// Адрес сервера
	// @var InternetAddress
	public $address;

	// Сессии на этом сервере
	public $sessions = [];

	/** @var RemoteServerManager */
	public $remoteServerManager;

	/** @var UDPServerSocket */
	public $internalSocket;

	/** 
	 * Время последнего обновления
	 * @var [type]
	 */
	private $lastUpdate;

	/** 
	 * Время последнего пришедшего пакета
	 * @var integer
	 */
	private $lastPingTime = -1;


	public function __construct(RemoteServerManager $remoteServerManager, 
		                        UDPServerSocket $internalSocket, 
		                        InternetAddress $address, 
		                        bool $isMain
	){
		$this->remoteServerManager = $remoteServerManager;
		$this->internalSocket = $internalSocket;

		$this->address = $address;
		$this->main = $isMain;

		$this->lastUpdate = microtime(true);
	} 

	public function update(float $time) {
		if($this->lastUpdate + 10 < $time){
			echo "Сервер " . $this->address->toString() . " недоступен больше 10 секунд" . PHP_EOL;
			$this->emergencyShutdown();

			return;
		}
	}

	// Функция вызывается при таймауте сервера
	// Или при если пришел пакет PACKET_EMERGENCY_SHUTDOWN
	// В этом случае нужно перекинуть игроков на другой сервер
	public function emergencyShutdown() {
		var_dump("TODO: Переместить игроков на другой main сервер");
		$this->remoteServerManager->closeServer($this->address);
	}

	// Функция вызывается, если с сервера пришел пакет PACKET_SHUTDOWN
	// Значит на сервере нет игроков. Все они переброшены на другой сервер
	public function safeShutdown() {
		$this->remoteServerManager->closeServer($this->address);
	}

	// Функция вызывается при получении пакета с сервера
	public function receivePacket($packet) : bool{
		$this->lastUpdate = microtime(true);

		$id = ord($packet{0});
		$offset = 1; // 1 байт - id сервера, 1 байт - id пакета
		if($id === RakLib::PACKET_ENCAPSULATED){
			$len = ord($packet{$offset++});
			$identifier = substr($packet, $offset, $len);
			$offset += $len;
			// Ищем сессию
			$session = $this->sessions[$identifier] ?? null;
			if($session !== null and $session->isConnected()){
				$flags = ord($packet{$offset++});
				$buffer = substr($packet, $offset);
				$session->addEncapsulatedToQueue(EncapsulatedPacket::fromInternalBinary($buffer), $flags);
			}else{
var_dump("Сессия с identifier $identifier не найдена");
				$this->streamInvalid($identifier);
			}
		}elseif($id === RakLib::PACKET_RAW){
			$len = ord($packet{$offset++});
			$address = substr($packet, $offset, $len);
			$offset += $len;
			$port = Binary::readShort(substr($packet, $offset, 2));
			$offset += 2;
			$payload = substr($packet, $offset);
			$address = new InternetAddress($address, $port);
			$this->server->sessionManager->sendPacket($payload, $address);
		}elseif($id === RakLib::PACKET_CLOSE_SESSION){
			$len = ord($packet{$offset++});
			$identifier = substr($packet, $offset, $len);
			if(isset($this->sessions[$identifier])){
				$this->sessions[$identifier]->flagForDisconnection();
			}else{
var_dump("PACKET_CLOSE_SESSION");
				$this->streamInvalid($identifier);
			}
		}elseif($id === RakLib::PACKET_INVALID_SESSION){
			$len = ord($packet{$offset++});
			$identifier = substr($packet, $offset, $len);
			if(isset($this->sessions[$identifier])){
				$this->removeSession($this->sessions[$identifier]);
			}
		}elseif($id === RakLib::PACKET_SET_OPTION){
			/*$len = ord($packet{$offset++});
			$name = substr($packet, $offset, $len);
			$offset += $len;
			$value = substr($packet, $offset);
			switch($name){
				case "name":
					$this->name = $value;
					break;
				case "portChecking":
					$this->portChecking = (bool) $value;
					break;
				case "packetLimit":
					$this->packetLimit = (int) $value;
					break;
			}*/
		}elseif($id === RakLib::PACKET_BLOCK_ADDRESS){
			$len = ord($packet{$offset++});
			$address = substr($packet, $offset, $len);
			$offset += $len;
			$timeout = Binary::readInt(substr($packet, $offset, 4));
			$this->blockAddress($address, $timeout);
		}elseif($id === RakLib::PACKET_UNBLOCK_ADDRESS){
			$len = ord($packet{$offset++});
			$address = substr($packet, $offset, $len);
			$this->unblockAddress($address);
		}elseif($id === RakLib::PACKET_SHUTDOWN){
			/*foreach($this->sessions as $session){
				$this->removeSession($session);
			}
			$this->externalSocket->close();
			$this->shutdown = true;*/
		}elseif($id === RakLib::PACKET_EMERGENCY_SHUTDOWN){
			/*$this->shutdown = true;*/
		}elseif($id === 0x87) {
			// Если сервер еще раз просит зарегестрироваться
			// Говорим, что он уже зарегестрирован
			$pk = new RegisterRemoteServerAccepted();
			$pk->encode();
			$this->sendPacket($pk->buffer);
		}else{
			echo "Unknown RakLib internal packet (ID 0x" . dechex($id) . ") received from server" . PHP_EOL;
		}
		return true;
	}

	// ВЫЗЫВАЕМ НА СЕРВЕРЕ МАЙНА otifyACK($identifier, $identifierACK);
	public function notifyACK(Session $session, int $identifierACK) : void{
		$identifier = $session->address->toString();

		$buffer = chr(RakLib::PACKET_ACK_NOTIFICATION) . chr(strlen($identifier)) . 
				  $identifier . Binary::writeInt($identifierACK);
		$this->sendPacket($buffer);
	}

    // Закрываем сессию
    // Для этого отправляем mcpe серверу PACKET_CLOSE_SESSION пакет
    // И удаляем сессию из массива сессий текущего сервера
    public function closeSession(Session $session) {
    	$identifier = $session->address->toString();
    	unset($this->sessions[$identifier]);
    	$this->streamClose($identifier, 'RakLib ping timeout');
    }

	public function streamPingMeasure(Session $session, int $pingMS) : void{
		// ВЫЗЫВАЕМ НА СЕРВЕРЕ МАЙНА 
		// updatePing($identifier, $pingMS);
		// echo '##############################' . PHP_EOL;
		// echo 'Call on mcpe server: updatePing($identifier, $pingMS)' . PHP_EOL;
		// echo '##############################' . PHP_EOL;
		$identifier = $session->address->toString();
		$buffer = chr(RakLib::PACKET_REPORT_PING) . chr(strlen($identifier)) .
		 		  $identifier . Binary::writeInt($pingMS);
		$this->sendPacket($buffer);
	}

	// ВЫЗЫВАЕМ НА СЕРВЕРЕ МАЙНА openSession($identifier, $address, $port, $clientID);
	public function openSession(Session $session) : void{
		$address = $session->address;
		$identifier = $address->toString();
		$this->sessions[$identifier] = $session;

		var_dump("Добавили сессию с identifier: $identifier");

		$buffer = chr(RakLib::PACKET_OPEN_SESSION) . chr(strlen($identifier)) . 
				  $identifier . chr(strlen($address->ip)) . $address->ip . 
				  Binary::writeShort($address->port) . Binary::writeLong($session->getID());
		$this->sendPacket($buffer);
	}

	public function streamInvalid(string $identifier) : void{
		// ВЫЗЫВАЕМ НА СЕРВЕРЕ
		// closeSession($identifier, "Invalid session");
		// echo '##############################' . PHP_EOL;
		// echo 'Call on mcpe server: сloseSession($identifier, "Invalid session");' . PHP_EOL;
		// echo '##############################' . PHP_EOL;
		var_dump("Сессия инвалид");
		$buffer = chr(RakLib::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
		$this->sendPacket($buffer);

	}

	public function streamClose(string $identifier, string $reason) : void{
		// ВЫЗЫВАЕМ НА СЕРВЕРЕ
		// closeSession($identifier, $reason);
		// echo '##############################' . PHP_EOL;
		// echo 'Call on mcpe server: closeSession($identifier, $reason);' . PHP_EOL;
		// echo '##############################' . PHP_EOL;
		$buffer = chr(RakLib::PACKET_CLOSE_SESSION) . chr(strlen($identifier)) . 
				  $identifier . chr(strlen($reason)) . $reason;
		$this->sendPacket($buffer);
	}
	public function streamRaw(InternetAddress $source, string $payload) : void{
		// ВЫЗЫВАЕМ НА СЕРВЕРЕ
		// handleRaw($address, $port, $payload);
		// echo '##############################' . PHP_EOL;
		// echo 'Call on mcpe server: handleRaw($address, $port, $payload);' . PHP_EOL;
		// echo '##############################' . PHP_EOL;
		$buffer = chr(RakLib::PACKET_RAW) . chr(strlen($source->ip)) . $source->ip . 
				  Binary::writeShort($source->port) . $payload;
		$this->sendPacket($buffer);
	}
	public function streamEncapsulated(Session $session, EncapsulatedPacket $packet, int $flags = RakLib::PRIORITY_NORMAL) : void{
		// ВЫЗЫВАЕМ НА СЕРВЕРЕ
		// handleEncapsulated($identifier, EncapsulatedPacket::fromInternalBinary($buffer), $flags);
		// echo '##############################' . PHP_EOL;
		// echo 'Call on mcpe server: handleEncapsulated($identifier, EncapsulatedPacket::fromInternalBinary($buffer), $flags);' . PHP_EOL;
		// echo '##############################' . PHP_EOL;
		$id = $session->address->toString();
		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . chr(strlen($id)) . $id . 
				  chr($flags) . $packet->toInternalBinary();
		$this->sendPacket($buffer);
	}

	public function sendPacket($packet) {
		$this->internalSocket->writePacket($packet, $this->address->ip, $this->address->port);
	}

}