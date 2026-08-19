<?php
/**
 * Include this (instead of calling session_start() directly) at the top of
 * any PHP file that needs to know who's logged in. It starts the session
 * and, if there's no active session but a valid "remember me" cookie is
 * present, transparently re-establishes the session from it.
 */

ini_set('display_errors', '0');
error_reporting(0);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth_helpers.php';

function currentUser() {
    if (!empty($_SESSION['user_id'])) {
        return [
            'user_id'  => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'is_admin' => (bool)$_SESSION['is_admin'],
        ];
    }

    if (!empty($_COOKIE['remember_me'])) {
        $parts = explode(':', $_COOKIE['remember_me'], 2);
        if (count($parts) === 2) {
            [$selector, $validator] = $parts;
            $pdo = getPDO();
            $tokenRow = verifyToken($pdo, 'remember', $selector, $validator);

            if ($tokenRow) {
                $stmt = $pdo->prepare("SELECT user_id, username, is_admin, is_active FROM users WHERE user_id = :id");
                $stmt->execute(['id' => $tokenRow['user_id']]);
                $user = $stmt->fetch();

                if ($user && $user['is_active']) {
                    session_regenerate_id(true);
                    $_SESSION['user_id']  = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['is_admin'] = (bool)$user['is_admin'];

                    // Rotate the token on each use: invalidate the old one and
                    // issue a new one. This limits how long a stolen cookie
                    // stays useful and lets us detect reuse of an old token.
                    deleteToken($pdo, $tokenRow['token_id']);
                    setRememberCookie($pdo, $user['user_id']);

                    return [
                        'user_id'  => $user['user_id'],
                        'username' => $user['username'],
                        'is_admin' => (bool)$user['is_admin'],
                    ];
                }
            }

            // Invalid, expired, or already-rotated token: clear the cookie
            // so the browser stops sending it.
            clearRememberCookie();
        }
    }

    return null;
}

function requireLogin() {
    $user = currentUser();
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'data' => null, 'error' => 'Not logged in.']);
        exit;
    }
    return $user;
}

function requireAdmin() {
    $user = requireLogin();
    if (!$user['is_admin']) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'data' => null, 'error' => 'Admin access required.']);
        exit;
    }
    return $user;
}
