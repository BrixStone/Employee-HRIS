<?php
// config/mail.php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(
    dirname(__DIR__) . '/monitoring'
);

$dotenv->safeLoad();

return [
    'host'       => $_ENV['MAIL_HOST'],
    'username'   => $_ENV['MAIL_USERNAME'],
    'password'   => $_ENV['MAIL_PASSWORD'],
    'port'       => (int) $_ENV['MAIL_PORT'],
    'encryption' => $_ENV['MAIL_ENCRYPTION'],
    'from_email' => $_ENV['MAIL_FROM_EMAIL'],
    'from_name'  => $_ENV['MAIL_FROM_NAME'],
    'app_url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
];
