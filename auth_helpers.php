<?php
/**
 * Shared helpers for the three token-based flows: "remember me",
 * password reset, and email verification. All three follow the same
 * "selector/validator" pattern:
 *   - selector: a random string used to look the row up in the DB (not secret)
 *   - validator: a random secret, hashed with SHA-256 before storing, and
 *     compared with hash_equals() to avoid timing attacks
 * This is the standard, well-reviewed pattern for long-lived tokens sent
 * over email/cookies (see e.g. the classic "remember me" write-ups by
 * Paragon IE / Barry Jaspan). SHA-256 (not password_hash/bcrypt) is
 * appropriate here because these are high-entropy random tokens, not
 * user-chosen passwords.
 */
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/mailer.php';

function generateTokenPair(): array {
    $selector  = bin2hex(random_bytes(8));   // 16 hex chars, used for DB lookup
    $validator = bin2hex(random_bytes(32));  // 64 hex chars, the actual secret
    return [$selector, $validator];
}

/** Create and store a token of the given type for a user. Returns [selector, validator]. */
function storeToken(PDO $pdo, int $userId, string $type, int $expiryMinutes): array {
    [$selector, $validator] = generateTokenPair();
    $hash = hash('sha256', $validator);
    $expires = date('Y-m-d H:i:s', time() + $expiryMinutes * 60);

    $stmt = $pdo->prepare(
        "INSERT INTO user_tokens (user_id, type, selector, validator_hash, expires_at) VALUES (:uid, :type, :sel, :hash, :exp)"
    );
    $stmt->execute(['uid' => $userId, 'type' => $type, 'sel' => $selector, 'hash' => $hash, 'exp' => $expires]);

    return [$selector, $validator];
}

/** Look up and validate a token. Returns the DB row (with user_id, token_id) or false. */
function verifyToken(PDO $pdo, string $type, string $selector, string $validator) {
    $stmt = $pdo->prepare(
        "SELECT * FROM user_tokens WHERE type = :type AND selector = :sel AND expires_at > NOW()"
    );
    $stmt->execute(['type' => $type, 'sel' => $selector]);
    $row = $stmt->fetch();

    if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        return false;
    }
    return $row;
}

function deleteToken(PDO $pdo, int $tokenId): void {
    $stmt = $pdo->prepare("DELETE FROM user_tokens WHERE token_id = :id");
    $stmt->execute(['id' => $tokenId]);
}

function deleteTokensForUser(PDO $pdo, int $userId, string $type): void {
    $stmt = $pdo->prepare("DELETE FROM user_tokens WHERE user_id = :uid AND type = :type");
    $stmt->execute(['uid' => $userId, 'type' => $type]);
}

/** Issue a fresh "remember me" cookie + DB token for a user (30 days). */
function setRememberCookie(PDO $pdo, int $userId): void {
    $minutes = 60 * 24 * 30; // 30 days
    [$selector, $validator] = storeToken($pdo, $userId, 'remember', $minutes);

    setcookie('remember_me', "$selector:$validator", [
        'expires'  => time() + $minutes * 60,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // 'secure' => true, // TODO: uncomment once the site is served over HTTPS
    ]);
}

function clearRememberCookie(): void {
    setcookie('remember_me', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Generate + store an email-verification token and send the email. */
function sendVerificationEmail(PDO $pdo, int $userId, string $email, string $username): void {
    deleteTokensForUser($pdo, $userId, 'email_verify');
    [$selector, $validator] = storeToken($pdo, $userId, 'email_verify', 60 * 24); // 24 hours

    $link = APP_BASE_URL . '/verify-email.html?selector=' . urlencode($selector) . '&validator=' . urlencode($validator);
    sendEmail($email, 'Verify your quaSense email address', verificationEmailBody($username, $link));
}

/** Generate + store a password-reset token and send the email. */
function sendPasswordResetEmail(PDO $pdo, int $userId, string $email, string $username): void {
    deleteTokensForUser($pdo, $userId, 'password_reset');
    [$selector, $validator] = storeToken($pdo, $userId, 'password_reset', 60); // 1 hour

    $link = APP_BASE_URL . '/reset-password.html?selector=' . urlencode($selector) . '&validator=' . urlencode($validator);
    sendEmail($email, 'Reset your quaSense password', passwordResetEmailBody($username, $link));
}
