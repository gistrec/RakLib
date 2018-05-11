<?php

/*
 * RakLib network library
 */

declare(strict_types=1);

namespace raklib\server;

use raklib\protocol\IncompatibleProtocolVersion;
use raklib\protocol\OfflineMessage;
use raklib\protocol\OpenConnectionReply1;
use raklib\protocol\OpenConnectionReply2;
use raklib\protocol\OpenConnectionRequest1;
use raklib\protocol\OpenConnectionRequest2;
use raklib\protocol\UnconnectedPing;
use raklib\protocol\UnconnectedPong;
use raklib\utils\InternetAddress;

/*
 * Класс нужен для 'отлавливания' пакетов от клиентов,
 * для которых еще не была создана сессия, т.е.
 * Отлавливаем первые 3 сообщения вначале подключения
 * UnconnectedPing, OpenConnectionRequest1, OpenConnectionRequest2
 * И создаем сессию sessionManager::createSession()
 */
class OfflineMessageHandler{
	/** @var SessionManager */
	private $sessionManager;

	public function __construct(SessionManager $manager){
		$this->sessionManager = $manager;
	}

	public function handle(OfflineMessage $packet, 
		                   InternetAddress $address) : bool{
		
		// echo('Пришел пакет от клиента: ' . $address->toString() . '' . PHP_EOL);
		// echo(substr(bin2hex($packet->buffer), 0, 50) . PHP_EOL);
		// echo PHP_EOL;

		switch($packet::$ID){
			// Когда клиент шлет UnconnectedPing
			// Мы должны отправить ему UnconnectedPong
			//
			// Шлет: когда находится в меню выбора сервера
			case UnconnectedPing::$ID:
				/** @var UnconnectedPing $packet */
				$pk = new UnconnectedPong();
				$pk->serverID = $this->sessionManager->getID();
				$pk->pingID = $packet->pingID;
				$pk->serverName = $this->sessionManager->name;
				$this->sessionManager->sendPacket($pk, $address);
				return true;
			// Когда клиент шлет OpenConnectionRequest1
			// Мы должны отправить OpenConnectionReply1
			//
			// Первая стадия при подключении игрока к серверу
			case OpenConnectionRequest1::$ID:
				/** @var OpenConnectionRequest1 $packet */
				// NOTE: Зачем нужно проверять версию протокола? Протокола чего?
				// $serverProtocol = $this->sessionManager->getProtocolVersion();
				/*if($packet->protocol !== $serverProtocol){
					$pk = new IncompatibleProtocolVersion();
					$pk->protocolVersion = $serverProtocol;
					$pk->serverId = $this->sessionManager->getID();
					$this->sessionManager->sendPacket($pk, $address);
					var_dump("Refused connection from $address due to incompatible RakNet protocol version (expected $serverProtocol, got $packet->protocol)");
				}else{*/
					$pk = new OpenConnectionReply1();
					$pk->mtuSize = $packet->mtuSize + 28; //IP header size (20 bytes) + UDP header size (8 bytes)
					$pk->serverID = $this->sessionManager->getID();
					$this->sessionManager->sendPacket($pk, $address);
				//}
				return true;
			// Когда клиент шлет OpenConnectionRequest2
			// Мы должны отправить OpenConnectionReply2
			//
			// Вторая стадия при подключении игрока к серверу
			case OpenConnectionRequest2::$ID:
				/** @var OpenConnectionRequest2 $packet */
				// Max size, do not allow creating large buffers to fill server memory
				$mtuSize = min(abs($packet->mtuSize), $this->sessionManager->maxMtuSize);
				$pk = new OpenConnectionReply2();
				$pk->mtuSize = $mtuSize;
				$pk->serverID = $this->sessionManager->getID();
				$pk->clientAddress = $address;
				$this->sessionManager->sendPacket($pk, $address);
				$this->sessionManager->createSession($address, $packet->clientID, $mtuSize);
				return true;
		}
		return false;
	}

}