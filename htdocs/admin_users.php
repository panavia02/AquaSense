<?php
/**
 * User account management. Two tiers of access, both handled in this one
 * file (rather than splitting into a separate location-admin file) so
 * there's a single source of truth for what counts as "managing a user":
 *
 *   - Master admin (users.is_admin): full access. Sees every user, can
 *     create/delete accounts, and can change is_admin/is_active on anyone.
 *   - Location admin (user_locations.is_location_admin=1 somewhere, but not
 *     a master admin): sees only users who have SOME assignment (location-
 *     or section-level) under a location they administer. Can edit that
 *     user's email and reset their password, and can resend a verification
 *     email -- but CANNOT create new accounts, delete accounts, or change
 *     is_admin/is_active on anyone. Those are enforced server-side below,
 *     independent of what admin.js chooses to show/hide in the UI.
 *
 * Why is_active is master-admin-only even for a location admin managing
 * "their" user: is_active is a global, account-wide flag -- deactivating a
 * user blocks their login everywhere, not just in the caller's location.
 * A location admin removing someone from THEIR location should use
 * locations.php's remove_location/remove_section instead, which only
 * affects that one location's access rather than the whole account.
 *
 * Why account creation stays master-admin-only: a brand new account has no
 * assignments yet, so there's no location-scoped version of "create" that
 * cleanly fits this page. A location admin who wants to add an EXISTING
 * user to their location already has that via locations.php's
 * search_users + assign_location/assign_section (see facility.js).
 */
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/location_helpers.php';

$currentUser = requireLogin();
$isMasterAdmin = (bool)$currentUser['is_admin'];
$callerAdminLocationIds = $isMasterAdmin ? [] : getAdminLocationIds(getPDO(), $currentUser['user_id']);

if (!$isMasterAdmin && empty($callerAdminLocationIds)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'data' => null, 'error' => 'Admin access required.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'data' => null, 'error' => null];

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'POST' ? ($_POST['action'] ?? null) : ($_GET['action'] ?? null);

switch ($action) {
    case 'list':
        listUsers();
        break;
    case 'create':
        createUser();
        break;
    case 'update':
        updateUser();
        break;
    case 'delete':
        deleteUser();
        break;
    case 'resend_verification':
        resendVerificationForUser();
        break;
    default:
        http_response_code(400);
        $response['error'] = 'Invalid action.';
        break;
}

echo json_encode($response);
exit;

function listUsers() {
    global $response, $isMasterAdmin, $callerAdminLocationIds;
    try {
        $pdo = getPDO();

        if ($isMasterAdmin) {
            $stmt = $pdo->query(
                "SELECT user_id, username, email, email_verified, is_admin, is_active, created_at, last_login FROM users ORDER BY username ASC"
            );
            $users = $stmt->fetchAll();
        } else {
            // Only users with SOME assignment (location- or section-level)
            // under a location this caller administers -- not the full
            // user list, per the scoping rules explained at the top of
            // this file.
            $placeholders = implode(',', array_fill(0, count($callerAdminLocationIds), '?'));
            $sql = "SELECT DISTINCT u.user_id, u.username, u.email, u.email_verified, u.is_admin, u.is_active, u.created_at, u.last_login
                    FROM users u
                    WHERE u.user_id IN (
                        SELECT ul.user_id FROM user_locations ul WHERE ul.location_id IN ($placeholders)
                        UNION
                        SELECT us.user_id FROM user_sections us
                        JOIN section_names sn ON sn.section_id = us.section_id
                        WHERE sn.location_id IN ($placeholders)
                    )
                    ORDER BY u.username ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($callerAdminLocationIds, $callerAdminLocationIds));
            $users = $stmt->fetchAll();
        }

        $response['success'] = true;
        // is_master_admin travels with the list so admin.js knows whether to
        // render the create-user form and the is_admin/is_active/delete
        // controls -- purely a UI convenience, since every action below
        // re-checks this server-side regardless of what the client sends.
        $response['data'] = [
            'users' => $users,
            'is_master_admin' => $isMasterAdmin,
        ];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function createUser() {
    global $response, $isMasterAdmin;

    if (!$isMasterAdmin) {
        http_response_code(403);
        $response['error'] = 'Only a master admin can create new accounts. To add an existing user to your location, use the Facility Location page instead.';
        return;
    }

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $isAdmin  = !empty($_POST['is_admin']) ? 1 : 0;

    if ($username === '' || strlen($password) < 8) {
        http_response_code(400);
        $response['error'] = 'Username is required and password must be at least 8 characters.';
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        $response['error'] = 'A valid email address is required.';
        return;
    }

    try {
        $pdo = getPDO();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, email, password_hash, is_admin, is_active, email_verified)
             VALUES (:username, :email, :hash, :is_admin, 1, 0)"
        );
        $stmt->execute(['username' => $username, 'email' => $email, 'hash' => $hash, 'is_admin' => $isAdmin]);
        $newId = $pdo->lastInsertId();

        sendVerificationEmail($pdo, $newId, $email, $username);

        $response['success'] = true;
        $response['data'] = ['user_id' => $newId, 'username' => $username];
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            http_response_code(409);
            $response['error'] = 'That username or email is already in use.';
            return;
        }
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function updateUser() {
    global $response, $currentUser, $isMasterAdmin;

    $id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        $response['error'] = 'Missing or invalid user_id.';
        return;
    }

    try {
        $pdo = getPDO();

        if (!$isMasterAdmin) {
            if (!canManageUserAccount($pdo, $currentUser, $id)) {
                http_response_code(403);
                $response['error'] = 'You can only manage users assigned to a location you administer.';
                return;
            }
            // Belt-and-suspenders: admin.js never sends these fields for a
            // non-master caller (the checkboxes are disabled), but reject
            // explicitly here too in case of a hand-crafted request, rather
            // than silently stripping them, so the failure is obvious.
            if (isset($_POST['is_admin']) || isset($_POST['is_active'])) {
                http_response_code(403);
                $response['error'] = 'Only a master admin can change admin status or active status.';
                return;
            }
        }

        $fields = [];
        $params = ['id' => $id];
        $emailChanged = false;
        $newEmail = null;

        if ($isMasterAdmin && isset($_POST['is_admin'])) {
            $fields[] = 'is_admin = :is_admin';
            $params['is_admin'] = $_POST['is_admin'] ? 1 : 0;
        }
        if ($isMasterAdmin && isset($_POST['is_active'])) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = $_POST['is_active'] ? 1 : 0;
        }
        if (!empty($_POST['password'])) {
            if (strlen($_POST['password']) < 8) {
                http_response_code(400);
                $response['error'] = 'Password must be at least 8 characters.';
                return;
            }
            $fields[] = 'password_hash = :password_hash';
            $params['password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
        }
        if (!empty($_POST['email'])) {
            $newEmail = trim($_POST['email']);
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                $response['error'] = 'A valid email address is required.';
                return;
            }
            $fields[] = 'email = :email';
            $params['email'] = $newEmail;
            // Changing the email means it's unverified until they confirm
            // the new address.
            $fields[] = 'email_verified = 0';
            $emailChanged = true;
        }

        if (empty($fields)) {
            http_response_code(400);
            $response['error'] = 'No fields to update.';
            return;
        }

        // Guard: don't allow removing admin/active status from the last active admin.
        // (Only ever reachable when $isMasterAdmin, since is_admin/is_active
        // are only added to $fields above in that case.)
        if ((isset($params['is_admin']) && $params['is_admin'] == 0) ||
            (isset($params['is_active']) && $params['is_active'] == 0)) {
            $count = $pdo->query(
                "SELECT COUNT(*) AS c FROM users WHERE is_admin = 1 AND is_active = 1"
            )->fetch()['c'];

            $targetStmt = $pdo->prepare("SELECT is_admin, is_active, username FROM users WHERE user_id = :id");
            $targetStmt->execute(['id' => $id]);
            $target = $targetStmt->fetch();

            if ($target && $target['is_admin'] && $target['is_active'] && $count <= 1) {
                http_response_code(409);
                $response['error'] = 'Cannot remove admin/active status from the last remaining admin.';
                return;
            }
        }

        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE user_id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($emailChanged) {
            $userStmt = $pdo->prepare("SELECT username FROM users WHERE user_id = :id");
            $userStmt->execute(['id' => $id]);
            $username = $userStmt->fetch()['username'];
            sendVerificationEmail($pdo, $id, $newEmail, $username);
        }

        $response['success'] = true;
        $response['data'] = ['user_id' => $id, 'rowsAffected' => $stmt->rowCount()];
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            http_response_code(409);
            $response['error'] = 'That email is already in use.';
            return;
        }
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function deleteUser() {
    global $response, $currentUser, $isMasterAdmin;

    if (!$isMasterAdmin) {
        http_response_code(403);
        $response['error'] = 'Only a master admin can delete accounts.';
        return;
    }

    $id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        $response['error'] = 'Missing or invalid user_id.';
        return;
    }

    if ($id == $currentUser['user_id']) {
        http_response_code(400);
        $response['error'] = "You can't delete your own account while logged in as it.";
        return;
    }

    try {
        $pdo = getPDO();

        $target = $pdo->prepare("SELECT is_admin, is_active FROM users WHERE user_id = :id");
        $target->execute(['id' => $id]);
        $row = $target->fetch();

        if ($row && $row['is_admin'] && $row['is_active']) {
            $count = $pdo->query(
                "SELECT COUNT(*) AS c FROM users WHERE is_admin = 1 AND is_active = 1"
            )->fetch()['c'];
            if ($count <= 1) {
                http_response_code(409);
                $response['error'] = 'Cannot delete the last remaining admin.';
                return;
            }
        }

        // user_tokens/user_locations/user_sections rows for this user are
        // removed automatically via their ON DELETE CASCADE foreign keys.
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = :id");
        $stmt->execute(['id' => $id]);

        $response['success'] = true;
        $response['data'] = ['rowsAffected' => $stmt->rowCount()];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function resendVerificationForUser() {
    global $response, $currentUser, $isMasterAdmin;

    $id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        $response['error'] = 'Missing or invalid user_id.';
        return;
    }

    try {
        $pdo = getPDO();

        if (!$isMasterAdmin && !canManageUserAccount($pdo, $currentUser, $id)) {
            http_response_code(403);
            $response['error'] = 'You can only manage users assigned to a location you administer.';
            return;
        }

        $stmt = $pdo->prepare("SELECT username, email, email_verified FROM users WHERE user_id = :id");
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            $response['error'] = 'User not found.';
            return;
        }
        if ($user['email_verified']) {
            http_response_code(400);
            $response['error'] = 'That user is already verified.';
            return;
        }

        sendVerificationEmail($pdo, $id, $user['email'], $user['username']);
        $response['success'] = true;
        $response['data'] = ['message' => 'Verification email sent.'];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}
