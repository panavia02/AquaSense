<?php
/**
 * API for the locations -> sections -> rooms hierarchy: browsing (list),
 * master-admin-only structural changes (create/update/delete location),
 * location-admin-or-master structural changes (sections/rooms), and
 * user-assignment management (who can see/admin which location or section).
 *
 * Every write action re-checks permissions itself via location_helpers.php
 * -- it does NOT trust anything the client claims about its own role, so
 * it's safe even though facility.js also hides buttons the user "shouldn't"
 * see (that's just UI convenience, not the actual security boundary).
 *
 * TODO: none of the state-changing actions here (or elsewhere in this app --
 * auth.php, admin_users.php, plugins.php, getdata.php) use a CSRF token.
 * Session cookies are same-site by default in most modern browsers, and the
 * "remember me" cookie explicitly sets SameSite=Lax (see auth_helpers.php),
 * which blocks the classic "malicious page auto-submits a form" attack in
 * most cases -- but PHP's own session cookie (set by session_start()) uses
 * whatever your php.ini/session.cookie_samesite is configured to, which may
 * not be set at all on some hosts. If you want to be thorough, add a CSRF
 * token that's issued alongside the session and required on every POST here.
 *
 * TODO: buildLocationTree() in location_helpers.php runs one query per
 * section (for its rooms) and one per location (for its admins). Fine at
 * the scale this app currently has, but if you end up with dozens of
 * locations/hundreds of sections, that N+1 pattern is worth collapsing into
 * a couple of JOINed queries instead.
 */

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/location_helpers.php';

$currentUser = requireLogin();

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'data' => null, 'error' => null];

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'POST' ? ($_POST['action'] ?? null) : ($_GET['action'] ?? null);

// Route to the handler for each action. Every handler below re-validates
// permissions for the specific location/section/room it's touching --
// reaching this switch only proves the user is logged in (see requireLogin()
// above), not that they're allowed to do whatever they're asking for.
switch ($action) {
    case 'list':
        listLocations();
        break;
    case 'create_location':
        createLocation();
        break;
    case 'update_location':
        updateLocation();
        break;
    case 'delete_location':
        deleteLocation();
        break;
    case 'create_section':
        createSection();
        break;
    case 'update_section':
        updateSection();
        break;
    case 'delete_section':
        deleteSection();
        break;
    case 'create_room':
        createRoom();
        break;
    case 'update_room':
        updateRoom();
        break;
    case 'delete_room':
        deleteRoom();
        break;
    case 'list_unassigned_guests':
        listUnassignedGuests();
        break;
    case 'list_location_users':
        listLocationUsers();
        break;
    case 'search_users':
        searchUsers();
        break;
    case 'assign_location':
        assignLocation();
        break;
    case 'remove_location':
        removeLocation();
        break;
    case 'assign_section':
        assignSection();
        break;
    case 'remove_section':
        removeSection();
        break;
    default:
        http_response_code(400);
        $response['error'] = 'Invalid action.';
        break;
}

echo json_encode($response);
exit;

// Returns every location this user can see, each already trimmed down to
// just the sections (and rooms) they have access to -- see
// buildLocationTree() in location_helpers.php for the actual visibility logic.
function listLocations() {
    global $response, $currentUser;
    try {
        $pdo = getPDO();
        $response['success'] = true;
        $response['data'] = [
            'is_master_admin' => (bool)$currentUser['is_admin'],
            'locations' => buildLocationTree($pdo, $currentUser),
        ];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

// Master-admin only, per the spec this was built against: location
// admins can manage a location's contents and users, but not create,
// rename, or delete locations themselves.
function createLocation() {
    global $response, $currentUser;
    if (!$currentUser['is_admin']) {
        http_response_code(403);
        $response['error'] = 'Only a master admin can create locations.';
        return;
    }
    $name = trim($_POST['location_name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        $response['error'] = 'Location name is required.';
        return;
    }
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("INSERT INTO locations (location_name) VALUES (:name)");
        $stmt->execute(['name' => $name]);
        $response['success'] = true;
        $response['data'] = ['location_id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function updateLocation() {
    global $response, $currentUser;
    if (!$currentUser['is_admin']) {
        http_response_code(403);
        $response['error'] = 'Only a master admin can rename locations.';
        return;
    }
    $id = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
    $name = trim($_POST['location_name'] ?? '');
    if (!$id || $name === '') {
        http_response_code(400);
        $response['error'] = 'location_id and location_name are required.';
        return;
    }
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("UPDATE locations SET location_name = :name WHERE location_id = :id");
        $stmt->execute(['name' => $name, 'id' => $id]);
        $response['success'] = true;
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function deleteLocation() {
    global $response, $currentUser;
    if (!$currentUser['is_admin']) {
        http_response_code(403);
        $response['error'] = 'Only a master admin can delete locations.';
        return;
    }
    $id = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        $response['error'] = 'location_id is required.';
        return;
    }
    try {
        $pdo = getPDO();

        $unassigned = getOrCreateUnassignedRoom($pdo);
        if ($id === $unassigned['location_id']) {
            http_response_code(400);
            $response['error'] = 'The Unassigned location is reserved and can\'t be deleted.';
            return;
        }

        // Guests in any section of this location get moved to the
        // Unassigned bucket (flagged needs_reassignment) BEFORE the
        // location itself is deleted, so they're preserved rather than
        // cascade-deleted along with their section/room. Both steps happen
        // in one transaction so a failure partway through can't leave
        // guests silently reassigned without the location actually being
        // deleted (or vice versa).
        $pdo->beginTransaction();

        $sectionStmt = $pdo->prepare("SELECT section_id FROM section_names WHERE location_id = :lid");
        $sectionStmt->execute(['lid' => $id]);
        $sectionIds = array_map('intval', $sectionStmt->fetchAll(PDO::FETCH_COLUMN));
        $movedCount = reassignGuestsToUnassigned($pdo, $sectionIds);

        $stmt = $pdo->prepare("DELETE FROM locations WHERE location_id = :id");
        $stmt->execute(['id' => $id]);
        $rowsAffected = $stmt->rowCount();

        $pdo->commit();

        $response['success'] = true;
        $response['data'] = ['rowsAffected' => $rowsAffected, 'guestsMovedToUnassigned' => $movedCount];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

// Master admin OR location admin of the target location.
function createSection() {
    global $response, $currentUser;
    $locationId = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
    $name = trim($_POST['section_name'] ?? '');
    if (!$locationId || $name === '') {
        http_response_code(400);
        $response['error'] = 'location_id and section_name are required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageLocation($pdo, $currentUser, $locationId)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this location.';
            return;
        }
        $stmt = $pdo->prepare("INSERT INTO section_names (location_id, section_name) VALUES (:lid, :name)");
        $stmt->execute(['lid' => $locationId, 'name' => $name]);
        $response['success'] = true;
        $response['data'] = ['section_id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function updateSection() {
    global $response, $currentUser;
    $id = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
    $name = trim($_POST['section_name'] ?? '');
    if (!$id || $name === '') {
        http_response_code(400);
        $response['error'] = 'section_id and section_name are required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageSection($pdo, $currentUser, $id)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this section\'s location.';
            return;
        }
        $stmt = $pdo->prepare("UPDATE section_names SET section_name = :name WHERE section_id = :id");
        $stmt->execute(['name' => $name, 'id' => $id]);
        $response['success'] = true;
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function deleteSection() {
    global $response, $currentUser;
    $id = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        $response['error'] = 'section_id is required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageSection($pdo, $currentUser, $id)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this section\'s location.';
            return;
        }

        $unassigned = getOrCreateUnassignedRoom($pdo);
        if ($id === $unassigned['section_id']) {
            http_response_code(400);
            $response['error'] = 'The Unassigned section is reserved and can\'t be deleted.';
            return;
        }

        // Same guest-preservation approach as deleteLocation() -- see the
        // comment there.
        $pdo->beginTransaction();
        $movedCount = reassignGuestsToUnassigned($pdo, [$id]);

        $stmt = $pdo->prepare("DELETE FROM section_names WHERE section_id = :id");
        $stmt->execute(['id' => $id]);
        $rowsAffected = $stmt->rowCount();

        $pdo->commit();

        $response['success'] = true;
        $response['data'] = ['rowsAffected' => $rowsAffected, 'guestsMovedToUnassigned' => $movedCount];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

// Master admin OR location admin of the section's location.
function createRoom() {
    global $response, $currentUser;
    $sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
    $name = trim($_POST['room_name'] ?? '');
    if (!$sectionId || $name === '') {
        http_response_code(400);
        $response['error'] = 'section_id and room_name are required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageSection($pdo, $currentUser, $sectionId)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this section\'s location.';
            return;
        }
        $stmt = $pdo->prepare("INSERT INTO room_names (section_id, room_name) VALUES (:sid, :name)");
        $stmt->execute(['sid' => $sectionId, 'name' => $name]);
        $response['success'] = true;
        $response['data'] = ['room_id' => $pdo->lastInsertId()];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function updateRoom() {
    global $response, $currentUser;
    $id = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);
    $name = trim($_POST['room_name'] ?? '');
    if (!$id || $name === '') {
        http_response_code(400);
        $response['error'] = 'room_id and room_name are required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageRoom($pdo, $currentUser, $id)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this room\'s location.';
            return;
        }
        $stmt = $pdo->prepare("UPDATE room_names SET room_name = :name WHERE room_id = :id");
        $stmt->execute(['name' => $name, 'id' => $id]);
        $response['success'] = true;
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function deleteRoom() {
    global $response, $currentUser;
    $id = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        $response['error'] = 'room_id is required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageRoom($pdo, $currentUser, $id)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this room\'s location.';
            return;
        }

        $unassigned = getOrCreateUnassignedRoom($pdo);
        if ($id === $unassigned['room_id']) {
            http_response_code(400);
            $response['error'] = 'The Unassigned room is reserved and can\'t be deleted.';
            return;
        }

        // A guest is tied to a specific (section, room) pair, not just a
        // room, so "delete this room" moves its guests to the Unassigned
        // bucket the same as deleting the whole section would -- there's
        // no sensible "keep them in this section but no room" state given
        // guest_room is NOT NULL. See deleteLocation()'s comment for why
        // this happens in a transaction.
        $pdo->beginTransaction();

        $guestsInRoom = $pdo->prepare("SELECT DISTINCT guest_section FROM guests WHERE guest_room = :rid");
        $guestsInRoom->execute(['rid' => $id]);
        $affectedSectionIds = array_map('intval', $guestsInRoom->fetchAll(PDO::FETCH_COLUMN));
        // reassignGuestsToUnassigned() moves by section, so build a
        // section-scoped move that only touches guests actually in THIS
        // room (not everyone else in the same section).
        $movedCount = 0;
        if (!empty($affectedSectionIds)) {
            $stmt = $pdo->prepare(
                "UPDATE guests SET guest_section = :usec, guest_room = :uroom, needs_reassignment = 1, guest_updated_at = NOW()
                 WHERE guest_room = :rid"
            );
            $stmt->execute(['usec' => $unassigned['section_id'], 'uroom' => $unassigned['room_id'], 'rid' => $id]);
            $movedCount = $stmt->rowCount();
        }

        $stmt = $pdo->prepare("DELETE FROM room_names WHERE room_id = :id");
        $stmt->execute(['id' => $id]);
        $rowsAffected = $stmt->rowCount();

        $pdo->commit();

        $response['success'] = true;
        $response['data'] = ['rowsAffected' => $rowsAffected, 'guestsMovedToUnassigned' => $movedCount];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

/** Master-admin-only: guests currently parked in the Unassigned bucket, waiting to be properly reassigned. */
function listUnassignedGuests() {
    global $response, $currentUser;
    if (!$currentUser['is_admin']) {
        http_response_code(403);
        $response['error'] = 'Only a master admin can view unassigned guests.';
        return;
    }
    try {
        $pdo = getPDO();
        $stmt = $pdo->query("SELECT guest_id, guest_name FROM guests WHERE needs_reassignment = 1 ORDER BY guest_updated_at DESC");
        $response['success'] = true;
        $response['data'] = $stmt->fetchAll();
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

/**
 * Users with any assignment (location- or section-level) inside this
 * location, for a location admin to manage. Deliberately scoped to just
 * this location -- per the spec, location admins can only view/manage
 * users assigned to locations they administer, not the full user list
 * (that's admin_users.php, master-admin only).
 */
function listLocationUsers() {
    global $response, $currentUser;
    $locationId = filter_input(INPUT_GET, 'location_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
    if (!$locationId) {
        http_response_code(400);
        $response['error'] = 'location_id is required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageLocation($pdo, $currentUser, $locationId)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this location.';
            return;
        }

        $locStmt = $pdo->prepare(
            "SELECT u.user_id, u.username, u.email, ul.is_location_admin
             FROM user_locations ul JOIN users u ON u.user_id = ul.user_id
             WHERE ul.location_id = :lid ORDER BY u.username ASC"
        );
        $locStmt->execute(['lid' => $locationId]);
        $locationLevel = $locStmt->fetchAll();

        $secStmt = $pdo->prepare(
            "SELECT u.user_id, u.username, u.email, us.section_id, sn.section_name
             FROM user_sections us
             JOIN users u ON u.user_id = us.user_id
             JOIN section_names sn ON sn.section_id = us.section_id
             WHERE sn.location_id = :lid ORDER BY u.username ASC"
        );
        $secStmt->execute(['lid' => $locationId]);
        $sectionLevel = $secStmt->fetchAll();

        $response['success'] = true;
        $response['data'] = [
            'location_admins_and_members' => $locationLevel,
            'section_only_members' => $sectionLevel,
        ];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

/**
 * Lightweight user search for the "assign a user" picker. Any location
 * admin (of ANY location, not just the one they're currently managing) or
 * master admin can use it -- it only exposes username/email, not
 * anything sensitive, and a location admin needs to be able to find
 * users who aren't assigned to their location yet in order to assign
 * them. TODO: if you have a large user base, consider paginating this
 * (it currently hard-caps at 50 results with no paging).
 */
function searchUsers() {
    global $response, $currentUser;
    try {
        $pdo = getPDO();

        if (!$currentUser['is_admin']) {
            $stmt = $pdo->prepare("SELECT 1 FROM user_locations WHERE user_id = :uid AND is_location_admin = 1 LIMIT 1");
            $stmt->execute(['uid' => $currentUser['user_id']]);
            if (!$stmt->fetchColumn()) {
                http_response_code(403);
                $response['error'] = 'You are not an admin of any location.';
                return;
            }
        }

        $query = trim($_GET['query'] ?? $_POST['query'] ?? '');
        if ($query !== '') {
            $stmt = $pdo->prepare(
                "SELECT user_id, username, email FROM users WHERE is_active = 1 AND username LIKE :q ORDER BY username ASC LIMIT 50"
            );
            $stmt->execute(['q' => '%' . $query . '%']);
        } else {
            $stmt = $pdo->query("SELECT user_id, username, email FROM users WHERE is_active = 1 ORDER BY username ASC LIMIT 50");
        }

        $response['success'] = true;
        $response['data'] = $stmt->fetchAll();
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

// Upserts a user's location assignment (and admin flag for that
// location). Note this can only ever set is_location_admin, never
// users.is_admin (the master-admin flag) -- that's intentional and
// enforced simply by this endpoint never touching that column.
function assignLocation() {
    global $response, $currentUser;
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $locationId = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
    $isLocAdmin = !empty($_POST['is_location_admin']) ? 1 : 0;

    if (!$userId || !$locationId) {
        http_response_code(400);
        $response['error'] = 'user_id and location_id are required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageLocation($pdo, $currentUser, $locationId)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this location.';
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT INTO user_locations (user_id, location_id, is_location_admin) VALUES (:uid, :lid, :admin)
             ON DUPLICATE KEY UPDATE is_location_admin = :admin2"
        );
        $stmt->execute(['uid' => $userId, 'lid' => $locationId, 'admin' => $isLocAdmin, 'admin2' => $isLocAdmin]);
        $response['success'] = true;
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

// Removes a user's location assignment, plus any section-only
// assignments they had within that same location (so removing someone
// from a location doesn't leave them with orphaned section access).
function removeLocation() {
    global $response, $currentUser;
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $locationId = filter_input(INPUT_POST, 'location_id', FILTER_VALIDATE_INT);
    if (!$userId || !$locationId) {
        http_response_code(400);
        $response['error'] = 'user_id and location_id are required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageLocation($pdo, $currentUser, $locationId)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this location.';
            return;
        }

        // Don't allow removing the last location admin (mirrors the
        // last-master-admin guard in admin_users.php).
        $target = $pdo->prepare("SELECT is_location_admin FROM user_locations WHERE user_id = :uid AND location_id = :lid");
        $target->execute(['uid' => $userId, 'lid' => $locationId]);
        $row = $target->fetch();
        if ($row && $row['is_location_admin']) {
            $count = $pdo->prepare("SELECT COUNT(*) AS c FROM user_locations WHERE location_id = :lid AND is_location_admin = 1");
            $count->execute(['lid' => $locationId]);
            if ($count->fetch()['c'] <= 1) {
                http_response_code(409);
                $response['error'] = 'Cannot remove the last admin of this location.';
                return;
            }
        }

        $stmt = $pdo->prepare("DELETE FROM user_locations WHERE user_id = :uid AND location_id = :lid");
        $stmt->execute(['uid' => $userId, 'lid' => $locationId]);

        // Also drop any section-only assignments this user had within this
        // same location, so removing them from the location doesn't leave
        // dangling section access behind.
        $cleanup = $pdo->prepare(
            "DELETE us FROM user_sections us
             JOIN section_names sn ON sn.section_id = us.section_id
             WHERE us.user_id = :uid AND sn.location_id = :lid"
        );
        $cleanup->execute(['uid' => $userId, 'lid' => $locationId]);

        $response['success'] = true;
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function assignSection() {
    global $response, $currentUser;
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
    if (!$userId || !$sectionId) {
        http_response_code(400);
        $response['error'] = 'user_id and section_id are required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageSection($pdo, $currentUser, $sectionId)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this section\'s location.';
            return;
        }
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO user_sections (user_id, section_id) VALUES (:uid, :sid)"
        );
        $stmt->execute(['uid' => $userId, 'sid' => $sectionId]);
        $response['success'] = true;
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function removeSection() {
    global $response, $currentUser;
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $sectionId = filter_input(INPUT_POST, 'section_id', FILTER_VALIDATE_INT);
    if (!$userId || !$sectionId) {
        http_response_code(400);
        $response['error'] = 'user_id and section_id are required.';
        return;
    }
    try {
        $pdo = getPDO();
        if (!canManageSection($pdo, $currentUser, $sectionId)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this section\'s location.';
            return;
        }
        $stmt = $pdo->prepare("DELETE FROM user_sections WHERE user_id = :uid AND section_id = :sid");
        $stmt->execute(['uid' => $userId, 'sid' => $sectionId]);
        $response['success'] = true;
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}
