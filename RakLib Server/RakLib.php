<?php

/*
 * RakLib network library
 */

declare(strict_types=1);

namespace raklib;

error_reporting(E_ALL);

const MIN_PHP_VERSION = "7.2.0RC3";

//Dependencies check
$errors = 0;
if(version_compare(MIN_PHP_VERSION, PHP_VERSION) > 0){
	echo "[CRITICAL] Use PHP >= " . MIN_PHP_VERSION . PHP_EOL;
	++$errors;
}

$exts = [
	"bcmath" => "BC Math",
	"pthreads" => "pthreads",
	"sockets" => "Sockets"
];

foreach($exts as $ext => $name){
	if(!extension_loaded($ext)){
		echo "[CRITICAL] Unable to find the $name ($ext) extension." . PHP_EOL;
		++$errors;
	}
}

if(extension_loaded("pthreads")){
	$pthreads_version = phpversion("pthreads");
	if(substr_count($pthreads_version, ".") < 2){
		$pthreads_version = "0.$pthreads_version";
	}

	if(version_compare($pthreads_version, "3.1.7dev") < 0){
		echo "[CRITICAL] pthreads >= 3.1.7dev is required, while you have $pthreads_version.";
		++$errors;
	}
}

if($errors > 0){
	exit(1); //Exit with error
}
unset($errors, $exts);

abstract class RakLib{
	const VERSION = "0.11.0";

	/**
	 * Версия протокола
	 */
	const DEFAULT_PROTOCOL_VERSION = 6;
	const MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

	// Сообщения отправляются на следующий тик
	const PRIORITY_NORMAL = 0;
	// Сообщения отправляются сразу, не дожидаясь следующего тика
	const PRIORITY_IMMEDIATE = 1;

	const FLAG_NEED_ACK = 0b00001000;



	/*
	 * Этих внутренних пакетов нет в RakNet протоколе
	 * Они используются для общения сервера RakLib с MCPE сервером
	 *
	 * Пакеты, которые приходят с сервера MCPE содержат 
	 * дополнительный первый байт - id сервера
	 * 
	 * Структура пакета:
	 *     byte (packet ID)
	 *     payload
	 */

	/*
	 * Пакет нужен для вызова функции на сервере MCPE
	 * handleEncapsulated($identifier, $buffer, $flags)
	 * 
	 * ENCAPSULATED payload:
	 *     byte    (identifier length)
	 *     byte[]  (identifier)
	 *     byte    (flags, last 3 bits, priority)
	 *     payload (binary internal EncapsulatedPacket)
	 */
	const PACKET_ENCAPSULATED = 0x01;

	/*
	 * Паект нужен для вызова функции на сервере MCPE
	 * openSession($identifier, $address, $port, $clientID)
	 * 
	 * OPEN_SESSION payload:
	 *     byte   (identifier length)
	 *     byte[] (identifier)
	 *     byte   (address length)
	 *     byte[] (address)
	 *     short  (port)
	 *     long   (clientID)
	 */
	const PACKET_OPEN_SESSION = 0x02;

	/*
	 * Пакет нужен для вызова функции на сервере MCPE
	 * closeSession($identifier, $reason)
	 * 
	 * CLOSE_SESSION payload:
	 *     byte   (identifier length)
	 *     byte[] (identifier)
	 *     string (reason)
	 */
	const PACKET_CLOSE_SESSION = 0x03;

	/* 
	 * Пакет нужен для вызова функции на сервере MCPE
	 * closeSession($identifier, "Invalid session")
	 * 
	 * INVALID_SESSION payload:
	 *     byte   (identifier length)
	 *     byte[] (identifier)
	 */
	const PACKET_INVALID_SESSION = 0x04;

	/* TODO: implement this
	 * SEND_QUEUE payload:
	 * byte   (identifier length)
	 * byte[] (identifier)
	 */
	// const PACKET_SEND_QUEUE = 0x05;

	/* 
	 * Пакет нужен для вызова функции на сервере MCPE
	 * notifyACK($identifier, $identifierACK)
	 * 
	 * ACK_NOTIFICATION payload:
	 *     byte   (identifier length)
	 *     byte[] (identifier)
	 *     int    (identifierACK)
	 */
	const PACKET_ACK_NOTIFICATION = 0x06;

	/* 
	 * TODO: Заменить на что-то полезное
	 *       Ибо отправлять байты - нууу, такое
	 * SET_OPTION payload:
	 *     byte   (option name length)
	 *     byte[] (option name)
	 *     byte[] (option value)
	 */
	const PACKET_SET_OPTION = 0x07;

	/* 
	 * Пакет нужен для вызова функции на сервере MCPE
	 * handleRaw($address, $port, $payload)
	 * 
	 * RAW payload:
	 *     byte (address length)
	 *     byte[] (address from/to)
	 *     short  (port)
	 *     byte[] (payload)
	 */
	const PACKET_RAW = 0x08;

	/* 
	 * Отправляется с сервера MCPE
	 * Нужен для блокировки адреса RakLib сервером
	 *
	 * BLOCK_ADDRESS payload:
	 *     byte   (address length)
	 *     byte[] (address)
	 *     int    (timeout)
	 */
	const PACKET_BLOCK_ADDRESS = 0x09;

	/*
	 * Отправляется с сервера MCPE
	 * Нужен для разблокировки адреса RakLib сервером
	 *  
	 * UNBLOCK_ADDRESS payload:
	 *     byte   (address length)
	 *     byte[] (address)
	 */
	const PACKET_UNBLOCK_ADDRESS = 0x10;

	/*
	 * Пакет нужен для вызова функции на сервере MCPE
	 * updatePing($identifier, $pingMS)
	 * 
	 * REPORT_PING payload:
	 *     byte (identifier length)
	 *     byte[] (identifier)
	 *     int32 (measured latency in MS)
	 */
	const PACKET_REPORT_PING = 0x11;

	/* 
	 * TODO: Что делаем, когда сервер выключается
	 * Сервер отсылает 'disconnect message'
	 * 
	 * No payload
	 */
	const PACKET_SHUTDOWN = 0x7e;

	/* 
	 * TODO: Что делаем, когда сервер крашится
	 * Сервер крашится, без отсылания 'disconnect message'
	 * 
	 * No payload
	 */
	const PACKET_EMERGENCY_SHUTDOWN = 0x7f;
	

	/**
	 * Адреса хранятся в пакетах:
	 *     ConnectionRequestAccepted
	 *     NewIncomingConnection
	 *
	 * MCPE использует 20 адресов
	 * @var int
	 */
	public static $SYSTEM_ADDRESS_COUNT = 20;
}
