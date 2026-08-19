<?php
/**
 * Permission helpers for the locations/sections/rooms hierarchy.
 *
 * Two independent things determine what a user can do:
 *   - users.is_admin ("master admin"): bypasses everything below. Can
 *     view/create/edit/remove any location, section, room, or assignment,
 *     whether or not they're personally assigned to it.
 *   - user_locations.is_location_admin (per location): can edit that one
 *     location's sections/rooms and manage which users are assigned to it.
 *     Has nothing to do with users.is_admin.
 *
 * A user (master admin or not) can only ever *see* a location if they're
 * assigned to it directly (user_locations) or assigned to at least one
 * section within it (user_sections) -- except master admins, who see all
 * of them regardless.
 */

ini_set('display_errors', '0');
error_reporting(0);

/** Is this user a location admin of the given location (ignoring master-admin)? */
function isLocationAdmin(PDO $pdo, int $userId, int $locationId): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM user_locations WHERE user_id = :uid AND location_id = :lid AND is_location_admin = 1"
    );
    $stmt->execute(['uid' => $userId, 'lid' => $locationId]);
    return (bool)$stmt->fetchColumn();
}

/** Master admin OR location admin of this specific location. */
function canManageLocation(PDO $pdo, array $user, int $locationId): bool {
    return $user['is_admin'] || isLocationAdmin($pdo, $user['user_id'], $locationId);
}

/** Master admin OR location admin of the location that this section belongs to. */
function canManageSection(PDO $pdo, array $user, int $sectionId): bool {
    if ($user['is_admin']) return true;
    $locationId = getSectionLocationId($pdo, $sectionId);
    return $locationId !== null && isLocationAdmin($pdo, $user['user_id'], $locationId);
}

/** Master admin OR location admin of the location that this room's section belongs to. */
function canManageRoom(PDO $pdo, array $user, int $roomId): bool {
    if ($user['is_admin']) return true;
    $stmt = $pdo->prepare("SELECT section_id FROM room_names WHERE room_id = :id");
    $stmt->execute(['id' => $roomId]);
    $sectionId = $stmt->fetchColumn();
    return $sectionId !== false && canManageSection($pdo, $user, (int)$sectionId);
}

/**
 * Finds (or, the first time it's needed, creates) a reserved
 * "Unassigned" location -> section -> room. Used when a room, section, or
 * location gets deleted while it still has guests in it: rather than
 * losing those guest records (the DB's ON DELETE CASCADE from
 * guests.guest_section/guest_room would otherwise delete them along with
 * the section/room), locations.php moves those guests here first and
 * flags them with needs_reassignment=1 so an admin can find and properly
 * reassign them afterward (see the "needs reassignment" panel in
 * facility.js).
 *
 * Self-provisioning (rather than seeded via a migration) so this works
 * even on installs that ran schema_v4_guest_data.sql before this function
 * existed, and so it quietly recreates itself if it's ever deleted by
 * some other path -- see the delete guards in locations.php that
 * specifically prevent deleting the tuple this function returns, though,
 * since that would defeat the point.
 */
function getOrCreateUnassignedRoom(PDO $pdo): array {
    $stmt = $pdo->prepare(
        "SELECT l.location_id, sn.section_id, rn.room_id
         FROM locations l
         JOIN section_names sn ON sn.location_id = l.location_id
         JOIN room_names rn ON rn.section_id = sn.section_id
         WHERE l.location_name = 'Unassigned' AND sn.section_name = 'Unassigned' AND rn.room_name = 'Unassigned'
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) {
        return ['location_id' => (int)$row['location_id'], 'section_id' => (int)$row['section_id'], 'room_id' => (int)$row['room_id']];
    }

    $pdo->prepare("INSERT INTO locations (location_name) VALUES ('Unassigned')")->execute();
    $locationId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO section_names (location_id, section_name) VALUES (:lid, 'Unassigned')")->execute(['lid' => $locationId]);
    $sectionId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO room_names (section_id, room_name) VALUES (:sid, 'Unassigned')")->execute(['sid' => $sectionId]);
    $roomId = (int)$pdo->lastInsertId();

    return ['location_id' => $locationId, 'section_id' => $sectionId, 'room_id' => $roomId];
}

/**
 * Moves every guest currently in $sectionIds to the Unassigned bucket and
 * flags them needs_reassignment=1. Called by locations.php right before
 * deleting a room/section/location, inside the same transaction as the
 * delete, so guests end up preserved-but-unassigned rather than cascaded
 * away. Returns how many guests were moved (purely informational).
 */
function reassignGuestsToUnassigned(PDO $pdo, array $sectionIds): int {
    if (empty($sectionIds)) {
        return 0;
    }
    $unassigned = getOrCreateUnassignedRoom($pdo);
    $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
    $stmt = $pdo->prepare(
        "UPDATE guests SET guest_section = ?, guest_room = ?, needs_reassignment = 1, guest_updated_at = NOW()
         WHERE guest_section IN ($placeholders)"
    );
    $stmt->execute(array_merge([$unassigned['section_id'], $unassigned['room_id']], $sectionIds));
    return $stmt->rowCount();
}

function getSectionLocationId(PDO $pdo, int $sectionId): ?int {
    $stmt = $pdo->prepare("SELECT location_id FROM section_names WHERE section_id = :id");
    $stmt->execute(['id' => $sectionId]);
    $locationId = $stmt->fetchColumn();
    return $locationId === false ? null : (int)$locationId;
}

/** Location IDs this user has ANY assignment to (admin or not), not including master-admin's implicit access to all. */
/** Location IDs where this user is specifically a location ADMIN (not just a member/viewer). */
function getAdminLocationIds(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT location_id FROM user_locations WHERE user_id = :uid AND is_location_admin = 1");
    $stmt->execute(['uid' => $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Used by admin_users.php: can $caller manage $targetUserId's account there?
 * Master admin: always. Location admin (of at least one location): only if
 * the target user has SOME assignment (location- or section-level) under a
 * location the caller administers -- i.e. the caller can manage accounts
 * for people in their own location(s), not anyone else's.
 */
function canManageUserAccount(PDO $pdo, array $caller, int $targetUserId): bool {
    if ($caller['is_admin']) {
        return true;
    }
    $adminLocationIds = getAdminLocationIds($pdo, $caller['user_id']);
    if (empty($adminLocationIds)) {
        return false;
    }
    $placeholders = implode(',', array_fill(0, count($adminLocationIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT 1 FROM user_locations WHERE user_id = ? AND location_id IN ($placeholders)
         UNION
         SELECT 1 FROM user_sections us JOIN section_names sn ON sn.section_id = us.section_id
         WHERE us.user_id = ? AND sn.location_id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$targetUserId], $adminLocationIds, [$targetUserId], $adminLocationIds));
    return (bool)$stmt->fetchColumn();
}

function getAssignedLocationIds(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT location_id FROM user_locations WHERE user_id = :uid");
    $stmt->execute(['uid' => $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Section IDs this user is directly assigned to (section-level, not via a full location assignment). */
function getAssignedSectionIds(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT section_id FROM user_sections WHERE user_id = :uid");
    $stmt->execute(['uid' => $userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * All section IDs this user can see guests/data for: every section in a
 * location they're assigned to, plus any sections assigned to them
 * individually. Returns null for master admins, meaning "no filter, see
 * everything" -- callers should treat null specially rather than looping
 * over it.
 */
/**
 * NOTE: this returns a *flat* list of section IDs the user can see,
 * which is what getdata.php needs for its "WHERE guest_section IN (...)"
 * dashboard filter. It intentionally does NOT tell you *why* a section
 * is accessible (whole-location assignment vs. a specific section
 * assignment) -- if you need that distinction, use buildLocationTree()
 * instead, which preserves the location/section structure.
 */
function getAccessibleSectionIds(PDO $pdo, array $user): ?array {
    if ($user['is_admin']) {
        return null;
    }

    $sectionIds = getAssignedSectionIds($pdo, $user['user_id']);

    $locationIds = getAssignedLocationIds($pdo, $user['user_id']);
    if (!empty($locationIds)) {
        $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
        $stmt = $pdo->prepare("SELECT section_id FROM section_names WHERE location_id IN ($placeholders)");
        $stmt->execute($locationIds);
        $sectionIds = array_merge($sectionIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    return array_values(array_unique($sectionIds));
}

/** True if this user can see (not necessarily edit) the given section. */
function canViewSection(PDO $pdo, array $user, int $sectionId): bool {
    if ($user['is_admin']) return true;
    $accessible = getAccessibleSectionIds($pdo, $user);
    return $accessible !== null && in_array($sectionId, $accessible, true);
}

/**
 * Build the location tree visible to this user: each visible location with
 * its sections and rooms. If $onlyLocationId is given, restricts to that
 * one location (still permission-checked). Sections are filtered to the
 * ones the user can see; if the user has full access to the location
 * (master admin, or a user_locations row), all of that location's sections
 * are included, otherwise only their individually-assigned sections are.
 */
/**
 * TODO: this issues one query per section (for its rooms) and one per
 * location (for its admin list), i.e. it's O(locations + sections)
 * round-trips rather than a couple of JOINs. That's fine for the
 * handful of locations/sections this app currently has, but if that
 * grows into the dozens/hundreds, rewrite this to pull everything in
 * 2-3 queries (locations, then all their sections+rooms via one JOIN
 * keyed by location_id, then all admins via one JOIN) and assemble the
 * nested structure in PHP instead of querying per-row.
 */
function buildLocationTree(PDO $pdo, array $user, ?int $onlyLocationId = null): array {
    $isMaster = (bool)$user['is_admin'];
    $userId = $user['user_id'];

    $assignedLocationIds = getAssignedLocationIds($pdo, $userId);
    $assignedSectionIds = getAssignedSectionIds($pdo, $userId);

    // The reserved "Unassigned" bucket (see getOrCreateUnassignedRoom())
    // deliberately never appears in this tree -- it's not a real location
    // anyone should pick from a dropdown or browse on the Facility
    // Location page. Guests parked there surface instead via the
    // "needs reassignment" list (locations.php action=list_unassigned_guests).
    $unassignedLocationId = null;
    $checkUnassigned = $pdo->query("SELECT location_id FROM locations WHERE location_name = 'Unassigned' LIMIT 1");
    $foundUnassigned = $checkUnassigned->fetchColumn();
    if ($foundUnassigned !== false) {
        $unassignedLocationId = (int)$foundUnassigned;
    }

    if ($isMaster) {
        $locStmt = $onlyLocationId
            ? $pdo->prepare("SELECT location_id, location_name FROM locations WHERE location_id = :id")
            : $pdo->query("SELECT location_id, location_name FROM locations WHERE location_name != 'Unassigned' ORDER BY location_name ASC");
        if ($onlyLocationId) {
            $locStmt->execute(['id' => $onlyLocationId]);
        }
        $locations = $locStmt->fetchAll();
    } else {
        // Visible = has a location-level assignment OR has at least one
        // section assignment within it.
        $visibleLocationIds = $assignedLocationIds;
        if (!empty($assignedSectionIds)) {
            $placeholders = implode(',', array_fill(0, count($assignedSectionIds), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT location_id FROM section_names WHERE section_id IN ($placeholders)");
            $stmt->execute($assignedSectionIds);
            $visibleLocationIds = array_merge($visibleLocationIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        $visibleLocationIds = array_values(array_unique($visibleLocationIds));
        if ($unassignedLocationId !== null) {
            $visibleLocationIds = array_values(array_diff($visibleLocationIds, [$unassignedLocationId]));
        }

        if ($onlyLocationId !== null) {
            $visibleLocationIds = in_array($onlyLocationId, $visibleLocationIds, true) ? [$onlyLocationId] : [];
        }

        if (empty($visibleLocationIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($visibleLocationIds), '?'));
        $stmt = $pdo->prepare("SELECT location_id, location_name FROM locations WHERE location_id IN ($placeholders) ORDER BY location_name ASC");
        $stmt->execute($visibleLocationIds);
        $locations = $stmt->fetchAll();
    }

    $result = [];
    foreach ($locations as $loc) {
        $locationId = (int)$loc['location_id'];
        // "Full" access (sees every section in the location, not just
        // individually-assigned ones) comes from being a master admin OR
        // having a user_locations row at all (admin or not -- a plain
        // member of a location still sees all of that location's
        // sections; user_sections is only for granting access to ONE
        // section of a location the user ISN'T otherwise assigned to).
        $hasFullLocationAccess = $isMaster || in_array($locationId, $assignedLocationIds, true);

        if ($hasFullLocationAccess) {
            $secStmt = $pdo->prepare("SELECT section_id, section_name FROM section_names WHERE location_id = :lid ORDER BY section_name ASC");
            $secStmt->execute(['lid' => $locationId]);
            $sections = $secStmt->fetchAll();
        } else {
            $sectionsInLocation = array_values(array_filter($assignedSectionIds, function ($sid) use ($pdo, $locationId) {
                return getSectionLocationId($pdo, $sid) === $locationId;
            }));
            if (empty($sectionsInLocation)) {
                $sections = [];
            } else {
                $placeholders = implode(',', array_fill(0, count($sectionsInLocation), '?'));
                $secStmt = $pdo->prepare("SELECT section_id, section_name FROM section_names WHERE section_id IN ($placeholders) ORDER BY section_name ASC");
                $secStmt->execute($sectionsInLocation);
                $sections = $secStmt->fetchAll();
            }
        }

        foreach ($sections as &$section) {
            $roomStmt = $pdo->prepare("SELECT room_id, room_name FROM room_names WHERE section_id = :sid ORDER BY room_name ASC");
            $roomStmt->execute(['sid' => $section['section_id']]);
            $section['section_id'] = (int)$section['section_id'];
            $section['rooms'] = array_map(function ($r) {
                return ['room_id' => (int)$r['room_id'], 'room_name' => $r['room_name']];
            }, $roomStmt->fetchAll());
        }
        unset($section);

        $adminStmt = $pdo->prepare(
            "SELECT u.user_id, u.username FROM user_locations ul
             JOIN users u ON u.user_id = ul.user_id
             WHERE ul.location_id = :lid AND ul.is_location_admin = 1
             ORDER BY u.username ASC"
        );
        $adminStmt->execute(['lid' => $locationId]);
        $admins = array_map(function ($a) {
            return ['user_id' => (int)$a['user_id'], 'username' => $a['username']];
        }, $adminStmt->fetchAll());

        $result[] = [
            'location_id' => $locationId,
            'location_name' => $loc['location_name'],
            'is_location_admin' => $isMaster || isLocationAdmin($pdo, $userId, $locationId),
            'admins' => $admins,
            'sections' => $sections,
        ];
    }

    return $result;
}
