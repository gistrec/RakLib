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

namespace raklib\protocol;

#ifndef COMPILE
use raklib\utils\Binary;
#endif
use raklib\utils\BinaryStream;
use raklib\utils\InternetAddress;

#include <rules/RakLibPacket.h>

abstract class Packet extends BinaryStream{
	public static $ID = -1;

	/** @var float|null */
	public $sendTime;

	protected function getString() : string{
		return $this->get($this->getShort());
	}

	protected function getAddress() : InternetAddress{
		$version = $this->getByte();
		$addr = ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff) . "." . ((~$this->getByte()) & 0xff);
		$port = $this->getShort();
		return new InternetAddress($addr, $port);
	}

	protected function putString(string $v) : void{
		$this->putShort(strlen($v));
		$this->put($v);
	}

	protected function putAddress(InternetAddress $address) : void{
		$this->putByte(4);
		$parts = explode(".", $address->ip);
		assert(count($parts) === 4, "Wrong number of parts in IPv4 IP, expected 4, got " . count($parts));
		foreach($parts as $b){
			$this->putByte((~((int) $b)) & 0xff);
		}
		$this->putShort($address->port);
	}

	public function encode() : void{
		$this->reset();
		$this->encodeHeader();
		$this->encodePayload();
	}

	protected function encodeHeader() : void{
		$this->putByte(static::$ID);
	}

	abstract protected function encodePayload() : void;

	public function decode() : void{
		$this->offset = 0;
		$this->decodeHeader();
		$this->decodePayload();
	}

	protected function decodeHeader() : void{
		$this->getByte(); //PID
	}

	abstract protected function decodePayload() : void;

	public function clean(){
		$this->buffer = null;
		$this->offset = 0;
		$this->sendTime = null;

		return $this;
	}
}
