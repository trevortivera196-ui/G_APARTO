<?php
/* =========================================================
   OJ APARTMENT — Auth API
   Endpoints (POST, action in JSON body or ?action=):
     register   — create new user account
     login      — start session
     logout     — destroy session
     me         — return current session user
     change_password
   ========================================================= */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

setCorsHeaders();
jsonHeaders();
startSession();

$action = getParam('action', getJson()['action'] ?? '');
$body   = getJson();

switch ($action) {

    /* ── Register ── */
    case 'register': {
        $name     = sanitize($body['name']     ?? '');
        $email    = strtolower(trim($body['email']    ?? ''));
        $phone    = sanitize($body['phone']    ?? '');
        $password = $body['password'] ?? '';
        $role     = 'tenant'; // default; admin must be set manually in DB

        if (!$name || !$email || !$password)
            badRequest('Name, email and password are required.');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            badRequest('Invalid email address.');

        if (strlen($password) < 8)
            badRequest('Password must be at least 8 characters.');

        $exists = DB::queryOne('SELECT id FROM users WHERE email = ?', [$email]);
        if ($exists) badRequest('An account with that email already exists.');

        $id = DB::insert(
            'INSERT INTO users (name, email, phone, password_hash, role, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$name, $email, $phone, hashPassword($password), $role]
        );

        // Send welcome email
        sendMail($email, 'Welcome to OJ Apartment', "
            <h2>Welcome, {$name}!</h2>
            <p>Your account has been created. You can now log in and purchase access to browse our vacancies.</p>
            <p><a href='" . APP_URL . "/payment.html'>View Access Plans</a></p>
        ");

        $user = DB::queryOne('SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?', [$id]);
        created('Account created successfully.', ['user' => $user]);
        break;
    }

    /* ── Login ── */
    case 'login': {
        $email    = strtolower(trim($body['email']    ?? ''));
        $password = $body['password'] ?? '';

        if (!$email || !$password) badRequest('Email and password are required.');

        $user = DB::queryOne('SELECT * FROM users WHERE email = ?', [$email]);

        if (!$user || !verifyPassword($password, $user['password_hash']))
            unauthorized('Invalid email or password.');

        if ($user['is_active'] == 0)
            forbidden('Your account has been deactivated. Contact admin.');

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'role'  => $user['role'],
        ];

        // Update last login
        DB::execute('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);

        ok('Login successful.', ['user' => $_SESSION['user']]);
        break;
    }

    /* ── Logout ── */
    case 'logout': {
        $_SESSION = [];
        session_destroy();
        ok('Logged out successfully.');
        break;
    }

    /* ── Current user ── */
    case 'me': {
        $user = currentUser();
        if (!$user) unauthorized('Not logged in.');

        // Also check if user has a valid access purchase
        $access = DB::queryOne(
            'SELECT plan, expires_at FROM access_purchases
              WHERE user_id = ? AND expires_at > NOW()
              ORDER BY expires_at DESC LIMIT 1',
            [$user['id']]
        );
        $user['access'] = $access ?: null;
        ok('OK', ['user' => $user]);
        break;
    }

    /* ── Change password ── */
    case 'change_password': {
        $user        = requireAuth();
        $oldPassword = $body['old_password'] ?? '';
        $newPassword = $body['new_password'] ?? '';

        if (!$oldPassword || !$newPassword) badRequest('Both old and new passwords are required.');
        if (strlen($newPassword) < 8)       badRequest('New password must be at least 8 characters.');

        $row = DB::queryOne('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if (!verifyPassword($oldPassword, $row['password_hash']))
            unauthorized('Current password is incorrect.');

        DB::execute('UPDATE users SET password_hash = ? WHERE id = ?',
            [hashPassword($newPassword), $user['id']]);

        ok('Password updated successfully.');
        break;
    }

    /* ── Grant access (called after M-Pesa confirmation) ── */
    case 'grant_access': {
        // Called internally by mpesa.php callback — no auth required here,
        // but we verify the transaction reference.
        $userId    = (int)($body['user_id']  ?? 0);
        $plan      = sanitize($body['plan']  ?? 'standard');
        $txRef     = sanitize($body['tx_ref'] ?? '');

        if (!$userId || !$txRef) badRequest('Missing user_id or tx_ref.');

        $durations = ['basic' => 1, 'standard' => 7, 'premium' => 30];
        $days      = $durations[$plan] ?? 7;

        DB::insert(
            'INSERT INTO access_purchases (user_id, plan, tx_ref, granted_at, expires_at)
             VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))',
            [$userId, $plan, $txRef, $days]
        );

        $user = DB::queryOne('SELECT name, email FROM users WHERE id = ?', [$userId]);
        if ($user) {
            sendMail($user['email'], 'Your OJ Apartment Access is Active', "
                <h2>Access Granted!</h2>
                <p>Hi {$user['name']}, your <strong>" . ucfirst($plan) . " Access</strong> plan is now active
                   for {$days} day(s).</p>
                <p><a href='" . APP_URL . "/apartments.html'>Browse Vacancies →</a></p>
            ");
        }

        ok('Access granted.', ['expires_in_days' => $days]);
        break;
    }

    default:
        badRequest('Unknown action: ' . htmlspecialchars($action));
}
