<?php

require __DIR__.'/../vendor/autoload.php';

$database = getenv('DB_DATABASE');
if (getenv('DB_CONNECTION') === 'pgsql' && is_string($database) && (str_ends_with($database, '_test') || str_contains($database, '_test_'))) {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT'), $database),
        (string) getenv('DB_USERNAME'),
        (string) getenv('DB_PASSWORD'),
    );
    $pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
}
