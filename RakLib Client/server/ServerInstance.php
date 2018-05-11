<?php


declare(strict_types=1);

namespace raklib\server;

use raklib\protocol\EncapsulatedPacket;

interface ServerInstance{

	/**
	 * @param string $identifier
	 * @param string $address
	 * @param int    $port
	 * @param int    $clientID
	 */
	public function openSession(string $identifier, string $address, int $port, int $clientID) : void;

	/**
	 * @param string $identifier
	 * @param string $reason
	 */
	public function closeSession(string $identifier, string $reason) : void;

	/**
	 * @param string             $identifier
	 * @param EncapsulatedPacket $packet
	 * @param int                $flags
	 */
	public function handleEncapsulated(string $identifier, EncapsulatedPacket $packet, int $flags) : void;

	/**
	 * @param string $address
	 * @param int    $port
	 * @param string $payload
	 */
	public function handleRaw(string $address, int $port, string $payload) : void;

	/**
	 * @param string $identifier
	 * @param int    $identifierACK
	 */
	public function notifyACK(string $identifier, int $identifierACK) : void;

	/**
	 * @param string $option
	 * @param string $value
	 */
	public function handleOption(string $option, string $value) : void;

	/**
	 * @param string $identifier
	 * @param int    $pingMS
	 */
	public function updatePing(string $identifier, int $pingMS) : void;
}