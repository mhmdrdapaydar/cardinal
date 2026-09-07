<?php
/**
 * DEV HARNESS — not part of the upload package, not uploaded, denied by .htaccess.
 *
 * Turns environment variables into a realistic request and runs realtime.php,
 * so presence and chat can be exercised without a web server.
 *
 * Two things make this faithful rather than a mock:
 *   - a real PHP session file is seeded on disk and referenced through the
 *     session cookie, so realtime.php's own session_start() is what runs;
 *   - php://input is served by a registered stream wrapper, so rt_input()
 *     reads the body exactly as it would over HTTP. No test hook is needed in
 *     the shipped file.
 */

final class RtInputStream
{
    public $context;
    private string $data = '';
    private int $pos = 0;

    public function stream_open($path, $mode, $options, &$opened): bool
    {
        if ($path === 'php://input') {
            $this->data = (string) getenv('RT_BODY');
            $this->pos = 0;
            return true;
        }
        if (strpos($path, 'php://temp') === 0 || strpos($path, 'php://memory') === 0) {
            $this->data = '';
            $this->pos = 0;
            return true;
        }
        return false;
    }
    public function stream_read($count) { $out = substr($this->data, $this->pos, $count); $this->pos += strlen($out); return $out; }
    public function stream_write($data) { $this->data .= $data; return strlen($data); }
    public function stream_eof(): bool { return $this->pos >= strlen($this->data); }
    public function stream_tell(): int { return $this->pos; }
    public function stream_seek($offset, $whence = SEEK_SET): bool { $this->pos = $offset; return true; }
    public function stream_stat() { return ['size' => strlen($this->data)]; }
    public function stream_close(): void {}
}

stream_wrapper_unregister('php');
stream_wrapper_register('php', RtInputStream::class);

$sessDir = getenv('RT_SESSDIR');
@mkdir($sessDir, 0777, true);
ini_set('session.save_path', $sessDir);
ini_set('session.use_strict_mode', '0');

$sid = getenv('RT_SID') ?: 'testsession';
$pid = getenv('RT_PID');
$file = $sessDir . '/sess_' . $sid;
if ($pid !== false && $pid !== '') {
    if (!file_exists($file)) file_put_contents($file, 'cardinal_player_id|i:' . (int) $pid . ';');
} else {
    @unlink($file);
}
$_COOKIE['cardinal_web_session_v2'] = $sid;

$_SERVER['REQUEST_METHOD'] = getenv('RT_METHOD') ?: 'GET';
$_SERVER['SCRIPT_NAME'] = '/realtime.php';
parse_str((string) getenv('RT_QUERY'), $_GET);

putenv('CARDINAL_REALTIME_DB=' . getenv('RT_DB'));

require dirname(__DIR__, 2) . '/realtime.php';
