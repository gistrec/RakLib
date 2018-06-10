<?php


namespace raklib\tasks;

use raklib\server\Session;
use raklib\scheduler\Task;

class SendChunkRequestPacketTask extends Task {

	public $session;

	public function __construct(Session $session) {
		$this->session = $session;
	}
	public function onRun(int $currentTick) {
		$this->session->remoteServer->sendPacket($this->session->chunkRequestPacket);
		var_dump("Второй пошел");
	}
}