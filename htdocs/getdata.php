<?php
/**
 * Guest data endpoint: dashboard reads (getdata/getupdateddata), the
 * confirm-change action, and (new) the guest facility lookup/reassignment
 * used from the profile page.
 *
 * NOTE ON GET vs POST: reassignguest and confirmchange both mutate data but
 * are triggered via GET, matching the convention the original getdata.php
 * already used for confirmchange before any of this was added. That's not
 * how you'd design this from scratch (mutations really should be POST --
 * GET requests can end up in browser history, proxy/server access logs,
 * and are technically "safe/cacheable" by HTTP semantics, none of which is
 * true here), but changing it now would mean updating every place in
 * script.js/facility.js that calls these. TODO: if you're open to touching
 * those call sites, switching confirmchange/reassignguest to POST (with a
 * CSRF token -- see the note in locations.php) would be the more correct
 * long-term setup.
 */
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/location_helpers.php';
$currentUser = requireLogin();

// Set headers for JSON output and security
header('Content-Type: application/json; charset=utf-8');
// NOTE: 'Access-Control-Allow-Origin: *' + session cookies don't mix safely
// (browsers won't send credentials to a '*' origin anyway, and if you ever
// change that you'd be exposing this data to any site). Since this is now
// same-origin, session-gated data, the wildcard CORS header has been removed.
// If you need cross-origin access later, set an explicit allowed origin.

// Initialize response array
$response = [
    'success' => false,
    'data' => null,
    'error' => null
];

// Verify and sanitize GET inputs
$mode = filter_input(INPUT_GET, 'mode', FILTER_SANITIZE_SPECIAL_CHARS);

// Validate that required parameters exist
if (!$mode) {
    http_response_code(400);
    $response['error'] = 'Missing required GET parameters.';
    echo json_encode($response);
    exit;
}

// Compute data depending on the string received
switch ($mode) {
    case 'getdata':
        getData();
        break;

    case 'getupdateddata':
        getUpdatedData();
        break;

    case 'confirmchange':
        confirmChange();
        break;

    case 'getguestfacility':
        getGuestFacility();
        break;

    case 'reassignguest':
        reassignGuest();
        break;

    case 'updateguestinfo':
        updateGuestInfo();
        break;

    default:
        http_response_code(422);
        $response['error'] = "Invalid action.";
        break;
}

/**
 * Builds "AND guest_section IN (...)" (plus matching bind params) for the
 * current user, or an empty clause/params for a master admin who should see
 * everything. Centralized here since getData/getUpdatedData/confirmChange
 * all need the same restriction.
 */
// Shared by getData()/getUpdatedData() so both apply the exact same
// section-based restriction. See getAccessibleSectionIds() in
// location_helpers.php for what counts as "accessible" (master admin =
// everything; otherwise union of directly-assigned sections and every
// section under an assigned location).
function accessFilterClause() {
    global $currentUser;
    $pdo = getPDO();
    $sectionIds = getAccessibleSectionIds($pdo, $currentUser);

    if ($sectionIds === null) {
        return ['', []]; // master admin: no filter
    }
    if (empty($sectionIds)) {
        // Not assigned to anything yet -- see nothing, rather than
        // silently falling through to "no filter".
        return [' AND 1=0', []];
    }
    $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
    return [" AND guest_section IN ($placeholders)", $sectionIds];
}

function getData() {
    global $response;

    try {
        $pdo = getPDO();
        [$clause, $params] = accessFilterClause();
        // Joined in so the dashboard can show real names instead of raw
        // section_id/room_id numbers (see script.js's Guest class / the
        // "TODO" that used to sit on index.html's filter dropdown).
        $stmt = $pdo->prepare(
            "SELECT g.*, sn.section_name, rn.room_name, l.location_id, l.location_name
             FROM guests g
             JOIN section_names sn ON sn.section_id = g.guest_section
             JOIN room_names rn ON rn.room_id = g.guest_room
             JOIN locations l ON l.location_id = sn.location_id
             WHERE 1=1" . $clause
        );
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        $response['success'] = true;
        $response['data'] = [
            'result' => $data,
            'length' => count($data)
        ];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = "Database error occurred: " . $e->getMessage();
    }
}

function getUpdatedData() {
    global $response;

    try {
        $pdo = getPDO();

        $now = time();
        $last_seen_timestamp = filter_input(INPUT_GET, 'last_seen', FILTER_SANITIZE_SPECIAL_CHARS);
        $last_seen_timestamp = isset($_GET['last_seen']) ? date('Y-m-d H:i:s', $last_seen_timestamp) : date('Y-m-d H:i:s', $now);

        [$clause, $params] = accessFilterClause();
        // Same JOINs as getData() -- see the comment there. guest_updated_at
        // is qualified as g.guest_updated_at now that this is a multi-table
        // query, since an unqualified column name would be ambiguous if any
        // joined table happened to have a same-named column.
        $stmt = $pdo->prepare(
            "SELECT g.*, sn.section_name, rn.room_name, l.location_id, l.location_name
             FROM guests g
             JOIN section_names sn ON sn.section_id = g.guest_section
             JOIN room_names rn ON rn.room_id = g.guest_room
             JOIN locations l ON l.location_id = sn.location_id
             WHERE g.guest_updated_at > ?" . $clause . " ORDER BY g.guest_updated_at ASC"
        );
        $stmt->execute(array_merge([$last_seen_timestamp], $params));
        $data = $stmt->fetchAll();

        $response['success'] = true;
        $response['data'] = [
            'result' => ["time" => $now, "data" => $data],
            'length' => count($data)
        ];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = "Database error occurred: " . $e->getMessage();
    }
}

function confirmChange() {
    global $response, $currentUser;

    try {
        $pdo = getPDO();

        $id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_SPECIAL_CHARS);
        if ($id === null || $id === false || !ctype_digit((string)$id)) {
            http_response_code(400);
            $response['error'] = 'Missing required parameter.';
            echo json_encode($response);
            exit;
        }

        $guestStmt = $pdo->prepare("SELECT guest_section FROM guests WHERE guest_id = :id");
        $guestStmt->execute(['id' => $id]);
        $sectionId = $guestStmt->fetchColumn();
        if ($sectionId === false) {
            http_response_code(404);
            $response['error'] = 'Guest not found.';
            return;
        }
        if (!canViewSection($pdo, $currentUser, (int)$sectionId)) {
            http_response_code(403);
            $response['error'] = 'You do not have access to this guest.';
            return;
        }

        $sql = "UPDATE guests SET guest_time = 0, guest_updated_at = :now WHERE guest_id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':now' => date('Y-m-d H:i:s', time()),
            ':id'  => $id
        ]);

        $rowCount = $stmt->rowCount();

        $response['success'] = true;
        $response['data'] = [
            'result' => "Database updated successfully. Rows affected: $rowCount",
            'length' => $rowCount
        ];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = "Database error occurred: " . $e->getMessage();
    }
}

/**
 * For the profile page: where is this guest, and what can the current user
 * reassign them to? Non-master admins who are a location admin of the
 * guest's current location can move them between sections/rooms within
 * that same location; master admins can move them anywhere.
 */
function getGuestFacility() {
    global $response, $currentUser;

    $guestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$guestId) {
        http_response_code(400);
        $response['error'] = 'Missing or invalid id.';
        return;
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare(
            "SELECT g.guest_id, g.guest_name, g.dob, g.profile_picture, g.needs_reassignment,
                    g.guest_section AS section_id, sn.section_name, sn.location_id, l.location_name,
                    g.guest_room AS room_id, rn.room_name
             FROM guests g
             JOIN section_names sn ON sn.section_id = g.guest_section
             JOIN locations l ON l.location_id = sn.location_id
             JOIN room_names rn ON rn.room_id = g.guest_room
             WHERE g.guest_id = :id"
        );
        $stmt->execute(['id' => $guestId]);
        $guest = $stmt->fetch();

        if (!$guest) {
            http_response_code(404);
            $response['error'] = 'Guest not found.';
            return;
        }
        if (!canViewSection($pdo, $currentUser, (int)$guest['section_id'])) {
            http_response_code(403);
            $response['error'] = 'You do not have access to this guest.';
            return;
        }

        $locationId = (int)$guest['location_id'];
        $canChangeLocation = (bool)$currentUser['is_admin'];
        // Same permission covers both reassignment AND editing the guest's
        // own info (name/dob/picture) -- a location admin of this guest's
        // location, or a master admin, either way "can manage this guest".
        $canManageGuest = $canChangeLocation || isLocationAdmin($pdo, $currentUser['user_id'], $locationId);

        $data = [
            'guest_id' => (int)$guest['guest_id'],
            'guest_name' => $guest['guest_name'],
            'dob' => $guest['dob'], // 'YYYY-MM-DD' or null, straight from MySQL's DATE type -- matches <input type="date">
            'profile_picture' => $guest['profile_picture'], // filename only; fetch via guest_picture.php?action=view&id=...
            'needs_reassignment' => (bool)$guest['needs_reassignment'],
            'location_id' => $locationId,
            'location_name' => $guest['location_name'],
            'section_id' => (int)$guest['section_id'],
            'section_name' => $guest['section_name'],
            'room_id' => (int)$guest['room_id'],
            'room_name' => $guest['room_name'],
            'can_reassign' => $canManageGuest,
            'can_edit_info' => $canManageGuest,
            'can_change_location' => $canChangeLocation,
            'locations' => [],
        ];

        if ($canManageGuest) {
            $data['locations'] = $canChangeLocation
                ? buildLocationTree($pdo, $currentUser)
                : buildLocationTree($pdo, $currentUser, $locationId);
        }

        $response['success'] = true;
        $response['data'] = $data;
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

/** Location admin (of this guest's location) or master admin can edit name/dob. Picture upload is handled separately by guest_picture.php. */
function updateGuestInfo() {
    global $response, $currentUser;

    $guestId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$guestId) {
        http_response_code(400);
        $response['error'] = 'Missing or invalid id.';
        return;
    }

    $name = trim($_POST['name'] ?? '');
    $dob = trim($_POST['dob'] ?? ''); // expects 'YYYY-MM-DD' from <input type="date">, or '' to clear it

    if ($name === '') {
        http_response_code(400);
        $response['error'] = 'Name is required.';
        return;
    }
    if ($dob !== '' && !DateTime::createFromFormat('Y-m-d', $dob)) {
        http_response_code(400);
        $response['error'] = 'Date of birth must be in YYYY-MM-DD format.';
        return;
    }

    try {
        $pdo = getPDO();

        $guestStmt = $pdo->prepare("SELECT guest_section FROM guests WHERE guest_id = :id");
        $guestStmt->execute(['id' => $guestId]);
        $sectionId = $guestStmt->fetchColumn();
        if ($sectionId === false) {
            http_response_code(404);
            $response['error'] = 'Guest not found.';
            return;
        }
        if (!canManageSection($pdo, $currentUser, (int)$sectionId)) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this guest\'s location.';
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE guests SET guest_name = :name, dob = :dob, guest_updated_at = NOW() WHERE guest_id = :id"
        );
        $stmt->execute(['name' => $name, 'dob' => $dob === '' ? null : $dob, 'id' => $guestId]);

        $response['success'] = true;
        $response['data'] = ['rowsAffected' => $stmt->rowCount()];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function reassignGuest() {
    global $response, $currentUser;

    $guestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $sectionId = filter_input(INPUT_GET, 'section_id', FILTER_VALIDATE_INT);
    $roomId = filter_input(INPUT_GET, 'room_id', FILTER_VALIDATE_INT);

    if (!$guestId || !$sectionId || !$roomId) {
        http_response_code(400);
        $response['error'] = 'id, section_id, and room_id are required.';
        return;
    }

    try {
        $pdo = getPDO();

        $currentStmt = $pdo->prepare(
            "SELECT sn.location_id FROM guests g JOIN section_names sn ON sn.section_id = g.guest_section WHERE g.guest_id = :id"
        );
        $currentStmt->execute(['id' => $guestId]);
        $currentLocationId = $currentStmt->fetchColumn();
        if ($currentLocationId === false) {
            http_response_code(404);
            $response['error'] = 'Guest not found.';
            return;
        }
        $currentLocationId = (int)$currentLocationId;

        $targetLocationId = getSectionLocationId($pdo, $sectionId);
        if ($targetLocationId === null) {
            http_response_code(400);
            $response['error'] = 'That section does not exist.';
            return;
        }

        // Room must actually belong to the target section.
        $roomCheck = $pdo->prepare("SELECT 1 FROM room_names WHERE room_id = :rid AND section_id = :sid");
        $roomCheck->execute(['rid' => $roomId, 'sid' => $sectionId]);
        if (!$roomCheck->fetchColumn()) {
            http_response_code(400);
            $response['error'] = 'That room does not belong to the selected section.';
            return;
        }

        // Non-master admins: must be a location admin of the guest's
        // CURRENT location (not the target one -- you don't get to move a
        // guest into a location you administer if you don't also
        // administer wherever they currently are), and the target section
        // must be in that SAME location (see the check just below this).
        $isMaster = (bool)$currentUser['is_admin'];
        if (!$isMaster) {
            if (!isLocationAdmin($pdo, $currentUser['user_id'], $currentLocationId)) {
                http_response_code(403);
                $response['error'] = 'You are not an admin of this guest\'s location.';
                return;
            }
            if ($targetLocationId !== $currentLocationId) {
                http_response_code(403);
                $response['error'] = 'Only a master admin can move a guest to a different location.';
                return;
            }
        }

        $stmt = $pdo->prepare(
            "UPDATE guests SET guest_section = :sec, guest_room = :room, needs_reassignment = 0, guest_updated_at = NOW() WHERE guest_id = :id"
        );
        $stmt->execute(['sec' => $sectionId, 'room' => $roomId, 'id' => $guestId]);

        $response['success'] = true;
        $response['data'] = ['rowsAffected' => $stmt->rowCount()];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

echo json_encode($response);
