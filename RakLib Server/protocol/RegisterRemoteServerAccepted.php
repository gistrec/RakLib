<?php

declare(strict_types=1);

namespace raklib\protocol;


class RegisterRemoteServerAccepted extends Packet{
	public static $ID = MessageIdentifiers::ID_REGISTER_REMOTE_SERVER_ACCEPTED;


	protected function encodePayload() : void{
	}

	protected function decodePayload() : void{
	}
}