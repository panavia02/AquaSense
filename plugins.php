<?php
/**
 * Plugin discovery + enable/disable toggle.
 *
 * IMPORTANT SCOPE NOTE: this file only tracks WHICH plugin folders exist and
 * whether each is flagged enabled/disabled in the database. It does not
 * load, execute, or otherwise wire plugin code into the rest of the app --
 * there's no plugin execution framework here (hooks, autoloading, an
 * activation lifecycle, etc.). TODO: if/when you have real plugins that
 * need to actually do something, you'll need to build that part; this just
 * gives you the on/off switch and the discovery mechanism to build on top of.
 *
 * TODO: plugin.json is read with json_decode() and trusted for its `name`/
 * `description` strings, which get echoed back as JSON (safe) and then
 * rendered via plugins.js -- check plugins.js if you ever render this HTML
 * differently, since right now it goes through escapeHtml() there, but any
 * future change that skips that escaping would let a plugin.json author
 * inject HTML into the admin's browser. Only matters if you'd ever install
 * a plugin folder from an untrusted source, which presumably you wouldn't.
 */
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/session_bootstrap.php';
requireAdmin(); // plugin management is master-admin only, site-wide config

header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'data' => null, 'error' => null];

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'POST' ? ($_POST['action'] ?? null) : ($_GET['action'] ?? null);

const PLUGINS_DIR = __DIR__ . '/plugins';
const MAIN_SITE_SLUG = 'main-website';

switch ($action) {
    case 'list':
        listPlugins();
        break;
    case 'toggle':
        togglePlugin();
        break;
    default:
        http_response_code(400);
        $response['error'] = 'Invalid action.';
        break;
}

echo json_encode($response);
exit;

/** Slug = folder name, lightly sanitized for use as a DB key / identifier. */
function slugFromFolder(string $folder): string {
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($folder));
}

function loadEnabledMap(PDO $pdo): array {
    $map = [];
    $stmt = $pdo->query("SELECT plugin_slug, is_enabled FROM plugin_state");
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['plugin_slug']] = (bool)$row['is_enabled'];
    }
    return $map;
}

// Always includes the locked "Main Website" entry first, then one entry
// per subfolder of plugins/ that has read access -- see slugFromFolder()
// for how folder names become the identifier stored in plugin_state.
function listPlugins() {
    global $response;

    try {
        $pdo = getPDO();
        $enabledMap = loadEnabledMap($pdo);

        $plugins = [
            [
                'slug' => MAIN_SITE_SLUG,
                'name' => 'Main Website',
                'description' => 'The core quaSense application. Always enabled.',
                'enabled' => true,
                'locked' => true,
            ],
        ];

        if (is_dir(PLUGINS_DIR)) {
            $entries = scandir(PLUGINS_DIR);
            sort($entries);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                $fullPath = PLUGINS_DIR . '/' . $entry;
                if (!is_dir($fullPath)) continue;

                $slug = slugFromFolder($entry);
                $name = $entry;
                $description = '';

                $manifestPath = $fullPath . '/plugin.json';
                if (is_file($manifestPath)) {
                    $manifest = json_decode(file_get_contents($manifestPath), true);
                    if (is_array($manifest)) {
                        $name = $manifest['name'] ?? $name;
                        $description = $manifest['description'] ?? '';
                    }
                }

                $plugins[] = [
                    'slug' => $slug,
                    'name' => $name,
                    'description' => $description,
                    // A plugin folder with no row yet is treated as enabled by default.
                    'enabled' => $enabledMap[$slug] ?? true,
                    'locked' => false,
                ];
            }
        }

        $response['success'] = true;
        $response['data'] = $plugins;
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}

function togglePlugin() {
    global $response;

    $slug = trim($_POST['slug'] ?? '');
    $enabled = !empty($_POST['enabled']) ? 1 : 0;

    if ($slug === '') {
        http_response_code(400);
        $response['error'] = 'slug is required.';
        return;
    }
    if ($slug === MAIN_SITE_SLUG) {
        http_response_code(400);
        $response['error'] = 'The main website cannot be disabled.';
        return;
    }

    // Make sure this slug actually corresponds to a real plugin folder,
    // rather than trusting an arbitrary string from the client.
    $found = false;
    if (is_dir(PLUGINS_DIR)) {
        foreach (scandir(PLUGINS_DIR) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_dir(PLUGINS_DIR . '/' . $entry) && slugFromFolder($entry) === $slug) {
                $found = true;
                break;
            }
        }
    }
    if (!$found) {
        http_response_code(404);
        $response['error'] = 'No plugin folder matches that slug.';
        return;
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare(
            "INSERT INTO plugin_state (plugin_slug, is_enabled) VALUES (:slug, :enabled)
             ON DUPLICATE KEY UPDATE is_enabled = :enabled2"
        );
        $stmt->execute(['slug' => $slug, 'enabled' => $enabled, 'enabled2' => $enabled]);
        $response['success'] = true;
    } catch (PDOException $e) {
        http_response_code(500);
        $response['error'] = 'Database error occurred: ' . $e->getMessage();
    }
}
