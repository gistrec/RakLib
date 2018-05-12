<?php

declare(strict_types=1);

namespace raklib\protocol;


use raklib\RakLib;

class RegisterRemoteServerRequest extends Packet{
	public static $ID = MessageIdentifiers::ID_REGISTER_REMOTE_SERVER_REQUEST;

	public $serverId;

	/**
	 * Ключ авторизации, 16 байт
	 * @var int 8byte
	 */
	public $auth_key;

	/**
	 * Главный ли это сервер
	 * Т.е. можно ли подключать игроков сразу к нему
	 * @var bool
	 */
	public $isMain;

	// TODO: Нужен ли адрес?
	/** @var InternetAddress */
	// public $address;

	protected function encodePayload() : void{
		// Первый байт - id сервера
		$this->putString(RakLib::REGISTER_SERVER_KEY);
		$this->putByte($this->isMain);
		//$this->putAddress($this->address);
	}

	protected function decodePayload() : void{
		$this->getByte();
		$this->auth_key = $this->getString();
		$this->isMain = $this->getBool();
		// $this->address = $this->getAddress();
	}

	// Проверка на совпадение ключей
	public function isValid() : bool{
		return RakLib::REGISTER_SERVER_KEY == $this->auth_key;
	}
}