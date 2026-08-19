<?php
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/session_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'data' => null, 'error' => null];

// State-changing actions require POST; read-only/link-triggered ones accept
// either since they're reached by clicking an emailed link.
$postOnly = ['login', 'logout', 'forgot_password', 'reset_password', 'resend_verification', 'change_password'];
$action = isset($_POST['action']) ? $_POST['action'] : ($_GET['action'] ?? null);

if (in_array($action, $postOnly, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['error'] = 'This action requires POST.';
    echo json_encode($response);
    exit;
}

switch ($action) {
    case 'login':
        login();
        break;
    case 'logout':
        logout();
        break;
    case 'check':
        checkSession();
        break;
    case 'forgot_password':
        forgotPassword();
        break;
    case 'reset_password':
        resetPassword();
        break;
    case 'verify_email':
        verifyEmail();
        break;
    case 'resend_verification':
        resendVerification();
        break;
    case 'change_password':
        changePassword();
        break;
    default:
        http_response_code(400);
        $response['error'] = 'Invalid action.';
        break;
}

echo json_encode($response);
exit;

function login() {
    global $response;

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    if ($username === '' || $password === '') {
        http_response_code(400);
        $response['error'] = 'Username and password are required.';
        return;
    }

    // Basic brute-force throttling per session.
    $_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
    $_SESSION['login_attempts_window'] = $_SESSION['login_attempts_window'] ?? time();
    if (time() - $_SESSION['login_attempts_window'] > 300) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_attempts_window'] = time();
    }
    if ($_SESSION['login_attempts'] >= 10) {
        http_response_code(429);
        $response['error'] = 'Too many login attempts. Please wait a few minutes and try again.';
        return;
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare(
            "SELECT user_id, username, email, email_verified, password_hash, is_admin, is_active FROM users WHERE username = :username"
        );
        $stmt->execute(['username' => $username]);
        $userRow = $stmt->fetch();

        // Always run password_verify, even against a dummy hash, so timing
        // doesn't reveal whether the username exists.
        $hashToCheck = $userRow['password_hash'] ?? '$2y$10$invalidsaltinvalidsaltinvalidsa';
        $passwordOk = password_verify($password, $hashToCheck);

        if (!$userRow || !$passwordOk || !$userRow['is_active']) {
            $_SESSION['login_attempts']++;
            http_response_code(401);
            $response['error'] = 'Invalid username or password.';
            return;
        }

        if (!$userRow['email_verified']) {
            http_response_code(403);
            $response['error'] = 'Please verify your email address before logging in.';
            $response['data'] = ['needsVerification' => true, 'email' => $userRow['email']];
            return;
        }

        $_SESSION['login_attempts'] = 0;
        session_regenerate_id(true);

        $_SESSION['user_id']  = $userRow['user_id'];
        $_SESSION['username'] = $userRow['username'];
        $_SESSION['is_admin'] = (bool)$userRow['is_admin'];

        $update = $pdo->prepare("UPDATE users SET last_login = :now WHERE user_id = :id");
        $update->execute(['now' => date('Y-m-d H:i:s'), 'id' => $userRow['user_id']]);

        if ($remember) {
            setRememberCookie($pdo, $userRow['user_id']);
        }

        $response['success'] = true;
        $response['data'] = [
            'username' => $userRow['username'],
            'is_admin' => (bool)$userRow['is_admin'],
        ];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function logout() {
    global $response;

    if (!empty($_COOKIE['remember_me'])) {
        $parts = explode(':', $_COOKIE['remember_me'], 2);
        if (count($parts) === 2) {
            $pdo = getPDO();
            $row = verifyToken($pdo, 'remember', $parts[0], $parts[1]);
            if ($row) {
                deleteToken($pdo, $row['token_id']);
            }
        }
        clearRememberCookie();
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    $response['success'] = true;
}

function checkSession() {
    global $response;
    $user = currentUser();
    if ($user) {
        // is_location_admin here just means "admin of AT LEAST ONE
        // location" -- enough for login.js to decide whether to show the
        // Admin Users nav link at all. Which specific location(s) they
        // administer, and what they can do there, is all re-derived and
        // re-checked by admin_users.php/locations.php on their own -- this
        // flag is a UI convenience, not a security decision.
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT 1 FROM user_locations WHERE user_id = :uid AND is_location_admin = 1 LIMIT 1");
        $stmt->execute(['uid' => $user['user_id']]);
        $isLocationAdmin = (bool)$stmt->fetchColumn();

        $response['success'] = true;
        $response['data'] = [
            'loggedIn' => true,
            'username' => $user['username'],
            'is_admin' => $user['is_admin'],
            'is_location_admin' => $isLocationAdmin,
        ];
    } else {
        $response['success'] = true;
        $response['data'] = ['loggedIn' => false];
    }
}

function forgotPassword() {
    global $response;

    $email = trim($_POST['email'] ?? '');
    // Always return the same generic message, whether or not the email is
    // registered, so this endpoint can't be used to enumerate accounts.
    $response['success'] = true;
    $response['data'] = ['message' => 'If that email is registered, a password reset link has been sent.'];

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE email = :email AND is_active = 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            sendPasswordResetEmail($pdo, $user['user_id'], $email, $user['username']);
        }
    } catch (PDOException $e) {
        // Deliberately don't leak DB errors here either -- log and keep the
        // generic response.
        error_log('forgot_password error: ' . $e->getMessage());
    }
}

function resetPassword() {
    global $response;

    $selector  = $_POST['selector'] ?? '';
    $validator = $_POST['validator'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if ($selector === '' || $validator === '') {
        http_response_code(400);
        $response['error'] = 'Missing reset token.';
        return;
    }
    if (strlen($newPassword) < 8) {
        http_response_code(400);
        $response['error'] = 'Password must be at least 8 characters.';
        return;
    }

    try {
        $pdo = getPDO();
        $tokenRow = verifyToken($pdo, 'password_reset', $selector, $validator);
        if (!$tokenRow) {
            http_response_code(400);
            $response['error'] = 'This reset link is invalid or has expired. Please request a new one.';
            return;
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id");
        $update->execute(['hash' => $hash, 'id' => $tokenRow['user_id']]);

        // Token is single-use.
        deleteToken($pdo, $tokenRow['token_id']);
        // Force logout everywhere: if the account was compromised, this
        // invalidates any "remember me" sessions an attacker may hold.
        deleteTokensForUser($pdo, $tokenRow['user_id'], 'remember');

        $response['success'] = true;
        $response['data'] = ['message' => 'Password updated. You can now log in with your new password.'];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function verifyEmail() {
    global $response;

    $selector  = $_POST['selector']  ?? $_GET['selector']  ?? '';
    $validator = $_POST['validator'] ?? $_GET['validator'] ?? '';

    if ($selector === '' || $validator === '') {
        http_response_code(400);
        $response['error'] = 'Missing verification token.';
        return;
    }

    try {
        $pdo = getPDO();
        $tokenRow = verifyToken($pdo, 'email_verify', $selector, $validator);
        if (!$tokenRow) {
            http_response_code(400);
            $response['error'] = 'This verification link is invalid or has expired. You can request a new one from the login page.';
            return;
        }

        $update = $pdo->prepare("UPDATE users SET email_verified = 1 WHERE user_id = :id");
        $update->execute(['id' => $tokenRow['user_id']]);
        deleteToken($pdo, $tokenRow['token_id']);

        $response['success'] = true;
        $response['data'] = ['message' => 'Email verified! You can now log in.'];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function resendVerification() {
    global $response;

    $email = trim($_POST['email'] ?? '');
    // Generic response regardless of outcome, to avoid leaking which emails
    // are registered / already verified.
    $response['success'] = true;
    $response['data'] = ['message' => 'If that email needs verifying, a new link has been sent.'];

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare(
            "SELECT user_id, username FROM users WHERE email = :email AND email_verified = 0 AND is_active = 1"
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            sendVerificationEmail($pdo, $user['user_id'], $email, $user['username']);
        }
    } catch (PDOException $e) {
        error_log('resend_verification error: ' . $e->getMessage());
    }
}

/**
 * Self-service password change for a logged-in user (any user, not just
 * admins -- see account.js on the Settings page). Requires the current
 * password as proof, same as you'd want on any "change my password"
 * form, then revokes remember-me tokens on OTHER devices as a
 * precaution (the current session/device stays logged in).
 *
 * TODO: doesn't check that the new password differs from the current
 * one -- not a security issue, just a minor UX gap (someone could
 * "change" their password to the same value and get a success message).
 */
function changePassword() {
    global $response;

    // Unlike the other actions here, this one requires an existing session --
    // it's for a logged-in user changing their own password, not a token flow.
    $user = requireLogin();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';

    if (strlen($newPassword) < 8) {
        http_response_code(400);
        $response['error'] = 'New password must be at least 8 characters.';
        return;
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :id");
        $stmt->execute(['id' => $user['user_id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            http_response_code(401);
            $response['error'] = 'Current password is incorrect.';
            return;
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :id");
        $update->execute(['hash' => $hash, 'id' => $user['user_id']]);

        // Revoke remember-me sessions on other devices as a precaution --
        // the current session (and its cookie, if any) stays valid.
        deleteTokensForUser($pdo, $user['user_id'], 'remember');

        $response['success'] = true;
        $response['data'] = ['message' => 'Password updated.'];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}
