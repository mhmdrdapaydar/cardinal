<?php
declare(strict_types=1);

/**
 * Cardinal — realtime presence and chat.
 * =============================================================================
 * This endpoint is DELIBERATELY ISOLATED from the game.
 *
 *   - It opens its own SQLite file and never touches the MySQL game database.
 *   - It never reads or writes a player's coins, level, inventory, floor
 *     progress or any other authoritative value.
 *   - Nothing it stores can influence a game rule. If the SQLite file is
 *     deleted the game is unaffected; only chat history and who is currently
 *     visible are lost.
 *
 * The only thing it borrows from the game is identity: the player id already
 * on the PHP session. Everything cosmetic that the client reports (display
 * name, class, cursor colour, position) is treated as untrusted decoration and
 * is sanitised, clamped and used for display only, exactly like the
 * non-authoritative avatar layer the package documents.
 *
 * Routes (all under realtime.php?route=…)
 *   GET  health        service + schema status
 *   POST sync          heartbeat: report my position, receive everyone nearby
 *   GET  chat          recent messages, optionally only those after an id
 *   POST chat/send     post one message
 *
 * The schema is created on first use, so there is nothing to install.
 */

// ---------------------------------------------------------------- session
$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$cookiePath = rtrim($scriptDirectory, '/') . '/';
if ($cookiePath === '/') $cookiePath = '/';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('cardinal_web_session_v2');
session_set_cookie_params([
    'lifetime' => 43200,
    'path' => $cookiePath,
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// ---------------------------------------------------------------- tunables
const RT_PRESENCE_TTL   = 60;    // seconds before a player stops being shown
const RT_LOBBY_SIZE     = 50;    // players per lobby inside one room
const RT_CHAT_KEEP      = 100;   // messages returned to a client
const RT_CHAT_HISTORY   = 400;   // rows kept in the file before trimming
const RT_CHAT_MAX_CHARS = 240;
const RT_CHAT_COOLDOWN  = 1.2;   // seconds between messages from one player

function rt_out(bool $ok, $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        $ok ? ['ok' => true, 'data' => $payload] : ['ok' => false, 'error' => $payload],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function rt_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function rt_text($value, int $max, string $fallback = ''): string
{
    if (!is_string($value)) return $fallback;
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    if ($value === '') return $fallback;
    if (function_exists('mb_substr')) $value = mb_substr($value, 0, $max, 'UTF-8');
    else $value = substr($value, 0, $max);
    return $value;
}

function rt_float($value, float $min, float $max, float $fallback = 0.0): float
{
    if (!is_numeric($value)) return $fallback;
    $n = (float) $value;
    if (!is_finite($n)) return $fallback;
    return max($min, min($max, $n));
}

function rt_int($value, int $min, int $max, int $fallback = 0): int
{
    if (!is_numeric($value)) return $fallback;
    return max($min, min($max, (int) $value));
}

// ---------------------------------------------------------------- storage
/**
 * Where the SQLite file lives.
 *
 * Preference order:
 *   1. CARDINAL_REALTIME_DB, if the host sets it
 *   2. a sibling of the private config, i.e. one level above public_html
 *   3. ./data/ next to this script, which .htaccess denies over HTTP
 */
function rt_database_path(): string
{
    $configured = getenv('CARDINAL_REALTIME_DB');
    if (is_string($configured) && $configured !== '') return $configured;

    $candidates = [];
    $here = __DIR__;
    for ($up = 1; $up <= 3; $up++) {
        $parent = dirname($here, $up);
        if ($parent === '' || $parent === '.' || $parent === DIRECTORY_SEPARATOR) break;
        $candidates[] = $parent . '/cardinal-realtime.sqlite';
    }
    foreach ($candidates as $candidate) {
        $dir = dirname($candidate);
        if (is_dir($dir) && is_writable($dir) && basename($dir) !== basename(__DIR__)) {
            return $candidate;
        }
    }
    return __DIR__ . '/data/cardinal-realtime.sqlite';
}

function rt_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        rt_out(false, 'pdo_sqlite در این هاست فعال نیست؛ چت و حضور آنلاین غیرفعال است.', 503);
    }

    $path = rt_database_path();
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        rt_out(false, 'محل ذخیره‌سازی چت قابل نوشتن نیست: ' . $dir, 503);
    }
    // If the file had to land inside the web root, make sure it cannot be
    // downloaded even on a host that ignores the package .htaccess.
    if ($dir === __DIR__ . '/data' && !file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }

    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $error) {
        rt_out(false, 'اتصال به پایگاه دادهٔ محلی ممکن نشد.', 503);
    }

    // WAL keeps readers from blocking the heartbeat writes.
    @$pdo->exec('PRAGMA journal_mode = WAL');
    @$pdo->exec('PRAGMA synchronous = NORMAL');
    @$pdo->exec('PRAGMA busy_timeout = 4000');

    $pdo->exec('CREATE TABLE IF NOT EXISTS presence (
        player_id  INTEGER PRIMARY KEY,
        name       TEXT    NOT NULL,
        class_id   INTEGER NOT NULL DEFAULT 1,
        pk_status  TEXT    NOT NULL DEFAULT \'white\',
        gender     TEXT    NOT NULL DEFAULT \'male\',
        floor      INTEGER NOT NULL DEFAULT 1,
        location   TEXT    NOT NULL DEFAULT \'city\',
        x          REAL    NOT NULL DEFAULT 0,
        z          REAL    NOT NULL DEFAULT 0,
        yaw        REAL    NOT NULL DEFAULT 0,
        moving     INTEGER NOT NULL DEFAULT 0,
        seen_at    INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS presence_room ON presence (location, floor, seen_at)');

    // A file created before gender existed must not be thrown away, so add the
    // column in place. SQLite has no IF NOT EXISTS for ALTER, hence the probe.
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(presence)') as $column) {
        $columns[] = $column['name'];
    }
    if (!in_array('gender', $columns, true)) {
        $pdo->exec("ALTER TABLE presence ADD COLUMN gender TEXT NOT NULL DEFAULT 'male'");
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS chat (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        player_id  INTEGER NOT NULL,
        name       TEXT    NOT NULL,
        class_id   INTEGER NOT NULL DEFAULT 1,
        pk_status  TEXT    NOT NULL DEFAULT \'white\',
        body       TEXT    NOT NULL,
        created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS chat_recent ON chat (id DESC)');

    return $pdo;
}

// ---------------------------------------------------------------- identity
function rt_player_id(): int
{
    $id = $_SESSION['cardinal_player_id'] ?? null;
    if (is_int($id) && $id > 0) return $id;
    if (is_string($id) && ctype_digit($id) && (int) $id > 0) return (int) $id;
    rt_out(false, 'برای استفاده از چت و حضور آنلاین باید وارد حساب شوید.', 401);
}

function rt_pk(string $value): string
{
    $value = strtolower($value);
    return in_array($value, ['white', 'orange', 'red', 'green'], true) ? $value : 'white';
}

// ---------------------------------------------------------------- routes
$route = (string) ($_GET['route'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($route === 'health') {
    $ready = in_array('sqlite', PDO::getAvailableDrivers(), true);
    rt_out(true, [
        'service' => 'cardinal-realtime',
        'sqlite' => $ready,
        'presenceTtl' => RT_PRESENCE_TTL,
        'lobbySize' => RT_LOBBY_SIZE,
        'signedIn' => isset($_SESSION['cardinal_player_id']),
    ]);
}

$playerId = rt_player_id();
$db = rt_db();
$now = time();

if ($route === 'sync') {
    if ($method !== 'POST') rt_out(false, 'روش درخواست نامعتبر است.', 405);
    $in = rt_input();

    $name = rt_text($in['name'] ?? null, 32, 'بازیکن');
    $classId = rt_int($in['classId'] ?? null, 1, 20, 1);
    $pk = rt_pk(rt_text($in['pkStatus'] ?? null, 10, 'white'));
    $gender = (($in['gender'] ?? 'male') === 'female') ? 'female' : 'male';
    $floor = rt_int($in['floor'] ?? null, 1, 200, 1);
    $location = (($in['location'] ?? 'city') === 'wild') ? 'wild' : 'city';
    // The world clamps the avatar to 33.25 in the city and 60 in the wild;
    // allow a little slack and reject anything wilder than that.
    $limit = $location === 'wild' ? 70.0 : 40.0;
    $x = rt_float($in['x'] ?? null, -$limit, $limit);
    $z = rt_float($in['z'] ?? null, -$limit, $limit);
    $yaw = rt_float($in['yaw'] ?? null, -7.0, 7.0);
    $moving = !empty($in['moving']) ? 1 : 0;

    // If nobody at all was still live when this player arrived, the realm had
    // emptied out: drop the transcript so an idle server does not carry chat
    // history forever. Checked BEFORE the upsert, otherwise this player's own
    // fresh row would always make the realm look occupied.
    $cutBefore = $now - RT_PRESENCE_TTL;
    $liveBefore = (int) $db->query('SELECT COUNT(*) FROM presence WHERE seen_at >= ' . $cutBefore)->fetchColumn();
    if ($liveBefore === 0) {
        $db->exec('DELETE FROM chat');
        $db->exec('DELETE FROM sqlite_sequence WHERE name = \'chat\'');
        $db->exec('DELETE FROM presence');
    }

    $db->prepare('INSERT INTO presence
            (player_id, name, class_id, pk_status, gender, floor, location, x, z, yaw, moving, seen_at)
         VALUES (:p, :n, :c, :k, :g, :f, :l, :x, :z, :y, :m, :t)
         ON CONFLICT(player_id) DO UPDATE SET
            name = :n, class_id = :c, pk_status = :k, gender = :g, floor = :f, location = :l,
            x = :x, z = :z, yaw = :y, moving = :m, seen_at = :t')
       ->execute([
            ':p' => $playerId, ':n' => $name, ':c' => $classId, ':k' => $pk,
            ':g' => $gender, ':f' => $floor, ':l' => $location, ':x' => $x, ':z' => $z,
            ':y' => $yaw, ':m' => $moving, ':t' => $now,
       ]);

    // Drop anyone who stopped sending heartbeats. Occasional, not every call.
    if (($now % 7) === 0) {
        $db->prepare('DELETE FROM presence WHERE seen_at < :cut')
           ->execute([':cut' => $now - RT_PRESENCE_TTL]);
    }

    $cut = $now - RT_PRESENCE_TTL;
    $rows = $db->prepare('SELECT player_id, name, class_id, pk_status, gender, x, z, yaw, moving, seen_at
                          FROM presence
                          WHERE location = :l AND floor = :f AND seen_at >= :cut
                          ORDER BY player_id ASC');
    $rows->execute([':l' => $location, ':f' => $floor, ':cut' => $cut]);
    $all = $rows->fetchAll();

    // Lobbies: a stable slice of the room, so the same people stay together
    // between polls instead of reshuffling every second.
    $myIndex = 0;
    foreach ($all as $i => $row) {
        if ((int) $row['player_id'] === $playerId) { $myIndex = $i; break; }
    }
    $lobby = intdiv($myIndex, RT_LOBBY_SIZE);
    $slice = array_slice($all, $lobby * RT_LOBBY_SIZE, RT_LOBBY_SIZE);

    $players = [];
    foreach ($slice as $row) {
        if ((int) $row['player_id'] === $playerId) continue;
        $players[] = [
            'id' => (int) $row['player_id'],
            'name' => (string) $row['name'],
            'classId' => (int) $row['class_id'],
            'pkStatus' => (string) $row['pk_status'],
            'gender' => (string) $row['gender'],
            'x' => round((float) $row['x'], 3),
            'z' => round((float) $row['z'], 3),
            'yaw' => round((float) $row['yaw'], 3),
            'moving' => ((int) $row['moving']) === 1,
            'idle' => $now - (int) $row['seen_at'],
        ];
    }

    $total = (int) $db->query('SELECT COUNT(*) FROM presence WHERE seen_at >= ' . (int) $cut)->fetchColumn();

    rt_out(true, [
        'players' => $players,
        'room' => ['location' => $location, 'floor' => $floor],
        'lobby' => $lobby + 1,
        'lobbies' => max(1, (int) ceil(count($all) / RT_LOBBY_SIZE)),
        'inRoom' => count($all),
        'online' => $total,
        'ttl' => RT_PRESENCE_TTL,
    ]);
}

if ($route === 'chat') {
    if ($method !== 'GET') rt_out(false, 'روش درخواست نامعتبر است.', 405);
    $after = rt_int($_GET['after'] ?? null, 0, PHP_INT_MAX, 0);
    if ($after > 0) {
        $stmt = $db->prepare('SELECT id, player_id, name, class_id, pk_status, body, created_at
                              FROM chat WHERE id > :a ORDER BY id ASC LIMIT :n');
        $stmt->bindValue(':a', $after, PDO::PARAM_INT);
        $stmt->bindValue(':n', RT_CHAT_KEEP, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } else {
        $stmt = $db->prepare('SELECT id, player_id, name, class_id, pk_status, body, created_at
                              FROM chat ORDER BY id DESC LIMIT :n');
        $stmt->bindValue(':n', RT_CHAT_KEEP, PDO::PARAM_INT);
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll());
    }

    $messages = [];
    foreach ($rows as $row) {
        $messages[] = [
            'id' => (int) $row['id'],
            'playerId' => (int) $row['player_id'],
            'name' => (string) $row['name'],
            'classId' => (int) $row['class_id'],
            'pkStatus' => (string) $row['pk_status'],
            'body' => (string) $row['body'],
            'at' => (int) $row['created_at'],
            'self' => ((int) $row['player_id']) === $playerId,
        ];
    }
    rt_out(true, ['messages' => $messages, 'keep' => RT_CHAT_KEEP]);
}

if ($route === 'chat/send') {
    if ($method !== 'POST') rt_out(false, 'روش درخواست نامعتبر است.', 405);
    $in = rt_input();

    $body = rt_text($in['body'] ?? null, RT_CHAT_MAX_CHARS);
    if ($body === '') rt_out(false, 'متن پیام خالی است.', 422);

    $last = (float) ($_SESSION['cardinal_rt_last_chat'] ?? 0);
    if ($last > 0 && (microtime(true) - $last) < RT_CHAT_COOLDOWN) {
        rt_out(false, 'کمی آرام‌تر؛ بین پیام‌ها کمی صبر کنید.', 429);
    }
    $_SESSION['cardinal_rt_last_chat'] = microtime(true);

    $name = rt_text($in['name'] ?? null, 32, 'بازیکن');
    $classId = rt_int($in['classId'] ?? null, 1, 20, 1);
    $pk = rt_pk(rt_text($in['pkStatus'] ?? null, 10, 'white'));

    $db->prepare('INSERT INTO chat (player_id, name, class_id, pk_status, body, created_at)
                  VALUES (:p, :n, :c, :k, :b, :t)')
       ->execute([':p' => $playerId, ':n' => $name, ':c' => $classId, ':k' => $pk,
                  ':b' => $body, ':t' => $now]);
    $id = (int) $db->lastInsertId();

    // Keep the file small: drop everything older than the retention window.
    $db->exec('DELETE FROM chat WHERE id <= ' . max(0, $id - RT_CHAT_HISTORY));

    rt_out(true, ['id' => $id]);
}

rt_out(false, 'مسیر درخواستی وجود ندارد.', 404);
