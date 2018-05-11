<?php

gc_enable();
error_reporting(-1);
ini_set("display_errors", '1');
ini_set("display_startup_errors", '1');

require_once "./autoload.php";

use raklib\server\RakLibServer;
use raklib\utils\InternetAddress;
use raklib\Raklib;

// Адрес, к которому будут подключаться клиенты
$externalAddress = new InternetAddress('192.168.0.100', 19132);

// Адрес для общения раклиба с серверами
$internetAddress = new InternetAddress('192.168.0.100', 19133);


$raklibServer = new RakLibServer($externalAddress, $internetAddress);
$raklibServer->run();


echo "test";