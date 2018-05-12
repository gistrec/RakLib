<?php

declare(strict_types=1);

namespace raklib\protocol;


use raklib\RakLib;

class RegisterRemoteServerRequest extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_REGISTER_REMOTE_SERVER_REQUEST;

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
		$this->writeMagic();
		$this->putString(RakLib::REGISTER_SERVER_KEY);
		$this->putByte($this->isMain);
		//$this->putAddress($this->address);
	}

	protected function decodePayload() : void{
		$this->readMagic();
		$this->auth_key = $this->readString();
		$this->isMain = $this->readByte();
		// $this->address = $this->getAddress();
	}

	// Проверка на совпадение ключей
	public function isValid() : bool{
		return RakLib::REGISTER_SERVER_KEY == $this->auth_key;
	}
}