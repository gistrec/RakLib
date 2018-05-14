<?php

declare(strict_types=1);

namespace raklib\protocol;

use RakLib\RakLib;

class RakLibCrashPacket extends Packet{
	public static $ID = MessageIdentifiers::ID_RAKLIB_CRASH;

	/**
	 * Ключ авторизации, 16 байт
	 * @var int 8byte
	 */
	public $auth_key;

	protected function encodePayload() : void{
		$this->putString(RakLib::REGISTER_SERVER_KEY);
	}

	protected function decodePayload() : void{
		$this->auth_key = $this->getString();
	}

	// Проверка на совпадение ключей
	public function isValid() : bool{
		return RakLib::REGISTER_SERVER_KEY == $this->auth_key;
	}
}