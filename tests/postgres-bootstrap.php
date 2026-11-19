<?php

require dirname(__DIR__).'/vendor/autoload.php';

if (is_file(dirname(__DIR__).'/.env.pgsql-testing')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__), '.env.pgsql-testing')->load();
}
