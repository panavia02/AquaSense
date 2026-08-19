<?php
/**
 * Shared database connection config + app-wide constants.
 *
 * IMPORTANT: In a real deployment, don't keep credentials in a file that
 * lives in your web root / git repo at all. Better options, roughly in
 * order of effort:
 *   1. Put this file OUTSIDE the web-accessible directory (e.g. one level
 *      above public_html) and require_once it with a relative path.
 *   2. Load values from environment variables (getenv('DB_PASS')) that are
 *      set in your hosting panel / .env file that is gitignored.
 */

$host    = 'localhost';
$db      = 'test';
$user    = 'app_db_dsx6ek';
$pass    = ')+rP6CD+ycg$LW4K2a';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

function getPDO() {
    global $dsn, $user, $pass, $options;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO($dsn, $user, $pass, $options);
    }
    return $pdo;
}

// TODO: update these two to match your real domain and a mailbox you
// control. They're used to build links in emails and as the "From" address.
define('APP_BASE_URL', 'https://panavia.duckdns.org/');
define('MAIL_FROM', 'no-reply@panavia.duckdns.org');
define('MAIL_FROM_NAME', 'AquaSense');
