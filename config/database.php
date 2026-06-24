<?php
declare(strict_types=1);

$localConfig = __DIR__ . '/database.local.php';
if (is_file($localConfig)) {
    return require $localConfig;
}

return [
    'driver' => getenv('CBT_DB_DRIVER') ?: 'sqlite',
    'sqlite' => [
        'path' => dirname(__DIR__) . '/cbt_exam.db',
    ],
    'mysql' => [
        'host' => getenv('CBT_DB_HOST') ?: 'localhost',
        'port' => (int) (getenv('CBT_DB_PORT') ?: 3306),
        'database' => getenv('CBT_DB_NAME') ?: '',
        'username' => getenv('CBT_DB_USER') ?: '',
        'password' => getenv('CBT_DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
];
