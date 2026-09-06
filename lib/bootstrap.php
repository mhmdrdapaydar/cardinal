<?php
declare(strict_types=1);

require_once __DIR__ . '/Game.php';

/** @return array<string, mixed> */
function cardinal_config(): array
{
    static $config = null;
    if ($config === null) $config = require dirname(__DIR__) . '/config.php';
    return $config;
}

/** @param array<string, mixed> $config */
function cardinal_database_is_configured(array $config): bool
{
    // A blank DB password is uncommon but is supported by legacy MySQL setups,
    // matching the former adapter which required host/user/database only.
    foreach (['db_host', 'db_name', 'db_user'] as $key) {
        if (!isset($config[$key]) || trim((string) $config[$key]) === '') return false;
    }
    return true;
}

function cardinal_live_game(): CardinalGame
{
    static $game = null;
    if ($game instanceof CardinalGame) return $game;
    $config = cardinal_config();
    if (!cardinal_database_is_configured($config)) {
        throw new GameException('اتصال پایگاه داده برای نسخه عملیاتی تنظیم نشده است.', 503);
    }
    if (!extension_loaded('pdo_mysql')) {
        throw new GameException('افزونه PDO MySQL روی هاست فعال نیست.', 503);
    }
    try {
        $port = filter_var((string) ($config['db_port'] ?? '3306'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) ?: 3306;
        $dsn = 'mysql:host=' . $config['db_host'] . ';port=' . $port . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string) $config['db_user'], (string) $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // The one legacy-compatible, idempotent registration for web server 5.
        $statement = $pdo->prepare('INSERT IGNORE INTO servers (server_id, server_name) VALUES (?, ?)');
        $statement->execute([5, 'website']);
        $game = new CardinalGame($pdo);
        return $game;
    } catch (PDOException $error) {
        // Never expose DSN, user name, or database detail to the browser.
        throw new GameException('اتصال امن سایت به پایگاه داده بازی برقرار نشد. تنظیمات و دسترسی دیتابیس را بررسی کنید.', 503);
    }
}

function cardinal_using_demo(): bool
{
    $config = cardinal_config();
    return !cardinal_database_is_configured($config) && !empty($config['allow_unconfigured_demo']);
}

/** @return CardinalGame|DemoGame */
function cardinal_game()
{
    if (cardinal_using_demo()) {
        require_once __DIR__ . '/DemoGame.php';
        return new DemoGame();
    }
    return cardinal_live_game();
}
