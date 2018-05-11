<?php

declare(strict_types=1);

namespace raklib\server;


use raklib\protocol\EncapsulatedPacket;
use raklib\utils\InternetAddress;

use raklib\utils\Binary;
use raklib\RakLib;
/*
 * Класс представляет собой сущность mcpe сервера
 */
class RemoteServer {
	// Айди сервера
	public $id;

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
	/** @var UDPServerSocket */
	public $externalSocket;

	public function __construct(RemoteServerManager $remoteServerManager, 
		                        UDPServerSocket $internalSocket, 
		                        UDPServerSocket $externalSocket,
		                        InternetAddress $address, 
		                        int $id, bool $isMain
	){
		$this->remoteServerManager = $remoteServerManager;
		$this->internalSocket = $internalSocket;
		$this->externalSocket = $externalSocket;

		$this->address = $address;
		$this->id = $id;
		$this->main = $isMain;
	} 

	// Функция вызывается при получении пакета с сервера
	// 1 байт - Id сервера
	// 1 байт - Id пакета
	public function receiveStream($packet) : bool{
		$id = ord($packet{1});
		$offset = 2; // 1 байт - id сервера, 1 байт - id пакета
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
			$this->externalSocket->writePacket($payload, $address, $port);
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
		}else{
			echo "Unknown RakLib internal packet (ID 0x" . dechex($id) . ") received from server" . PHP_EOL;
		}
		return true;
	}

	// TODO: Нужно ли отправлять этот пакет?
	// Без него все итак всё хорошо работает
	public function notifyACK(Session $session, int $identifierACK) : void{
		$this->streamACK($session->address->toString(), $identifierACK);
	}

	// Открываем сессию
	// Для этого отправляем mcpe серверу PACKET_OPEN_SESSION паекет
	// И добавляем сессию в массив сессий текущего сервера
	public function openSession(Session $session){
		$identifier = $session->address->toString();
		var_dump("Добавили сессию с identifier: $identifier");
		$this->sessions[$identifier] = $session;
        $this->streamOpen($session);
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
		$this->sendToServer($buffer);
	}
	public function streamACK(string $identifier, int $identifierACK) : void{
		// ВЫЗЫВАЕМ НА СЕРВЕРЕ МАЙНА
		// notifyACK($identifier, $identifierACK);
		// echo '##############################' . PHP_EOL;
		// echo 'Call on mcpe server: notifyACK($identifier, $identifierACK);' . PHP_EOL;
		// echo '##############################' . PHP_EOL;
		$identifier = $session->address->toString();
		$buffer = chr(RakLib::PACKET_ACK_NOTIFICATION) . chr(strlen($identifier)) . 
				  $identifier . Binary::writeInt($identifierACK);
		$this->sendToServer($buffer);

	}
	public function streamOpen(Session $session) : void{
		// ВЫЗЫВАЕМ НА СЕРВЕРЕ МАЙНА
		// openSession($identifier, $address, $port, $clientID);
		// echo '##############################' . PHP_EOL;
		// echo 'Call on mcpe server: openSession($identifier, $address, $port, $clientID);' . PHP_EOL;
		// echo '##############################' . PHP_EOL;

		$address = $session->address;
		$identifier = $address->toString();
		$buffer = chr(RakLib::PACKET_OPEN_SESSION) . chr(strlen($identifier)) . 
				  $identifier . chr(strlen($address->ip)) . $address->ip . 
				  Binary::writeShort($address->port) . Binary::writeLong($session->getID());
		$this->sendToServer($buffer);
	}
	public function streamInvalid(string $identifier) : void{
		// ВЫЗЫВАЕМ НА СЕРВЕРЕ
		// closeSession($identifier, "Invalid session");
		// echo '##############################' . PHP_EOL;
		// echo 'Call on mcpe server: сloseSession($identifier, "Invalid session");' . PHP_EOL;
		// echo '##############################' . PHP_EOL;
		var_dump("Сессия инвалид");
		$buffer = chr(RakLib::PACKET_INVALID_SESSION) . chr(strlen($identifier)) . $identifier;
		$this->sendToServer($buffer);

	}
	public function streamClose(string $identifier, string $reason) : void{
		// ВЫЗЫВАЕМ НА СЕРВЕРЕ
		// closeSession($identifier, $reason);
		// echo '##############################' . PHP_EOL;
		// echo 'Call on mcpe server: closeSession($identifier, $reason);' . PHP_EOL;
		// echo '##############################' . PHP_EOL;
		$buffer = chr(RakLib::PACKET_CLOSE_SESSION) . chr(strlen($identifier)) . 
				  $identifier . chr(strlen($reason)) . $reason;
		$this->sendToServer($buffer);
	}
	public function streamRaw(InternetAddress $source, string $payload) : void{
		// ВЫЗЫВАЕМ НА СЕРВЕРЕ
		// handleRaw($address, $port, $payload);
		// echo '##############################' . PHP_EOL;
		// echo 'Call on mcpe server: handleRaw($address, $port, $payload);' . PHP_EOL;
		// echo '##############################' . PHP_EOL;
		$buffer = chr(RakLib::PACKET_RAW) . chr(strlen($source->ip)) . $source->ip . 
				  Binary::writeShort($source->port) . $payload;
		$this->sendToServer($buffer);
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
		$this->sendToServer($buffer);
	}

	public function sendToServer($packet) {
		$this->internalSocket->writePacket($packet, $this->address->ip, $this->address->port);
	}

}