<?php
/* =========================================================
   OJ APARTMENT — M-Pesa Daraja API Handler
   Endpoints (?action=):
     token       GET  — get OAuth access token (server-side)
     stk_push    POST — initiate STK Push
     b2c_send    POST — B2C salary disbursement (admin)
     callback    POST — Safaricom payment callback (webhook)
     b2c_result  POST — B2C result callback (webhook)
     status      GET  — query transaction status
   ========================================================= */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

setCorsHeaders();
jsonHeaders();
startSession();

$action = getParam('action', '');
$body   = getJson();

/* ── Base URLs ── */
$baseUrl = MPESA_ENV === 'live'
    ? 'https://api.safaricom.co.ke'
    : 'https://sandbox.safaricom.co.ke';

/* =========================================================
   TOKEN — Get Daraja OAuth token (cached in session/DB)
   ========================================================= */
function getDarajaToken(string $baseUrl): string {
    // Check session cache first
    if (!empty($_SESSION['mpesa_token']) && $_SESSION['mpesa_token_exp'] > time()) {
        return $_SESSION['mpesa_token'];
    }

    $credentials = base64_encode(MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
    $ch = curl_init("$baseUrl/oauth/v1/generate?grant_type=client_credentials");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Basic $credentials"],
        CURLOPT_SSL_VERIFYPEER => MPESA_ENV === 'live',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) serverError('Failed to get M-Pesa token.');

    $data  = json_decode($response, true);
    $token = $data['access_token'] ?? '';
    if (!$token) serverError('Empty M-Pesa access token.');

    // Cache for 55 minutes (token lasts 60)
    $_SESSION['mpesa_token']     = $token;
    $_SESSION['mpesa_token_exp'] = time() + 3300;

    return $token;
}

/* =========================================================
   STK PUSH — Send payment prompt to phone
   ========================================================= */
function stkPush(array $body, string $baseUrl): void {
    startSession();
    $phone  = normalizeMpesaPhone($body['phone'] ?? '');
    $amount = (int)ceil((float)($body['amount'] ?? 0));
    $ref    = sanitize($body['ref']   ?? generateRef('OJ'));
    $desc   = sanitize($body['desc']  ?? 'OJ Apartment Access');
    $userId = (int)($body['user_id']  ?? 0);

    if (!$phone)   badRequest('Invalid phone number.');
    if ($amount < 1) badRequest('Amount must be at least 1.');

    $token     = getDarajaToken($baseUrl);
    $timestamp = date('YmdHis');
    $password  = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);

    $payload = [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => $amount,
        'PartyA'            => $phone,
        'PartyB'            => MPESA_SHORTCODE,
        'PhoneNumber'       => $phone,
        'CallBackURL'       => MPESA_CALLBACK_URL,
        'AccountReference'  => $ref,
        'TransactionDesc'   => $desc,
    ];

    $ch = curl_init("$baseUrl/mpesa/stkpush/v1/processrequest");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => MPESA_ENV === 'live',
    ]);
    $response = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || ($response['ResponseCode'] ?? '1') !== '0') {
        $errMsg = $response['errorMessage'] ?? $response['ResponseDescription'] ?? 'STK push failed.';
        respond(false, $errMsg, [], 502);
    }

    $checkoutId = $response['CheckoutRequestID'];

    // Store pending transaction
    DB::insert(
        'INSERT INTO mpesa_transactions
           (user_id, checkout_request_id, merchant_request_id, phone, amount, ref, type, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, "stk", "pending", NOW())',
        [$userId, $checkoutId, $response['MerchantRequestID'], $phone, $amount, $ref]
    );

    ok('STK push sent. Waiting for PIN.', [
        'checkout_request_id' => $checkoutId,
        'merchant_request_id' => $response['MerchantRequestID'],
    ]);
}

/* =========================================================
   B2C — Business to Customer (salary disburse)
   ========================================================= */
function b2cSend(array $body, string $baseUrl): void {
    requireAdmin();
    $phone     = normalizeMpesaPhone($body['phone'] ?? '');
    $amount    = (int)ceil((float)($body['amount'] ?? 0));
    $staffId   = (int)($body['staff_id'] ?? 0);
    $reason    = sanitize($body['reason'] ?? 'Monthly Salary');

    if (!$phone)     badRequest('Invalid phone number.');
    if ($amount < 1) badRequest('Amount must be at least 1.');

    $token = getDarajaToken($baseUrl);
    $ref   = generateRef('SAL');

    $payload = [
        'InitiatorName'      => MPESA_B2C_INITIATOR,
        'SecurityCredential' => MPESA_B2C_CREDENTIAL,
        'CommandID'          => 'SalaryPayment',
        'Amount'             => $amount,
        'PartyA'             => MPESA_SHORTCODE,
        'PartyB'             => $phone,
        'Remarks'            => $reason,
        'QueueTimeOutURL'    => APP_URL . '/api/mpesa.php?action=b2c_result',
        'ResultURL'          => APP_URL . '/api/mpesa.php?action=b2c_result',
        'Occasion'           => $ref,
    ];

    $ch = curl_init("$baseUrl/mpesa/b2c/v1/paymentrequest");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => MPESA_ENV === 'live',
    ]);
    $response = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || ($response['ResponseCode'] ?? '1') !== '0') {
        $errMsg = $response['errorMessage'] ?? 'B2C request failed.';
        respond(false, $errMsg, [], 502);
    }

    // Log to transactions table
    DB::insert(
        'INSERT INTO mpesa_transactions
           (user_id, checkout_request_id, merchant_request_id, phone, amount, ref, type, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, "b2c", "pending", NOW())',
        [$staffId, $response['ConversationID'] ?? '', $response['OriginatorConversationID'] ?? '', $phone, $amount, $ref]
    );

    // Also log in payroll transactions
    DB::insert(
        'INSERT INTO payroll_transactions
           (staff_id, type, amount, phone, reason, mpesa_ref, status, created_at)
         VALUES (?, "salary", ?, ?, ?, ?, "pending", NOW())',
        [$staffId, $amount, $phone, $reason, $ref]
    );

    ok('B2C payment dispatched.', ['ref' => $ref, 'conversation_id' => $response['ConversationID'] ?? '']);
}

/* =========================================================
   CALLBACK — Safaricom posts result here after STK
   ========================================================= */
function handleCallback(): void {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    // Log raw callback for debugging
    file_put_contents(__DIR__ . '/logs/mpesa_callback.log',
        date('Y-m-d H:i:s') . ' ' . $raw . PHP_EOL, FILE_APPEND);

    $body  = $data['Body']['stkCallback'] ?? null;
    if (!$body) { http_response_code(200); echo '{}'; exit; }

    $checkoutId = $body['CheckoutRequestID']  ?? '';
    $resultCode = (int)($body['ResultCode']   ?? 1);
    $resultDesc = $body['ResultDesc']         ?? '';

    $status = $resultCode === 0 ? 'success' : 'failed';

    $mpesaCode = '';
    $amount    = 0;
    $phone     = '';

    if ($resultCode === 0) {
        $items = $body['CallbackMetadata']['Item'] ?? [];
        foreach ($items as $item) {
            match ($item['Name'] ?? '') {
                'MpesaReceiptNumber' => $mpesaCode = $item['Value'] ?? '',
                'Amount'             => $amount    = (float)($item['Value'] ?? 0),
                'PhoneNumber'        => $phone     = (string)($item['Value'] ?? ''),
                default              => null,
            };
        }
    }

    // Update transaction record
    DB::execute(
        'UPDATE mpesa_transactions
            SET status = ?, mpesa_receipt = ?, result_desc = ?, completed_at = NOW()
          WHERE checkout_request_id = ?',
        [$status, $mpesaCode, $resultDesc, $checkoutId]
    );

    // If success, grant access
    if ($status === 'success') {
        $tx = DB::queryOne(
            'SELECT * FROM mpesa_transactions WHERE checkout_request_id = ?',
            [$checkoutId]
        );
        if ($tx && $tx['user_id']) {
            // Determine plan from amount
            $plan = match(true) {
                $amount <= 700  => 'basic',
                $amount <= 2000 => 'standard',
                default         => 'premium',
            };
            $durations = ['basic' => 1, 'standard' => 7, 'premium' => 30];
            $days = $durations[$plan];

            DB::insert(
                'INSERT INTO access_purchases (user_id, plan, tx_ref, mpesa_receipt, granted_at, expires_at)
                 VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))',
                [$tx['user_id'], $plan, $tx['ref'], $mpesaCode, $days]
            );

            $user = DB::queryOne('SELECT name, email FROM users WHERE id = ?', [$tx['user_id']]);
            if ($user) {
                sendMail($user['email'], 'Access Granted — OJ Apartment', "
                    <h2>Payment Received!</h2>
                    <p>Hi {$user['name']}, your M-Pesa payment of KES " . number_format($amount, 2) . "
                    has been received (Receipt: <strong>{$mpesaCode}</strong>).</p>
                    <p>Your <strong>" . ucfirst($plan) . " Access</strong> is now active for {$days} day(s).</p>
                    <p><a href='" . APP_URL . "/apartments.html'>Browse Vacancies →</a></p>
                ");
            }
        }
    }

    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

/* =========================================================
   B2C RESULT — Safaricom posts B2C result here
   ========================================================= */
function handleB2cResult(): void {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    file_put_contents(__DIR__ . '/logs/mpesa_b2c.log',
        date('Y-m-d H:i:s') . ' ' . $raw . PHP_EOL, FILE_APPEND);

    $result     = $data['Result']   ?? [];
    $resultCode = (int)($result['ResultCode'] ?? 1);
    $convId     = $result['ConversationID'] ?? '';
    $status     = $resultCode === 0 ? 'success' : 'failed';

    $receipt = '';
    foreach (($result['ResultParameters']['ResultParameter'] ?? []) as $p) {
        if ($p['Key'] === 'TransactionReceipt') $receipt = $p['Value'] ?? '';
    }

    DB::execute(
        'UPDATE mpesa_transactions SET status = ?, mpesa_receipt = ?, completed_at = NOW()
          WHERE checkout_request_id = ?',
        [$status, $receipt, $convId]
    );

    DB::execute(
        'UPDATE payroll_transactions SET status = ?, mpesa_receipt = ? WHERE mpesa_ref = (
            SELECT ref FROM mpesa_transactions WHERE checkout_request_id = ? LIMIT 1
        )',
        [$status, $receipt, $convId]
    );

    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

/* =========================================================
   STATUS — Query transaction status
   ========================================================= */
function queryStatus(string $baseUrl): void {
    $checkoutId = sanitize(getParam('checkout_request_id', ''));
    if (!$checkoutId) badRequest('Missing checkout_request_id.');

    $tx = DB::queryOne(
        'SELECT status, mpesa_receipt, result_desc, amount, phone, created_at, completed_at
           FROM mpesa_transactions WHERE checkout_request_id = ?',
        [$checkoutId]
    );
    if (!$tx) notFound('Transaction not found.');
    ok('OK', ['transaction' => $tx]);
}

/* ── Route ── */
switch ($action) {
    case 'token':       startSession(); ok('Token ready', ['token' => getDarajaToken($baseUrl)]); break;
    case 'stk_push':    startSession(); stkPush($body, $baseUrl);  break;
    case 'b2c_send':    startSession(); b2cSend($body, $baseUrl);  break;
    case 'callback':    handleCallback(); break;
    case 'b2c_result':  handleB2cResult(); break;
    case 'status':      startSession(); queryStatus($baseUrl); break;
    default:            badRequest('Unknown action.');
}
