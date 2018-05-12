<?php

declare(strict_types=1);

namespace raklib\protocol;


class RegisterRemoteServerAccepted extends OfflineMessage{
	public static $ID = MessageIdentifiers::ID_REGISTER_REMOTE_SERVER_ACCEPTED;

	/**
	 * Отправляем id сервера
	 * @var int
	 */
	public $serverId; 

	protected function encodePayload() : void{
		$this->putInt($this->serverId);
	}

	protected function decodePayload() : void{
		$this->serverId = $this->getInt();
	}
}