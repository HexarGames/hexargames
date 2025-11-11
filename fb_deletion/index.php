<?php
declare(strict_types=1);

// --- Health check (safe GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['health'])) {
  header('Content-Type: application/json');
  echo json_encode(['ok' => true, 'ts' => gmdate('c')]);
  exit;
}

header('Content-Type: application/json');

// Fail-safe error handler -> never a blank 500 page
set_exception_handler(function($e){
  http_response_code(500);
  echo json_encode(['error' => 'server_error', 'msg' => 'Please check server logs.']);
  error_log('[fb_deletion] Uncaught: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
});
set_error_handler(function($severity, $message, $file, $line){
  http_response_code(500);
  echo json_encode(['error' => 'server_error', 'msg' => 'Please check server logs.']);
  error_log("[fb_deletion] PHP Error: $message @ $file:$line");
  return true;
});

require_once __DIR__.'/db.php'; // must exist and connect OK

$APP_SECRET = getenv('FB_APP_SECRET') ?: 'd67c3772d442ad286b47a94e8ac4d70b';

// Only POST is allowed by Facebook
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method_not_allowed']);
  exit;
}

if (empty($_POST['signed_request'])) {
  http_response_code(400);
  echo json_encode(['error' => 'missing_signed_request']);
  exit;
}

function base64_url_decode(string $input): string {
  // Add padding if needed
  $remainder = strlen($input) % 4;
  if ($remainder) $input .= str_repeat('=', 4 - $remainder);
  return base64_decode(strtr($input, '-_', '+/'));
}

list($encoded_sig, $payload) = explode('.', $_POST['signed_request'], 2);

// Verify signature
$expected_sig = hash_hmac('sha256', $payload, $APP_SECRET, true);
$decoded_sig  = base64_url_decode($encoded_sig);
if (!hash_equals($decoded_sig, $expected_sig)) {
  http_response_code(400);
  echo json_encode(['error' => 'bad_signature']);
  exit;
}

// Parse payload
$data = json_decode(base64_url_decode($payload), true);
if (!is_array($data) || ($data['algorithm'] ?? '') !== 'HMAC-SHA256') {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_payload']);
  exit;
}

$user_id = $data['user_id'] ?? null;
if (!$user_id) {
  http_response_code(400);
  echo json_encode(['error' => 'missing_user_id']);
  exit;
}

// Generate confirmation code (alphanumeric)
$confirmation_code = bin2hex(random_bytes(8));

// Persist request
$status_url = "https://{$_SERVER['HTTP_HOST']}/fb_deletion/status.php?id=".$confirmation_code;

$stmt = $conn->prepare("INSERT INTO deletion_requests (confirmation_code, user_id, status) VALUES (?, ?, 'queued')");
if (!$stmt) throw new Exception('Prepare failed: '.$conn->error);
$stmt->bind_param("ss", $confirmation_code, $user_id);
if (!$stmt->execute()) throw new Exception('Execute failed: '.$stmt->error);
$stmt->close();

// Kick off deletion (sync demo; replace with background job if needed)
$upd = $conn->prepare("UPDATE deletion_requests SET status='deleted' WHERE confirmation_code=?");
$upd->bind_param("s", $confirmation_code);
$upd->execute();
$upd->close();

// Respond per Facebook spec
echo json_encode([
  'url' => $status_url,
  'confirmation_code' => $confirmation_code
]);
