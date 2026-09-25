<?php
/* =========================================================
   OJ APARTMENT — Contact Form API
   Endpoints (?action=):
     submit     POST — save enquiry + email admin
     list       GET  — list all enquiries (admin only)
     update     POST — mark enquiry status (admin only)
     delete     POST — delete enquiry (admin only)
   ========================================================= */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

setCorsHeaders();
jsonHeaders();
startSession();

$action = getParam('action', 'submit');
$body   = getJson();

switch ($action) {

    /* ── Submit enquiry ── */
    case 'submit': {
        $name     = sanitize($body['name']     ?? '');
        $contact  = sanitize($body['contact']  ?? '');  // email or phone
        $interest = sanitize($body['interest'] ?? 'General information');
        $message  = sanitize($body['message']  ?? '');

        if (!$name)    badRequest('Name is required.');
        if (!$contact) badRequest('Email or phone is required.');
        if (!$message) badRequest('Message is required.');

        // Determine if contact is email or phone
        $email = filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : null;
        $phone = !$email ? $contact : null;

        $id = DB::insert(
            'INSERT INTO enquiries (name, email, phone, interest, message, status, created_at)
             VALUES (?, ?, ?, ?, ?, "new", NOW())',
            [$name, $email, $phone, $interest, $message]
        );

        // Email admin
        $adminHtml = "
            <h2>New Enquiry — OJ Apartment</h2>
            <table>
              <tr><td><strong>From:</strong></td><td>{$name}</td></tr>
              <tr><td><strong>Contact:</strong></td><td>{$contact}</td></tr>
              <tr><td><strong>Interest:</strong></td><td>{$interest}</td></tr>
              <tr><td><strong>Message:</strong></td><td>{$message}</td></tr>
              <tr><td><strong>Time:</strong></td><td>" . date('Y-m-d H:i:s') . "</td></tr>
            </table>
        ";
        sendMail(MAIL_TO_ADMIN, "New Enquiry from {$name}", $adminHtml);

        // Auto-reply to sender (if email provided)
        if ($email) {
            sendMail($email, 'We received your enquiry — OJ Apartment', "
                <h2>Thank you, {$name}!</h2>
                <p>We've received your message about <strong>{$interest}</strong> and will get back to you within 24 hours.</p>
                <p>Our office hours are Monday – Saturday, 8am – 6pm.</p>
                <p>📞 +254 700 000 000 &nbsp;|&nbsp; 📧 info@ojapartment.co.ke</p>
            ");
        }

        ok('Your message has been sent. We\'ll get back to you soon!', ['enquiry_id' => $id]);
        break;
    }

    /* ── List enquiries (admin) ── */
    case 'list': {
        requireAdmin();
        $status = sanitize(getParam('status', ''));
        $sql    = 'SELECT * FROM enquiries';
        $params = [];
        if ($status) { $sql .= ' WHERE status = ?'; $params[] = $status; }
        $sql .= ' ORDER BY created_at DESC';
        $rows = DB::query($sql, $params);
        ok('OK', ['enquiries' => $rows, 'count' => count($rows)]);
        break;
    }

    /* ── Update status (admin) ── */
    case 'update': {
        requireAdmin();
        $id     = (int)($body['id']     ?? 0);
        $status = sanitize($body['status'] ?? '');
        if (!$id || !$status) badRequest('id and status are required.');
        DB::execute('UPDATE enquiries SET status = ? WHERE id = ?', [$status, $id]);
        ok('Enquiry updated.');
        break;
    }

    /* ── Delete (admin) ── */
    case 'delete': {
        requireAdmin();
        $id = (int)($body['id'] ?? 0);
        if (!$id) badRequest('Missing id.');
        DB::execute('DELETE FROM enquiries WHERE id = ?', [$id]);
        ok('Enquiry deleted.');
        break;
    }

    default:
        badRequest('Unknown action.');
}
