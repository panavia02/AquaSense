<?php
/**
 * ONE-TIME SETUP SCRIPT.
 *
 * Run this once from the command line to create your first admin account:
 *   php create_admin.php myadminusername me@example.com
 * It will prompt you to type a password (hidden input where supported).
 * This account is created as already email-verified since you're creating
 * it directly on the server yourself.
 *
 * After you've created your first admin and confirmed you can log in,
 * DELETE this file from your server.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("This script must be run from the command line, not a browser.\n");
}

require_once __DIR__ . '/db.php';

$username = $argv[1] ?? null;
$email    = $argv[2] ?? null;

if (!$username || !$email) {
    die("Usage: php create_admin.php <username> <email>\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Error: '$email' doesn't look like a valid email address.\n");
}

echo "Password for '$username': ";
system('stty -echo 2>/dev/null');
$password = trim(fgets(STDIN));
system('stty echo 2>/dev/null');
echo "\n";

if (strlen($password) < 8) {
    die("Password must be at least 8 characters.\n");
}

try {
    $pdo = getPDO();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        "INSERT INTO users (username, email, email_verified, password_hash, is_admin, is_active)
         VALUES (:username, :email, 1, :hash, 1, 1)"
    );
    $stmt->execute(['username' => $username, 'email' => $email, 'hash' => $hash]);

    echo "Admin user '$username' created successfully.\n";
    echo "Remember to delete create_admin.php now.\n";
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        die("Error: that username or email is already in use.\n");
    }
    die("Database error: " . $e->getMessage() . "\n");
}
