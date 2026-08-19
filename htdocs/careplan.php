<?php
/**
 * Per-guest care plan: viewable by anyone who can view the guest at all
 * (same canViewSection() rule as everywhere else -- i.e. anyone assigned
 * to that guest's location or section), editable only by a location admin
 * of the guest's location or a master admin (same canManageSection() rule
 * used for reassignment and info editing).
 *
 * TODO: this only stores the CURRENT plan text, not a history of previous
 * versions/who-changed-what-when beyond the single updated_by/updated_at
 * on the latest save. If you need an audit trail (e.g. "what did the plan
 * say last month"), you'd want a separate guest_care_plan_history table
 * that gets an INSERT alongside every UPDATE here, rather than overwriting
 * in place.
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

switch ($action) {
    case 'get':
        getCarePlan();
        break;
    case 'save':
        saveCarePlan();
        break;
    default:
        http_response_code(400);
        $response['error'] = 'Invalid action.';
        break;
}

echo json_encode($response);
exit;

function getCarePlan() {
    global $response, $currentUser;

    $guestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$guestId) {
        http_response_code(400);
        $response['error'] = 'Missing or invalid id.';
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
        if (!canViewSection($pdo, $currentUser, (int)$sectionId)) {
            http_response_code(403);
            $response['error'] = 'You do not have access to this guest.';
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT gcp.plan_text, gcp.updated_at, u.username AS updated_by_username
             FROM guest_care_plans gcp
             LEFT JOIN users u ON u.user_id = gcp.updated_by
             WHERE gcp.guest_id = :id"
        );
        $stmt->execute(['id' => $guestId]);
        $plan = $stmt->fetch();

        $response['success'] = true;
        $response['data'] = [
            'plan_text' => $plan ? $plan['plan_text'] : '',
            'updated_at' => $plan ? $plan['updated_at'] : null,
            'updated_by' => $plan ? $plan['updated_by_username'] : null,
            'can_edit' => canManageSection($pdo, $currentUser, (int)$sectionId),
        ];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function saveCarePlan() {
    global $response, $currentUser;

    $guestId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $planText = $_POST['plan_text'] ?? '';
    if (!$guestId) {
        http_response_code(400);
        $response['error'] = 'Missing or invalid id.';
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
            "INSERT INTO guest_care_plans (guest_id, plan_text, updated_by, updated_at)
             VALUES (:gid, :text, :uid, NOW())
             ON DUPLICATE KEY UPDATE plan_text = :text2, updated_by = :uid2, updated_at = NOW()"
        );
        $stmt->execute([
            'gid' => $guestId,
            'text' => $planText,
            'uid' => $currentUser['user_id'],
            'text2' => $planText,
            'uid2' => $currentUser['user_id'],
        ]);

        $response['success'] = true;
        $response['data'] = ['message' => 'Care plan saved.'];
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}
