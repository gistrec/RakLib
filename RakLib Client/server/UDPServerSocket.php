<?php

declare(strict_types=1);

namespace raklib\server;

use raklib\utils\InternetAddress;

class UDPServerSocket{
	/** @var resource */
	protected $socket;
	/**
	 * @var InternetAddress
	 */
	private $bindAddress;

	public function __construct(InternetAddress $bindAddress){
		$this->bindAddress = $bindAddress;
		// Создаем сокет
		//
		// AF_INET  - IPv4
		// SOCK_DGRAM - Поддерживает датаграммы (на них основан UDP)
		// SOL_UDP - указываем конкретный протокол
		$this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

		// Привязываем имя (ip адрес и порт) к сокету
		if(socket_bind($this->socket, $bindAddress->ip, $bindAddress->port) === true){
			// Видимо устанавливаем невозможность повторного использования адреса
			socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 0);
			socket_set_option($this->socket, SOL_SOCKET, SO_SNDBUF, 1024 * 1024 * 8);
			socket_set_option($this->socket, SOL_SOCKET, SO_RCVBUF, 1024 * 1024 * 8);
		}else{
			throw new \Exception("Failed to bind to " . $bindAddress . ": " . trim(socket_strerror(socket_last_error($this->socket))));
		}
		socket_set_nonblock($this->socket);
	}

	/**
	 * @return InternetAddress
	 */
	public function getBindAddress() : InternetAddress{
		return $this->bindAddress;
	}

	/**
	 * @return resource
	 */
	public function getSocket(){
		return $this->socket;
	}

	public function close() : void{
		socket_close($this->socket);
	}

	/**
	 * @param string &$buffer
	 * @param string &$source
	 * @param int    &$port
	 *
	 * @return int|bool
	 */
	public function readPacket(?string &$buffer, ?string &$source, ?int &$port){
		// Получаем 65535 байт из сокета
		return socket_recvfrom($this->socket, $buffer, 65535, 0, $source, $port);
	}

	/**
	 * @param string $buffer
	 * @param string $dest
	 * @param int    $port
	 *
	 * @return int|bool
	 */
	public function writePacket(string $buffer, string $dest, int $port){
		if ($buffer{1} != chr(0x07)) {
			echo('Отправляем пакет на раклиб сервер' . PHP_EOL);
			echo(substr(bin2hex($buffer), 0, 50) . PHP_EOL);
			echo PHP_EOL;
		}
		return socket_sendto($this->socket, $buffer, strlen($buffer), 0, $dest, $port);
	}
}