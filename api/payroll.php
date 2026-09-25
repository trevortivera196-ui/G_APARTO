<?php
/* =========================================================
   OJ APARTMENT — Payroll API
   Endpoints (?action=):
     Staff:
       staff_list      GET   — all staff
       staff_get       GET   — single staff member
       staff_create    POST  — add staff (admin)
       staff_update    POST  — edit staff (admin)
       staff_delete    POST  — delete staff (admin)
     Payroll:
       run             POST  — calculate & disburse payroll via M-Pesa B2C (admin)
       history         GET   — transaction history
     Withdrawals:
       withdrawal      POST  — ad-hoc M-Pesa send to staff (admin)
     Settings:
       settings_get    GET   — load payroll settings
       settings_save   POST  — save payroll settings (admin)
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

    /* ══════════════ STAFF ══════════════ */

    case 'staff_list': {
        requireAuth();
        $dept   = sanitize(getParam('dept', ''));
        $status = sanitize(getParam('status', ''));
        $sql    = 'SELECT id, name, email, phone, department, role, salary, status, created_at FROM staff WHERE 1=1';
        $params = [];
        if ($dept)   { $sql .= ' AND department = ?'; $params[] = $dept; }
        if ($status) { $sql .= ' AND status = ?';     $params[] = $status; }
        $sql .= ' ORDER BY name ASC';
        ok('OK', ['staff' => DB::query($sql, $params)]);
        break;
    }

    case 'staff_get': {
        requireAuth();
        $id   = (int)getParam('id', 0);
        if (!$id) badRequest('Missing id.');
        $row = DB::queryOne('SELECT * FROM staff WHERE id = ?', [$id]);
        if (!$row) notFound('Staff member not found.');
        ok('OK', ['staff' => $row]);
        break;
    }

    case 'staff_create': {
        requireAdmin();
        $fields = validateStaffFields($body);
        $id = DB::insert(
            'INSERT INTO staff (name, email, phone, department, role, salary, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            $fields
        );
        ok('Staff member added.', ['id' => $id]);
        break;
    }

    case 'staff_update': {
        requireAdmin();
        $id     = (int)($body['id'] ?? 0);
        if (!$id) badRequest('Missing id.');
        $fields   = validateStaffFields($body);
        $fields[] = $id;
        DB::execute(
            'UPDATE staff SET name=?, email=?, phone=?, department=?, role=?, salary=?, status=? WHERE id=?',
            $fields
        );
        ok('Staff member updated.');
        break;
    }

    case 'staff_delete': {
        requireAdmin();
        $id = (int)($body['id'] ?? 0);
        if (!$id) badRequest('Missing id.');
        DB::execute('DELETE FROM staff WHERE id = ?', [$id]);
        ok('Staff member deleted.');
        break;
    }

    /* ══════════════ PAYROLL RUN ══════════════ */

    case 'run': {
        requireAdmin();
        $month      = sanitize($body['month'] ?? date('Y-m'));
        $staffIds   = $body['staff_ids'] ?? [];  // empty = all active

        $settings = getPayrollSettings();

        // Load staff
        $sql    = 'SELECT * FROM staff WHERE status = "active"';
        $params = [];
        if (!empty($staffIds)) {
            $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
            $sql .= " AND id IN ($placeholders)";
            $params = array_map('intval', $staffIds);
        }
        $staffList = DB::query($sql, $params);
        if (empty($staffList)) badRequest('No active staff found.');

        $disbursed = [];
        $errors    = [];
        $totalNet  = 0;

        foreach ($staffList as $s) {
            $net = calcNetPay((float)$s['salary'], $settings)['net'];
            $totalNet += $net;

            // Trigger B2C via M-Pesa
            $phone = normalizeMpesaPhone($s['phone']);
            if (!$phone) {
                $errors[] = "Invalid phone for {$s['name']}";
                continue;
            }

            $ref = generateRef('PAY');
            $txId = DB::insert(
                'INSERT INTO payroll_transactions
                   (staff_id, type, amount, phone, reason, mpesa_ref, month, status, created_at)
                 VALUES (?, "salary", ?, ?, "Monthly Salary", ?, ?, "pending", NOW())',
                [$s['id'], $net, $phone, $ref, $month]
            );

            $disbursed[] = [
                'staff_id'  => $s['id'],
                'name'      => $s['name'],
                'phone'     => $phone,
                'net_pay'   => $net,
                'ref'       => $ref,
                'tx_id'     => $txId,
            ];
        }

        ok('Payroll run initiated.', [
            'month'      => $month,
            'count'      => count($disbursed),
            'total_net'  => $totalNet,
            'disbursed'  => $disbursed,
            'errors'     => $errors,
        ]);
        break;
    }

    /* ══════════════ HISTORY ══════════════ */

    case 'history': {
        requireAuth();
        $type    = sanitize(getParam('type', ''));
        $month   = sanitize(getParam('month', ''));
        $limit   = min((int)getParam('limit', 50), 200);
        $offset  = (int)getParam('offset', 0);

        $sql    = 'SELECT pt.*, s.name as staff_name, s.department
                     FROM payroll_transactions pt
                     LEFT JOIN staff s ON s.id = pt.staff_id
                    WHERE 1=1';
        $params = [];
        if ($type)  { $sql .= ' AND pt.type = ?';  $params[] = $type; }
        if ($month) { $sql .= ' AND pt.month = ?'; $params[] = $month; }
        $sql .= ' ORDER BY pt.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit; $params[] = $offset;

        $rows = DB::query($sql, $params);
        ok('OK', ['transactions' => $rows, 'count' => count($rows)]);
        break;
    }

    /* ══════════════ WITHDRAWAL ══════════════ */

    case 'withdrawal': {
        requireAdmin();
        $staffId = (int)($body['staff_id'] ?? 0);
        $phone   = sanitize($body['phone'] ?? '');
        $amount  = (float)($body['amount'] ?? 0);
        $reason  = sanitize($body['reason'] ?? 'Withdrawal');

        if ($amount < 1)   badRequest('Amount must be at least 1.');
        if (!$phone)        badRequest('Phone number is required.');

        $normPhone = normalizeMpesaPhone($phone);
        if (!$normPhone) badRequest('Invalid phone number.');

        $ref = generateRef('WD');
        $txId = DB::insert(
            'INSERT INTO payroll_transactions
               (staff_id, type, amount, phone, reason, mpesa_ref, month, status, created_at)
             VALUES (?, "withdrawal", ?, ?, ?, ?, ?, "pending", NOW())',
            [$staffId ?: null, $amount, $normPhone, $reason, $ref, date('Y-m')]
        );

        ok('Withdrawal logged. Trigger B2C via /api/mpesa.php?action=b2c_send to disburse.', [
            'tx_id' => $txId,
            'ref'   => $ref,
            'phone' => $normPhone,
        ]);
        break;
    }

    /* ══════════════ SETTINGS ══════════════ */

    case 'settings_get': {
        requireAuth();
        ok('OK', ['settings' => getPayrollSettings()]);
        break;
    }

    case 'settings_save': {
        requireAdmin();
        $fields = [
            'frequency'     => sanitize($body['frequency']     ?? 'Monthly'),
            'payday'        => sanitize($body['payday']        ?? '28th of every month'),
            'currency'      => sanitize($body['currency']      ?? 'KES'),
            'nhif'          => (int)(bool)($body['nhif']         ?? true),
            'nssf'          => (int)(bool)($body['nssf']         ?? true),
            'paye'          => (int)(bool)($body['paye']         ?? true),
            'housing_levy'  => (int)(bool)($body['housing_levy'] ?? false),
            'house_allow'   => (int)(bool)($body['house_allow']  ?? true),
            'transport'     => (int)(bool)($body['transport']    ?? true),
            'medical'       => (int)(bool)($body['medical']      ?? false),
            'overtime'      => (int)(bool)($body['overtime']     ?? false),
            'custom_ded'    => (float)($body['custom_ded']      ?? 0),
            'shortcode'     => sanitize($body['shortcode']     ?? ''),
            'auto_disburse' => (int)(bool)($body['auto_disburse'] ?? false),
        ];

        DB::execute('DELETE FROM payroll_settings WHERE 1=1');
        foreach ($fields as $k => $v) {
            DB::insert(
                'INSERT INTO payroll_settings (setting_key, setting_value) VALUES (?, ?)',
                [$k, (string)$v]
            );
        }
        ok('Settings saved.');
        break;
    }

    default:
        badRequest('Unknown action: ' . htmlspecialchars($action));
}

/* ── Load settings as associative array ── */
function getPayrollSettings(): array {
    $rows = DB::query('SELECT setting_key, setting_value FROM payroll_settings');
    $s    = [];
    foreach ($rows as $r) $s[$r['setting_key']] = $r['setting_value'];

    return array_merge([
        'nhif'        => 1, 'nssf'        => 1, 'paye'       => 1,
        'housing_levy'=> 0, 'house_allow' => 1, 'transport'  => 1,
        'medical'     => 0, 'overtime'    => 0, 'custom_ded' => 0,
    ], $s);
}

/* ── Kenya payroll calculator ── */
function calcNetPay(float $basic, array $s): array {
    $allowances = 0;
    if ($s['house_allow'] ?? false)  $allowances += round($basic * 0.15);
    if ($s['transport']   ?? false)  $allowances += 3000;
    if ($s['medical']     ?? false)  $allowances += 2000;
    $gross = $basic + $allowances;

    $deductions = 0;
    if ($s['nhif']         ?? false) $deductions += nhifBand($gross);
    if ($s['nssf']         ?? false) $deductions += min(round($gross * 0.06), 2160);
    if ($s['paye']         ?? false) $deductions += payeBand($gross);
    if ($s['housing_levy'] ?? false) $deductions += round($gross * 0.015);
    if (($s['custom_ded'] ?? 0) > 0) $deductions += round($gross * ($s['custom_ded'] / 100));

    return ['allowances' => $allowances, 'deductions' => $deductions,
            'gross' => $gross, 'net' => max(0, $gross - $deductions)];
}

function nhifBand(float $g): int {
    if ($g <= 5999)  return 150; if ($g <= 7999)  return 300;
    if ($g <= 11999) return 400; if ($g <= 14999) return 500;
    if ($g <= 19999) return 600; if ($g <= 24999) return 750;
    if ($g <= 29999) return 850; if ($g <= 34999) return 900;
    if ($g <= 39999) return 950; if ($g <= 44999) return 1000;
    if ($g <= 49999) return 1100;if ($g <= 59999) return 1200;
    if ($g <= 69999) return 1300;if ($g <= 79999) return 1400;
    if ($g <= 89999) return 1500;if ($g <= 99999) return 1600;
    return 1700;
}

function payeBand(float $g): int {
    $personal = 2400;
    if ($g <= 24000)       $tax = $g * 0.10;
    elseif ($g <= 32333)   $tax = 2400 + ($g - 24000) * 0.25;
    elseif ($g <= 500000)  $tax = 4483 + ($g - 32333) * 0.30;
    else                   $tax = 144481 + ($g - 500000) * 0.325;
    return max(0, (int)round($tax - $personal));
}

/* ── Staff field validation ── */
function validateStaffFields(array $b): array {
    $name   = sanitize($b['name']       ?? '');
    $email  = sanitize($b['email']      ?? '');
    $phone  = sanitize($b['phone']      ?? '');
    $dept   = sanitize($b['department'] ?? '');
    $role   = sanitize($b['role']       ?? '');
    $salary = (float)($b['salary']      ?? 0);
    $status = sanitize($b['status']     ?? 'active');
    if (!$name || !$phone || $salary <= 0) badRequest('name, phone and salary are required.');
    return [$name, $email, $phone, $dept, $role, $salary, $status];
}
