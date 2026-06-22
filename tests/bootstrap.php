<?php

if (is_file(dirname(__DIR__).'/vendor/autoload.php')) {
    require dirname(__DIR__).'/vendor/autoload.php';
}

if (class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
    (new \Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}
