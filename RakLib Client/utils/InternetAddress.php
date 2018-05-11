<?php

declare(strict_types=1);

namespace raklib\utils;

class InternetAddress{

	/**
	 * @var string
	 */
	public $ip;
	/**
	 * @var int
	 */
	public $port;

	public function __construct(string $address, int $port){
		$this->ip = $address;
		if($port < 0 or $port > 65536){
			throw new \InvalidArgumentException("Invalid port range");
		}
		$this->port = $port;
	}

	/**
	 * @return string
	 */
	public function getIp() : string{
		return $this->ip;
	}

	/**
	 * @return int
	 */
	public function getPort() : int{
		return $this->port;
	}

	public function __toString(){
		return $this->ip . " " . $this->port;
	}

	public function toString() : string{
		return $this->__toString();
	}

	public function equals(InternetAddress $address) : bool{
		return $this->ip === $address->ip and $this->port === $address->port;
	}
}
