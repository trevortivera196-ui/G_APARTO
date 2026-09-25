<?php
/* =========================================================
   OJ APARTMENT — Apartments / Vacancies API
   Endpoints (?action=):
     list        GET  — list all apartments (with filters)
     get         GET  — single apartment by id
     reserve     POST — reserve a unit (auth required)
     cancel      POST — cancel reservation (auth required)
     create      POST — add unit (admin only)
     update      POST — edit unit (admin only)
     delete      POST — delete unit (admin only)
   ========================================================= */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

setCorsHeaders();
jsonHeaders();
startSession();

$action = getParam('action', '');
$body   = getJson();

switch ($action) {

    /* ── List apartments ── */
    case 'list': {
        $type      = sanitize(getParam('type',   ''));
        $status    = sanitize(getParam('status', ''));
        $floor     = (int)getParam('floor',  0);
        $maxRent   = (float)getParam('max_rent', 0);

        $sql    = 'SELECT * FROM apartments WHERE 1=1';
        $params = [];

        if ($type)    { $sql .= ' AND type = ?';     $params[] = $type; }
        if ($status)  { $sql .= ' AND status = ?';   $params[] = $status; }
        if ($floor)   { $sql .= ' AND floor = ?';    $params[] = $floor; }
        if ($maxRent) { $sql .= ' AND rent <= ?';    $params[] = $maxRent; }

        $sql .= ' ORDER BY floor ASC, unit ASC';

        $rows = DB::query($sql, $params);

        // Decode amenities JSON
        foreach ($rows as &$row) {
            $row['amenities'] = json_decode($row['amenities'] ?? '[]', true);
        }

        ok('OK', ['apartments' => $rows, 'count' => count($rows)]);
        break;
    }

    /* ── Single apartment ── */
    case 'get': {
        $id  = (int)getParam('id', 0);
        if (!$id) badRequest('Missing apartment id.');

        $apt = DB::queryOne('SELECT * FROM apartments WHERE id = ?', [$id]);
        if (!$apt) notFound('Apartment not found.');

        $apt['amenities'] = json_decode($apt['amenities'] ?? '[]', true);

        // Load active reservation if any
        $res = DB::queryOne(
            'SELECT r.*, u.name as tenant_name FROM reservations r
              JOIN users u ON u.id = r.user_id
             WHERE r.apartment_id = ? AND r.status IN ("pending","confirmed")
             LIMIT 1',
            [$id]
        );
        $apt['reservation'] = $res ?: null;

        ok('OK', ['apartment' => $apt]);
        break;
    }

    /* ── Reserve a unit ── */
    case 'reserve': {
        $user      = requireAuth();
        $aptId     = (int)($body['apartment_id'] ?? 0);
        $moveInDate = sanitize($body['move_in_date'] ?? '');
        $notes     = sanitize($body['notes'] ?? '');

        if (!$aptId)      badRequest('Missing apartment_id.');
        if (!$moveInDate) badRequest('Missing move_in_date.');

        // Check user has valid access
        $access = DB::queryOne(
            'SELECT id FROM access_purchases WHERE user_id = ? AND expires_at > NOW() LIMIT 1',
            [$user['id']]
        );
        if (!$access) forbidden('You need an active access plan to reserve a unit.');

        $apt = DB::queryOne('SELECT * FROM apartments WHERE id = ?', [$aptId]);
        if (!$apt)                      notFound('Apartment not found.');
        if ($apt['status'] !== 'available') badRequest('This unit is not available.');

        // Check no existing pending/confirmed reservation for this user
        $existing = DB::queryOne(
            'SELECT id FROM reservations WHERE user_id = ? AND status IN ("pending","confirmed")',
            [$user['id']]
        );
        if ($existing) badRequest('You already have an active reservation. Cancel it first.');

        DB::execute('UPDATE apartments SET status = ? WHERE id = ?', ['reserved', $aptId]);

        $resId = DB::insert(
            'INSERT INTO reservations (user_id, apartment_id, move_in_date, notes, status, created_at)
             VALUES (?, ?, ?, ?, "pending", NOW())',
            [$user['id'], $aptId, $moveInDate, $notes]
        );

        // Notify admin
        sendMail(MAIL_TO_ADMIN,
            'New Unit Reservation — Unit ' . $apt['unit'],
            "<p><strong>{$user['name']}</strong> has reserved Unit {$apt['unit']} ({$apt['type']}, Floor {$apt['floor']}).</p>
             <p>Move-in date: {$moveInDate}</p><p>Notes: {$notes}</p>"
        );

        // Confirm to tenant
        sendMail($user['email'],
            'Reservation Confirmed — Unit ' . $apt['unit'],
            "<h2>Your Reservation is Confirmed!</h2>
             <p>Hi {$user['name']}, Unit <strong>{$apt['unit']}</strong> ({$apt['type']}, Floor {$apt['floor']})
             has been reserved for you.</p>
             <p>Planned move-in: <strong>{$moveInDate}</strong></p>
             <p>Our team will contact you within 24 hours to finalise the lease.</p>"
        );

        ok('Unit reserved successfully.', ['reservation_id' => $resId]);
        break;
    }

    /* ── Cancel reservation ── */
    case 'cancel': {
        $user  = requireAuth();
        $resId = (int)($body['reservation_id'] ?? 0);
        if (!$resId) badRequest('Missing reservation_id.');

        $res = DB::queryOne(
            'SELECT r.*, a.unit FROM reservations r JOIN apartments a ON a.id = r.apartment_id
              WHERE r.id = ? AND r.user_id = ?',
            [$resId, $user['id']]
        );
        if (!$res) notFound('Reservation not found or does not belong to you.');

        DB::execute('UPDATE reservations SET status = "cancelled" WHERE id = ?', [$resId]);
        DB::execute('UPDATE apartments SET status = "available" WHERE id = ?', [$res['apartment_id']]);

        ok('Reservation cancelled. The unit is now available again.');
        break;
    }

    /* ── Admin: Create apartment ── */
    case 'create': {
        requireAdmin();
        $fields = validateAptFields($body);

        $id = DB::insert(
            'INSERT INTO apartments
               (unit, floor, type, status, rent, size_sqm, beds, baths, description, amenities, image_url, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            $fields
        );
        $apt = DB::queryOne('SELECT * FROM apartments WHERE id = ?', [$id]);
        $apt['amenities'] = json_decode($apt['amenities'], true);
        created('Apartment created.', ['apartment' => $apt]);
        break;
    }

    /* ── Admin: Update apartment ── */
    case 'update': {
        requireAdmin();
        $id = (int)($body['id'] ?? 0);
        if (!$id) badRequest('Missing apartment id.');

        $fields   = validateAptFields($body);
        $fields[] = $id;

        DB::execute(
            'UPDATE apartments SET
               unit=?, floor=?, type=?, status=?, rent=?, size_sqm=?,
               beds=?, baths=?, description=?, amenities=?, image_url=?
             WHERE id=?',
            $fields
        );
        ok('Apartment updated.');
        break;
    }

    /* ── Admin: Delete apartment ── */
    case 'delete': {
        requireAdmin();
        $id = (int)($body['id'] ?? 0);
        if (!$id) badRequest('Missing apartment id.');
        DB::execute('DELETE FROM apartments WHERE id = ?', [$id]);
        ok('Apartment deleted.');
        break;
    }

    default:
        badRequest('Unknown action: ' . htmlspecialchars($action));
}

/* ── Field validation helper ── */
function validateAptFields(array $b): array {
    $unit    = sanitize($b['unit']        ?? '');
    $floor   = (int)($b['floor']          ?? 0);
    $type    = sanitize($b['type']        ?? '');
    $status  = sanitize($b['status']      ?? 'available');
    $rent    = (float)($b['rent']         ?? 0);
    $size    = (float)($b['size_sqm']     ?? 0);
    $beds    = (int)($b['beds']           ?? 0);
    $baths   = (int)($b['baths']          ?? 1);
    $desc    = sanitize($b['description'] ?? '');
    $amenities = json_encode($b['amenities'] ?? []);
    $image   = sanitize($b['image_url']   ?? '');

    if (!$unit || !$floor || !$type || !$rent)
        badRequest('unit, floor, type and rent are required.');

    return [$unit, $floor, $type, $status, $rent, $size, $beds, $baths, $desc, $amenities, $image];
}
