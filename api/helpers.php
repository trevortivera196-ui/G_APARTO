<?php
/* =========================================================
   OJ APARTMENT — Shared Helper Functions
   ========================================================= */
require_once __DIR__ . '/config.php';

/* ── CORS & JSON headers ── */
function setCorsHeaders(): void {
    header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function jsonHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
}

/* ── Unified JSON response ── */
function respond(bool $success, string $message, array $data = [], int $code = 200): void {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ]);
    exit;
}

function ok(string $message = 'OK', array $data = []): void {
    respond(true, $message, $data, 200);
}

function created(string $message = 'Created', array $data = []): void {
    respond(true, $message, $data, 201);
}

function badRequest(string $message = 'Bad request'): void {
    respond(false, $message, [], 400);
}

function unauthorized(string $message = 'Unauthorized'): void {
    respond(false, $message, [], 401);
}

function forbidden(string $message = 'Forbidden'): void {
    respond(false, $message, [], 403);
}

function notFound(string $message = 'Not found'): void {
    respond(false, $message, [], 404);
}

function serverError(string $message = 'Internal server error'): void {
    respond(false, $message, [], 500);
}

/* ── Input helpers ── */
function getJson(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function getParam(string $key, mixed $default = null): mixed {
    return $_REQUEST[$key] ?? $default;
}

function sanitize(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

/* ── Session ── */
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => APP_ENV === 'production',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function currentUser(): ?array {
    startSession();
    return $_SESSION['user'] ?? null;
}

function requireAuth(): array {
    $user = currentUser();
    if (!$user) unauthorized('You must be logged in.');
    return $user;
}

function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') forbidden('Admin access required.');
    return $user;
}

/* ── Password ── */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/* ── Simple email via mail() ── */
function sendMail(string $to, string $subject, string $htmlBody): bool {
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
    return mail($to, $subject, $htmlBody, $headers);
}

/* ── KES formatting ── */
function fmtKES(float $amount): string {
    return 'KES ' . number_format($amount, 2);
}

/* ── Generate reference code ── */
function generateRef(string $prefix = 'OJ'): string {
    return strtoupper($prefix) . '-' . strtoupper(substr(uniqid(), -6)) . '-' . rand(100, 999);
}

/* ── Validate Safaricom phone ── */
function normalizeMpesaPhone(string $phone): ?string {
    $phone = preg_replace('/\D/', '', $phone);
    // Accept 07xxxxxxxx, 01xxxxxxxx, 254xxxxxxxx, +254xxxxxxxx
    if (strlen($phone) === 10 && ($phone[0] === '0')) {
        $phone = '254' . substr($phone, 1);
    }
    if (strlen($phone) === 12 && str_starts_with($phone, '254')) {
        return $phone;
    }
    return null;
}
