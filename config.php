<?php
declare(strict_types=1);

/*
 * Cardinal Web V — deployment configuration
 *
 * This file is deliberately safe to include in an upload archive: it contains
 * no credential. For production, preferably set the CARDINAL_DB_* variables in
 * the cPanel application/environment configuration or return values from a
 * private file outside public_html (see private-config.example.php).
 */

// Search a few parents so an extracted subdirectory can still use the normal
// cPanel-safe /home/ACCOUNT/cardinal-private.php location.
$private = [];
$privateBase = dirname(__DIR__);
$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
for ($depth = 0; $depth < 4; $depth += 1) {
    $candidate = $privateBase . '/cardinal-private.php';
    $candidatePath = realpath($candidate);
    // Do not load a secret that was accidentally placed beneath web root.
    $insideDocumentRoot = $documentRoot && $candidatePath && ($candidatePath === $documentRoot || strpos($candidatePath, $documentRoot . DIRECTORY_SEPARATOR) === 0);
    if ($candidatePath && !$insideDocumentRoot) {
        $loaded = require $candidatePath;
        $private = is_array($loaded) ? $loaded : [];
        break;
    }
    $nextBase = dirname($privateBase);
    if ($nextBase === $privateBase) break;
    $privateBase = $nextBase;
}

function cardinal_config_value(array $private, string $key, string ...$environments): string
{
    foreach ($environments as $environment) {
        $fromEnvironment = getenv($environment);
        if (is_string($fromEnvironment) && $fromEnvironment !== '') return $fromEnvironment;
    }
    return isset($private[$key]) ? trim((string) $private[$key]) : '';
}

return [
    // CARDINAL_DB_* avoids collisions; DB_* matches the former web package.
    'db_host' => cardinal_config_value($private, 'db_host', 'CARDINAL_DB_HOST', 'DB_HOST'),
    'db_port' => cardinal_config_value($private, 'db_port', 'CARDINAL_DB_PORT', 'DB_PORT') ?: '3306',
    'db_name' => cardinal_config_value($private, 'db_name', 'CARDINAL_DB_NAME', 'DB_NAME'),
    'db_user' => cardinal_config_value($private, 'db_user', 'CARDINAL_DB_USER', 'DB_USER'),
    'db_password' => cardinal_config_value($private, 'db_password', 'CARDINAL_DB_PASSWORD', 'DB_PASSWORD'),
    'web_server_id' => 5,
    // With no configured DB, the landing page remains usable as an isolated demo.
    'allow_unconfigured_demo' => true,
];
