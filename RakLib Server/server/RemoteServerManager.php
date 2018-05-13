<?php

declare(strict_types=1);

namespace raklib\server;

use raklib\utils\InternetAddress;
use raklib\protocol\RegisterRemoteServerRequest;
use raklib\protocol\RegisterRemoteServerAccepted;

class RemoteServerManager {

	/**
	 * @var RakLibServer
	 */
	public $server;
	/**
	 * Сервера
	 * @var float[] string (address) => float (unblock time) 
	 */
	public $servers = [];

	// Счетчик id серверов
	// Увеличивается на 1 при добавлении нового сервера
	public $unique_serverId = 0;

	/** @var UDPServerSocket */
	public $internalSocket;

	public $reusableAddress;

	public function __construct(RakLibServer $server, 
								UDPServerSocket $internalSocket) {
		$this->server = $server;

		$this->internalSocket = $internalSocket;

		$this->reusableAddress = new InternetAddress('', 0);
	}

	// Получаем главный сервер (или, наверное лучше сказать доступный)
	// К которому будет подключен новый игрок
	public function getMainServer() : RemoteServer{
		foreach ($this->servers as $server) {
			// Если сервер главный
			if ($server->main) return $server;
		}
		// TODO: Что делать, если не найден сервер,
		// к которому можно подключить игрока
		// Пока будем возвращать последний сервер
		return $server;
	}

	/**
	 * Выполняется каждый 'тик'
	 */
	public function tick() : void{
		// TODO
	}

	// Получаем информацию с сервера
	// И перенаправляем её классу сервера
	public function receivePacket() : bool{
		$address = $this->reusableAddress;

		// Получаем данные из сокета
		$len = $this->internalSocket->readPacket($buffer, $address->ip, $address->port);

		// Если данных нет
		// выходим из функции
		if($len === false){
			return false;
		}

		// Структура пакета:
		// id сервера
		// id пакета
		if ($buffer{1} != chr(0x07)) {
			echo('Пришел пакет с сервера '.$address->toString() . PHP_EOL);
			echo(substr(bin2hex($buffer), 0, 50) . PHP_EOL);
			echo PHP_EOL;
		}

		$serverId = ord($buffer{0});

var_dump($buffer);

		if (isset($this->servers[$serverId])) {
			$this->servers[$serverId]->receivePacket($buffer);

		// Если сервер пытается зарегестрироваться
		}elseif ($serverId == 0xff && ord($buffer{1}) == 0x87) {
			$pk = new RegisterRemoteServerRequest();
			$pk->buffer = $buffer;
			$pk->decode();

			if ($pk->isValid()) {
				$isMain = $pk->isMain;
				$id = $this->registerServer($address->ip, $address->port, $isMain);
					
				$pk = new RegisterRemoteServerAccepted();
				$pk->serverId = $id;
				$pk->encode();

				$this->servers[$id]->sendToServer($pk->buffer);
				// $this->sessionManager->sendPacket($pk, $address);

				echo "Зарегестрирован новый сервер" .PHP_EOL;
				echo "Ip: " . $address->toString() . PHP_EOL;
				echo "Главный: " . ($isMain == 1 ? "да" : "нет") . PHP_EOL;
				echo "Id: $id" . PHP_EOL;
			} else  {
				// TODO: block address
				//$this->sessionManager->blockAddress($address);
			}
		}
		return true;
	}

	// TODO: Что делаем при отключении сервера
	public function closeServer($id) {

	}

	// Функция нужна для регистрации сервера
	// У каждого сервера есть id, ip, port, главный сервер или нет
	private function registerServer(string $ip, int $port, bool $isMain) : int{
		// Создаем экземпляр сервера и добавляем его в список серверов
		$address = new InternetAddress($ip, $port);
		$id = $this->unique_serverId++;
		$this->servers[$id] = new RemoteServer($this, 
											   $this->internalSocket,
											   $address, $id, $isMain);
		return $id;
	}
}