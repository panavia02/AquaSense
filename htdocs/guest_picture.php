<?php
/**
 * Guest profile picture upload + serving. Split out from getdata.php since
 * file uploads need multipart/form-data POST (can't share getdata.php's
 * GET-query-param style), and serving the image is a raw-bytes response,
 * not JSON like everything else in this app.
 *
 * Permission model matches everywhere else guest info is touched: viewing
 * a photo requires canViewSection() (same as seeing the guest at all);
 * uploading/replacing one requires canManageSection() (location admin of
 * the guest's location, or master admin) -- same rule used for
 * reassignment and editing name/dob in getdata.php's updateGuestInfo().
 */
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/location_helpers.php';
$currentUser = requireLogin();

const GUEST_PHOTOS_DIR = __DIR__ . '/uploads/guest-photos';
const MAX_PHOTO_BYTES = 5 * 1024 * 1024; // 5MB -- TODO: tune to taste, or make configurable
// getimagesize() reports one of the IMAGETYPE_* constants for a real image
// file regardless of what the client claims its MIME type is -- checking
// against this (rather than trusting $_FILES['photo']['type']) is what
// actually stops someone uploading a non-image file with a spoofed type.
const ALLOWED_PHOTO_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_WEBP => 'webp',
];

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'POST' ? ($_POST['action'] ?? null) : ($_GET['action'] ?? null);

// action=view streams raw image bytes, not JSON -- handle it completely
// separately, before the JSON header below gets set for every other action.
if ($action === 'view') {
    viewPhoto();
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$response = ['success' => false, 'data' => null, 'error' => null];

switch ($action) {
    case 'upload':
        uploadPhoto();
        break;
    default:
        http_response_code(400);
        $response['error'] = 'Invalid action.';
        break;
}

echo json_encode($response);
exit;

function viewPhoto() {
    global $currentUser;

    $guestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$guestId) {
        http_response_code(400);
        return;
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT guest_section, profile_picture FROM guests WHERE guest_id = :id");
        $stmt->execute(['id' => $guestId]);
        $guest = $stmt->fetch();

        if (!$guest || !canViewSection($pdo, $currentUser, (int)$guest['guest_section'])) {
            http_response_code(403);
            return;
        }
        if (empty($guest['profile_picture'])) {
            http_response_code(404);
            return;
        }

        // basename() strips any path components -- profile_picture should
        // always already be a bare filename (see uploadPhoto()), but this
        // is a cheap extra guard against ever treating it as a path.
        $path = GUEST_PHOTOS_DIR . '/' . basename($guest['profile_picture']);
        if (!is_file($path)) {
            http_response_code(404);
            return;
        }

        $imageInfo = @getimagesize($path);
        header('Content-Type: ' . ($imageInfo['mime'] ?? 'application/octet-stream'));
        header('Cache-Control: private, max-age=3600'); // "private" since this isn't public content, even though it's cacheable by the browser itself
        readfile($path);
    } catch (PDOException $e) {
        http_response_code(500);
    }
}

function uploadPhoto() {
    global $response, $currentUser;

    $guestId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$guestId) {
        http_response_code(400);
        $response['error'] = 'Missing or invalid id.';
        return;
    }

    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        $response['error'] = 'No photo uploaded, or the upload failed.';
        return;
    }
    if ($_FILES['photo']['size'] > MAX_PHOTO_BYTES) {
        http_response_code(400);
        $response['error'] = 'Photo is too large (max 5MB).';
        return;
    }

    $imageInfo = @getimagesize($_FILES['photo']['tmp_name']);
    if (!$imageInfo || !isset(ALLOWED_PHOTO_TYPES[$imageInfo[2]])) {
        http_response_code(400);
        $response['error'] = 'File must be a JPEG, PNG, or WEBP image.';
        return;
    }

    try {
        $pdo = getPDO();

        $guestStmt = $pdo->prepare("SELECT guest_section, profile_picture FROM guests WHERE guest_id = :id");
        $guestStmt->execute(['id' => $guestId]);
        $guest = $guestStmt->fetch();
        if (!$guest) {
            http_response_code(404);
            $response['error'] = 'Guest not found.';
            return;
        }
        if (!canManageSection($pdo, $currentUser, (int)$guest['guest_section'])) {
            http_response_code(403);
            $response['error'] = 'You are not an admin of this guest\'s location.';
            return;
        }

        if (!is_dir(GUEST_PHOTOS_DIR)) {
            mkdir(GUEST_PHOTOS_DIR, 0755, true);
        }

        $ext = ALLOWED_PHOTO_TYPES[$imageInfo[2]];
        // Random suffix (not just guest_id) so a stale cached/bookmarked
        // filename can't collide with a later re-upload, and so the
        // filename alone isn't a guessable enumeration of guest_ids --
        // though again, the real access control is canViewSection() in
        // viewPhoto(), not filename obscurity.
        $filename = 'guest_' . $guestId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destination = GUEST_PHOTOS_DIR . '/' . $filename;

        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $destination)) {
            http_response_code(500);
            $response['error'] = 'Failed to save the uploaded photo.';
            return;
        }

        if (!empty($guest['profile_picture'])) {
            $oldPath = GUEST_PHOTOS_DIR . '/' . basename($guest['profile_picture']);
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $update = $pdo->prepare("UPDATE guests SET profile_picture = :pic, guest_updated_at = NOW() WHERE guest_id = :id");
        $update->execute(['pic' => $filename, 'id' => $guestId]);

        $response['success'] = true;
        $response['data'] = ['profile_picture' => $filename];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}
