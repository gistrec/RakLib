<?php

$ROOT_DIR = __DIR__ . DIRECTORY_SEPARATOR;

spl_autoload_register(function ($class_name) {
    $class_name = str_replace('raklib', '', $class_name);
    $class_name = str_replace('\\', '/', $class_name);
    require_once __DIR__.'/'. $class_name . '.php';
});

$classesDir = array (
    $ROOT_DIR . 'scheduler',
    $ROOT_DIR . 'protocol',
    $ROOT_DIR . 'server',
    $ROOT_DIR . 'tasks',
    $ROOT_DIR . 'utils',
    $ROOT_DIR
);


function loadDir($directory) {
    foreach (scandir($directory) as $filename) {
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            // Проверяем что арсширение у файла - php
            $splited = explode('.',$path);
            if ($splited[count($splited) - 1] == "php") {
                require_once $path;
            }
        }
    }
}

function loadclass() {
    global $classesDir;
    foreach ($classesDir as $directory) {
        loadDir($directory);
    }
}

loadclass();