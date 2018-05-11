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

use pocketmine\utils\Binary;
use raklib\protocol\EncapsulatedPacket;
use raklib\utils\InternetAddress;
use raklib\RakLib;

class ServerHandler{

	/** @var RakLibServer */
	protected $server;
	/** @var ServerInstance */
	protected $instance;

	protected $reusableAddress;

	public function __construct(RakLibServer $server, ServerInstance $instance){
		$this->server = $server;
		$this->instance = $instance;

		$this->reusableAddress = new InternetAddress('0', 0);
	}

	// Отправляем данные на RakLib Server
	public function sendEncapsulated(string $identifier, EncapsulatedPacket $packet, int $flags = RakLib::PRIORITY_NORMAL) : void{
		$buffer = chr(RakLib::PACKET_ENCAPSULATED) . 
				  chr(strlen($identifier)) . $identifier . chr($flags) . 
				  $packet->toInternalBinary();
		$this->server->sendToRakLib($buffer);
	}

	// Отправляем данные на RakLib Server
	public function sendRaw(string $address, int $port, string $payload) : void{
		$buffer = chr(RakLib::PACKET_RAW) . 
				  chr(strlen($address)) . $address . Binary::writeShort($port) . 
				  $payload;
		$this->server->sendToRakLib($buffer);
	}

	// Отправляем данные на RakLib Server
	public function closeSession(string $identifier, string $reason) : void{
		$buffer = chr(RakLib::PACKET_CLOSE_SESSION) . 
				  chr(strlen($identifier)) . $identifier . chr(strlen($reason)) . $reason;
		$this->server->sendToRakLib($buffer);
	}

	// Отправляем данные на RakLib Server
	public function sendOption(string $name, $value) : void{
		$buffer = chr(RakLib::PACKET_SET_OPTION) . 
			      chr(strlen($name)) . $name . $value;
		$this->server->sendToRakLib($buffer);
	}

	// Отправляем данные на RakLib Server
	public function blockAddress(string $address, int $timeout) : void{
		$buffer = chr(RakLib::PACKET_BLOCK_ADDRESS) . 
				  chr(strlen($address)) . $address . Binary::writeInt($timeout);
		$this->server->sendToRakLib($buffer);
	}

	// Отправляем данные на RakLib Server
	public function unblockAddress(string $address) : void{
		$buffer = chr(RakLib::PACKET_UNBLOCK_ADDRESS) . 
				  chr(strlen($address)) . $address;
		$this->server->sendToRakLib($buffer);
	}

	// TODO: SHOTDOWN
	public function shutdown() : void{
		/*
		$buffer = chr(RakLib::PACKET_SHUTDOWN);
		$this->server->pushMainToThreadPacket($buffer);
		$this->server->shutdown();
		$this->server->synchronized(function(RakLibServer $server){
			$server->wait(20000);
		}, $this->server);
		$this->server->join();*/
	}

	// TODO: SHOTDOWN
	public function emergencyShutdown() : void{
		// $this->server->shutdown();
		// $this->server->pushMainToThreadPacket(chr(RakLib::PACKET_EMERGENCY_SHUTDOWN));
	}

	// В отличие от пакетов, которые идут на раклиб сервер
	// У входящих пакетов с раклиба первый байт - не id сервера
	// Поэтому используем стандартную функцию
	public function handlePacket() : bool{
		$address = $this->reusableAddress;
		if($this->server->socket->readPacket($packet, $address->ip, $address->port) > 0){
			echo('['.microtime(true) . '] Пришел пакет с раклиб сервера' . PHP_EOL);
			echo(substr(bin2hex($packet), 0, 50) . PHP_EOL);

			$identifier = $address->toString();
			
			$id = ord($packet{0});
var_dump("id:".$id);
			$offset = 1;
			if($id === RakLib::PACKET_ENCAPSULATED){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$flags = ord($packet{$offset++});
				$buffer = substr($packet, $offset);
var_dump("handleEncapsulated($identifier)");
				$this->instance->handleEncapsulated($identifier, EncapsulatedPacket::fromInternalBinary($buffer), $flags);
			}elseif($id === RakLib::PACKET_RAW){
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$payload = substr($packet, $offset);
var_dump("handleRaw($address, $port)");
				$this->instance->handleRaw($address, $port, $payload);
			}elseif($id === RakLib::PACKET_SET_OPTION){
				$len = ord($packet{$offset++});
				$name = substr($packet, $offset, $len);
				$offset += $len;
				$value = substr($packet, $offset);
				$this->instance->handleOption($name, $value);
			}elseif($id === RakLib::PACKET_OPEN_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$len = ord($packet{$offset++});
				$address = substr($packet, $offset, $len);
				$offset += $len;
				$port = Binary::readShort(substr($packet, $offset, 2));
				$offset += 2;
				$clientID = Binary::readLong(substr($packet, $offset, 8));
var_dump("openSession($identifier, $address, $port, $clientID)");
				$this->instance->openSession($identifier, $address, $port, $clientID);
			}elseif($id === RakLib::PACKET_CLOSE_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$len = ord($packet{$offset++});
				$reason = substr($packet, $offset, $len);
var_dump("closeSession($identifier, $reason)");
				$this->instance->closeSession($identifier, $reason);
			}elseif($id === RakLib::PACKET_INVALID_SESSION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
var_dump("Invalid session($identifier, $reason)");
				$this->instance->closeSession($identifier, "Invalid session");
			}elseif($id === RakLib::PACKET_ACK_NOTIFICATION){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$identifierACK = Binary::readInt(substr($packet, $offset, 4));
var_dump("notifyACK($identifier, $identifierACK)");
				$this->instance->notifyACK($identifier, $identifierACK);
			}elseif($id === RakLib::PACKET_REPORT_PING){
				$len = ord($packet{$offset++});
				$identifier = substr($packet, $offset, $len);
				$offset += $len;
				$pingMS = Binary::readInt(substr($packet, $offset, 4));
var_dump("updatePing($identifier, $pingMS)");
				$this->instance->updatePing($identifier, $pingMS);
			}
echo PHP_EOL;
			return true;
		}

		return false;
	}
}
