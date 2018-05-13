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
	public $remoteServers = [];

	// Счетчик id серверов
	// Увеличивается на 1 при добавлении нового сервера
	public $unique_serverId = 0;

	/** @var UDPremoteServersocket */
	public $internalSocket;

	public $reusableAddress;

	public function __construct(RakLibServer $server, 
								UDPServersocket $internalSocket) {
		$this->server = $server;

		$this->internalSocket = $internalSocket;

		$this->reusableAddress = new InternetAddress('', 0);
	}

	// Получаем главный сервер (или, наверное лучше сказать доступный)
	// К которому будет подключен новый игрок
	public function getMainServer() : RemoteServer{
		foreach ($this->remoteServers as $server) {
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
		$time = microtime(true);
		foreach($this->remoteServers as $server){
			$server->update($time);
		}
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

		// Получаем PacketID
		$pid = ord($buffer{0});

		// Получаем сессию по адресу
		$server = $this->getServer($address);

		//if ($buffer{1} != chr(0x07)) {
		//	echo('Пришел пакет с сервера '.$address->toString() . PHP_EOL);
		//	echo(substr(bin2hex($buffer), 0, 50) . PHP_EOL);
		//	echo PHP_EOL;
		//}

		$server = $this->getServer($address);

		if ($server != null) {
			$server->receivePacket($buffer);
		// Если сервер пытается зарегестрироваться
		}elseif ($pid == 0x87) {
			$pk = new RegisterRemoteServerRequest();
			$pk->buffer = $buffer;
			$pk->decode();

			// Если сервер регестрируется с правильным auth_key
			if ($pk->isValid()) {
				$isMain = $pk->isMain;
				$server = $this->registerServer($address, $isMain);
					
				$pk = new RegisterRemoteServerAccepted();
				$pk->encode();
				$server->sendPacket($pk->buffer);

				echo "Зарегестрирован новый сервер" .PHP_EOL;
				echo "Ip: " . $address->toString() . PHP_EOL;
				echo "Главный: " . ($isMain == 1 ? "да" : "нет") . PHP_EOL;
			} else  {
				// TODO: block address
				//$this->blockAddress($address);
			}
		}
		return true;
	}

	public function getServer(InternetAddress $address) : ?RemoteServer{
		return $this->remoteServers[$address->toString()] ?? null;
	}

	// TODO: Что делаем при отключении сервера
	public function closeServer(InternetAddress $address) {
		echo "Удалили сервер " . $address->toString() . PHP_EOL;
		unset($this->remoteServers[$address->toString()]);
	}

	// Функция нужна для регистрации сервера
	// У каждого сервера есть ip, port, главный сервер или нет
	private function registerServer(InternetAddress $address, bool $isMain) : RemoteServer{
		// Создаем экземпляр сервера и добавляем его в список серверов
		$server = new RemoteServer($this, $this->internalSocket, $address, $isMain);
		$this->remoteServers[$address->toString()] = $server;
		return $server;
	}
}