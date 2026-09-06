<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

// The SPA and API are same-origin. Sessions are HttpOnly and scoped to the
// extracted directory so multiple copies do not overwrite one another.
$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$cookiePath = rtrim($scriptDirectory, '/') . '/';
if ($cookiePath === '/') $cookiePath = '/';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
// Versioned name prevents a stale PHP/old Node cookie from being interpreted
// as a valid session after switching this directory to the PHP release.
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

/*
 * One active web browser per Cardinal account
 * -------------------------------------------
 * The shared bot schema intentionally has no browser-session column and this
 * upload must not migrate it.  A short-lived, server-side lease stored outside
 * the public web root therefore binds a PHP session to one opaque token. A
 * later login replaces that token; the previous browser is rejected at its
 * next API heartbeat/action. Files are locked so simultaneous login requests
 * cannot accidentally leave two valid leases behind.
 */
const CARDINAL_WEB_LEASE_TTL = 43200;

function api_lease_directory(): string
{
    static $directory = null;
    if (is_string($directory)) return $directory;
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    if ($base === '') throw new GameException('فضای امن نشست سایت در دسترس نیست.', 503);
    $directory = $base . DIRECTORY_SEPARATOR . 'cardinal-web-v5-leases';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new GameException('فضای امن نشست سایت قابل ایجاد نیست.', 503);
    }
    if (!is_writable($directory)) throw new GameException('فضای امن نشست سایت قابل نوشتن نیست.', 503);
    @chmod($directory, 0700);
    return $directory;
}

function api_lease_scope(): string
{
    static $scope = null;
    if (is_string($scope)) return $scope;
    $config = cardinal_config();
    $host = trim((string) ($config['db_host'] ?? ''));
    $name = trim((string) ($config['db_name'] ?? ''));
    // Production deployments using the same existing DB share one lease space.
    // Demo installs remain isolated by their extracted path.
    $identity = ($host !== '' && $name !== '')
        ? 'database|' . $host . '|' . (string) ($config['db_port'] ?? '3306') . '|' . $name
        : 'demo|' . (realpath(__DIR__) ?: __DIR__);
    $scope = hash('sha256', 'cardinal-web-server-5|' . $identity);
    return $scope;
}

function api_lease_path(int $playerId): string
{
    return api_lease_directory() . DIRECTORY_SEPARATOR . api_lease_scope() . '-' . hash('sha256', (string) $playerId) . '.json';
}

/**
 * @param callable(array<string, mixed>):array{record:array<string, mixed>,value:mixed} $callback
 * @return mixed
 */
function api_with_lease_lock(int $playerId, callable $callback)
{
    $handle = @fopen(api_lease_path($playerId), 'c+');
    if (!is_resource($handle)) throw new GameException('قفل امنیتی نشست سایت در دسترس نیست.', 503);
    @chmod(api_lease_path($playerId), 0600);
    if (!@flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new GameException('قفل امنیتی نشست سایت در دسترس نیست.', 503);
    }
    try {
        rewind($handle);
        $raw = stream_get_contents($handle);
        $record = [];
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $record = $decoded;
        }
        $result = $callback($record);
        if (!is_array($result) || !isset($result['record']) || !is_array($result['record'])) {
            throw new RuntimeException('Invalid Cardinal web lease callback result.');
        }
        $encoded = json_encode($result['record'], JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) throw new GameException('ثبت امنیتی نشست سایت ناموفق بود.', 503);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) === false || !fflush($handle)) {
            throw new GameException('ثبت امنیتی نشست سایت ناموفق بود.', 503);
        }
        return $result['value'] ?? null;
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function api_issue_session_lease(int $playerId): string
{
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $error) {
        throw new GameException('تولید نشست امن سایت ناموفق بود.', 503);
    }
    api_with_lease_lock($playerId, function (array $record) use ($token): array {
        $now = time();
        return ['record' => ['token' => $token, 'issued_at' => $now, 'expires_at' => $now + CARDINAL_WEB_LEASE_TTL], 'value' => true];
    });
    return $token;
}

function api_session_lease_is_active(int $playerId, string $token): bool
{
    if ($token === '') return false;
    return api_with_lease_lock($playerId, function (array $record) use ($token): array {
        $stored = $record['token'] ?? null;
        $expiresAt = isset($record['expires_at']) && is_numeric($record['expires_at']) ? (int) $record['expires_at'] : 0;
        $valid = is_string($stored) && $expiresAt > time() && hash_equals($stored, $token);
        // Never rewrite a mismatched record: it may belong to the newer browser.
        if ($valid) $record['expires_at'] = time() + CARDINAL_WEB_LEASE_TTL;
        return ['record' => $record, 'value' => $valid];
    });
}

function api_release_session_lease(int $playerId, string $token): void
{
    if ($token === '') return;
    api_with_lease_lock($playerId, function (array $record) use ($token): array {
        $stored = $record['token'] ?? null;
        if (is_string($stored) && hash_equals($stored, $token)) $record = [];
        return ['record' => $record, 'value' => true];
    });
}

function api_release_current_session_lease(): void
{
    $playerId = $_SESSION['cardinal_player_id'] ?? null;
    $token = $_SESSION['cardinal_session_lease'] ?? null;
    if ((!is_int($playerId) && !(is_string($playerId) && ctype_digit($playerId))) || !is_string($token) || $token === '') return;
    try {
        api_release_session_lease((int) $playerId, $token);
    } catch (Throwable $error) {
        // Session cookie deletion must still succeed even if temporary storage
        // is unavailable during a logout/error path.
        error_log('[Cardinal Web V] unable to release session lease');
    }
}

/** @param mixed $data */
function api_success($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array<string, mixed> */
function api_input(): array
{
    $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($length > 131072) throw new GameException('حجم درخواست بیش از حد مجاز است.', 413);
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new GameException('بدنه درخواست نامعتبر است.');
    return $data;
}

function api_require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) throw new GameException('روش درخواست مجاز نیست.', 405);
}

function api_player_id(): int
{
    // A preview session must never be reused against the real shared database,
    // and vice versa. It otherwise looks like a missing/deleted avatar.
    $currentMode = cardinal_using_demo() ? 'demo' : 'live';
    if (isset($_SESSION['cardinal_mode']) && $_SESSION['cardinal_mode'] !== $currentMode) {
        api_clear_session();
        throw new GameException('نشست قبلی پایان یافت. لطفاً وارد حساب کاردینال شوید.', 401);
    }
    $playerId = $_SESSION['cardinal_player_id'] ?? null;
    if (!is_int($playerId) && !(is_string($playerId) && ctype_digit($playerId))) {
        throw new GameException('نشست شما پایان یافته است. دوباره وارد شوید.', 401);
    }
    $playerId = (int) $playerId;
    if ($playerId < 1) throw new GameException('نشست شما پایان یافته است. دوباره وارد شوید.', 401);
    $token = $_SESSION['cardinal_session_lease'] ?? null;
    if (!is_string($token) || $token === '') {
        api_clear_session();
        throw new GameException('نشست شما پایان یافته است. دوباره وارد شوید.', 401);
    }
    if (!api_session_lease_is_active($playerId, $token)) {
        // Do not release the lease here: it now belongs to the newer browser.
        api_clear_session();
        throw new GameException('این حساب در مرورگر یا دستگاه دیگری وارد شده است؛ برای امنیت، نشست قبلی پایان یافت.', 401);
    }
    return $playerId;
}

function api_write_session(int $playerId): void
{
    // A fresh token replaces any prior browser lease for this same account.
    // Releasing a different account first prevents one browser from holding
    // stale ownership after it deliberately signs into another account.
    $token = api_issue_session_lease($playerId);
    api_release_current_session_lease();
    session_regenerate_id(true);
    $_SESSION['cardinal_player_id'] = $playerId;
    $_SESSION['cardinal_server_id'] = 5;
    $_SESSION['cardinal_mode'] = cardinal_using_demo() ? 'demo' : 'live';
    $_SESSION['cardinal_session_lease'] = $token;
}

function api_clear_session(): void
{
    api_release_current_session_lease();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

$route = trim((string) ($_GET['route'] ?? ''), '/');

try {
    if ($route === 'health') {
        api_require_method('GET');
        // Open the real connection here so health accurately reports deployment failures.
        if (!cardinal_using_demo()) cardinal_live_game();
        api_success(['serverId' => 5, 'demoMode' => cardinal_using_demo(), 'timestamp' => gmdate('c')]);
    }

    if ($route === 'auth/login') {
        api_require_method('POST'); $input = api_input();
        $phone = isset($input['phone']) ? (string) $input['phone'] : '';
        $password = isset($input['password']) ? (string) $input['password'] : '';
        if (strlen($phone) > 32 || strlen($password) > 256) throw new GameException('ورودی حساب نامعتبر است.');
        $result = cardinal_game()->authenticate($phone, $password);
        api_write_session((int) $result['playerId']);
        api_success(['player' => $result['player']]);
    }

    if ($route === 'auth/register') {
        api_require_method('POST'); $input = api_input();
        $name = isset($input['name']) ? (string) $input['name'] : '';
        $gender = isset($input['gender']) ? (string) $input['gender'] : '';
        $classId = $input['classId'] ?? null;
        $referral = isset($input['referralCode']) ? (string) $input['referralCode'] : null;
        if (strlen($name) > 100 || ($referral !== null && strlen($referral) > 100)) throw new GameException('ورودی ثبت‌نام نامعتبر است.');
        $result = cardinal_game()->register($name, $gender, $classId, $referral);
        api_write_session((int) $result['playerId']);
        api_success(['player' => $result['player']], 201);
    }

    if ($route === 'auth/demo') {
        api_require_method('POST'); api_input();
        if (!cardinal_using_demo()) throw new GameException('ورود پیش‌نمایش در محیط عملیاتی غیرفعال است.', 404);
        $result = cardinal_game()->authenticate('00000000000', 'PreviewOnly');
        api_write_session((int) $result['playerId']);
        api_success(['player' => $result['player']]);
    }

    if ($route === 'auth/logout') {
        api_require_method('POST'); api_input(); api_clear_session(); api_success((object) []);
    }

    $playerId = api_player_id();
    // Lightweight heartbeat used by the browser to notice immediately when a
    // newer login has replaced this session. It does not touch game data.
    if ($route === 'session') {
        api_require_method('GET');
        api_success(['active' => true]);
    }
    $game = cardinal_game();

    if ($route === 'me') {
        api_require_method('GET');
        try {
            api_success(['player' => $game->snapshot($playerId), 'demoMode' => cardinal_using_demo()]);
        } catch (GameException $error) {
            // A stale/deleted player id is authentication state, not a database
            // outage. Clear it so the landing page shows the login form again.
            if ($error->status() === 404) {
                api_clear_session();
                throw new GameException('نشست شما پایان یافته است. دوباره وارد شوید.', 401);
            }
            throw $error;
        }
    }
    if ($route === 'equipment') { api_require_method('GET'); api_success($game->getEquipment($playerId)); }
    if ($route === 'inventory') { api_require_method('GET'); api_success($game->getInventory($playerId)); }
    if ($route === 'shop') { api_require_method('GET'); api_success($game->getShop($playerId)); }
    if ($route === 'boss') { api_require_method('GET'); api_success($game->getBoss($playerId)); }
    if ($route === 'quests') { api_require_method('GET'); api_success($game->getDailyQuests($playerId)); }
    if ($route === 'party') { api_require_method('GET'); api_success($game->getParty($playerId)); }
    if ($route === 'social') { api_require_method('GET'); api_success($game->getSocial($playerId)); }
    if ($route === 'guild') { api_require_method('GET'); api_success($game->getGuild($playerId)); }
    if ($route === 'crafting') { api_require_method('GET'); api_success($game->getCrafting($playerId)); }
    if ($route === 'noble') { api_require_method('GET'); api_success($game->getNoble($playerId)); }
    if ($route === 'achievements') { api_require_method('GET'); api_success($game->getAchievements($playerId)); }
    if ($route === 'notifications') { api_require_method('GET'); api_success($game->notifications($playerId)); }

    if (preg_match('#^leaderboard/(players|killers|guilds|groups)$#', $route, $matches) === 1) {
        api_require_method('GET');
        $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int) floor((float) $_GET['page'])) : 1;
        api_success($game->leaderboard($matches[1], $page));
    }

    if (preg_match('#^action/([a-z-]{2,48})$#', $route, $matches) === 1) {
        api_require_method('POST');
        api_success($game->action($playerId, $matches[1], api_input()));
    }

    if ($route === 'account/save') {
        api_require_method('POST'); $input = api_input();
        api_success($game->saveAccount($playerId, (string) ($input['phone'] ?? ''), (string) ($input['password'] ?? '')));
    }
    if ($route === 'account/change-phone') {
        api_require_method('POST'); $input = api_input();
        api_success($game->changePhone($playerId, (string) ($input['currentPassword'] ?? ''), (string) ($input['phone'] ?? '')));
    }
    if ($route === 'account/change-password') {
        api_require_method('POST'); $input = api_input();
        api_success($game->changePassword($playerId, (string) ($input['currentPassword'] ?? ''), (string) ($input['nextPassword'] ?? '')));
    }
    if ($route === 'account/change-name') {
        api_require_method('POST'); $input = api_input();
        api_success($game->changeName($playerId, (string) ($input['name'] ?? '')));
    }
    if ($route === 'account/delete') {
        api_require_method('POST'); $input = api_input();
        $result = $game->deleteAccount($playerId, (string) ($input['confirmation'] ?? ''));
        if (!cardinal_using_demo()) api_clear_session();
        api_success($result);
    }

    throw new GameException('مسیر API پیدا نشد.', 404);
} catch (GameException $error) {
    api_error($error->getMessage(), $error->status());
} catch (Throwable $error) {
    // Do not include a SQL/server stack in a browser response.
    error_log('[Cardinal Web V] unexpected API error: ' . get_class($error));
    api_error('خطای غیرمنتظره در سرور رخ داد. لطفاً دوباره تلاش کنید.', 500);
}
