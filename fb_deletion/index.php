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

if (!is_file(__DIR__.'/db.php')) {
  http_response_code(500);
  echo json_encode(['error' => 'missing_db_config']);
  exit;
}
require_once __DIR__.'/db.php'; // must exist and connect OK

if (!is_file(__DIR__.'/helpers.php')) {
  http_response_code(500);
  echo json_encode(['error' => 'missing_helpers']);
  exit;
}
require_once __DIR__.'/helpers.php';

$appsConfig = fb_apps_config();
$defaultSecret = getenv('FB_APP_SECRET') ?: 'd67c3772d442ad286b47a94e8ac4d70b';
$appSlugParam = $_GET['app'] ?? null;
$requestedAppId = null;
if ($appSlugParam !== null && $appSlugParam !== '') {
  $requestedAppId = fb_app_id_from_slug($appSlugParam);
  if ($requestedAppId === null) $requestedAppId = $appSlugParam;
}

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

// Parse payload to learn which app sent the request
$payload_raw = base64_url_decode($payload);
$data = json_decode($payload_raw, true);
if (!is_array($data) || ($data['algorithm'] ?? '') !== 'HMAC-SHA256') {
  http_response_code(400);
  echo json_encode(['error' => 'invalid_payload']);
  exit;
}

$app_id = $data['app_id'] ?? null;
if (!$app_id) {
  http_response_code(400);
  echo json_encode(['error' => 'missing_app_id']);
  exit;
}

if ($requestedAppId !== null && $requestedAppId !== $app_id) {
  http_response_code(400);
  echo json_encode(['error' => 'app_mismatch']);
  exit;
}

$appSettings = $appsConfig[$app_id] ?? null;
if (!$appSettings) {
  error_log('[fb_deletion] Unknown app_id: '.$app_id);
  http_response_code(400);
  echo json_encode(['error' => 'unknown_app', 'app_id' => $app_id]);
  exit;
}

$appSecret = $appSettings['secret'] ?? null;
if (!$appSecret) {
  http_response_code(400);
  echo json_encode(['error' => 'missing_app_secret']);
  exit;
}
$appName = $appSettings['name'] ?? $appSettings['slug'] ?? $app_id;

// Verify signature now that we know which secret to use
$expected_sig = hash_hmac('sha256', $payload, $appSecret, true);
$decoded_sig  = base64_url_decode($encoded_sig);
if (!hash_equals($decoded_sig, $expected_sig)) {
  http_response_code(400);
  echo json_encode(['error' => 'bad_signature']);
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
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'example.com');
$appSlug = fb_app_slug((string)$app_id);
$status_url = sprintf(
  'https://%s/fb_deletion/status.php?app=%s&id=%s',
  $host,
  rawurlencode($appSlug),
  rawurlencode($confirmation_code)
);
try {
  $stmt = $conn->prepare("INSERT INTO deletion_requests (confirmation_code, user_id, app_id, app_name, status) VALUES (?, ?, ?, ?, 'queued')");
  if (!$stmt) throw new Exception('Prepare failed: '.$conn->error);
  $stmt->bind_param("ssss", $confirmation_code, $user_id, $app_id, $appName);
  if (!$stmt->execute()) throw new Exception('Execute failed: '.$stmt->error);
  $stmt->close();
} catch (Throwable $e) {
  error_log('[fb_deletion] Insert failed: '.$e->getMessage());
  throw $e;
}

// Kick off deletion (sync demo; replace with background job if needed)
$upd = $conn->prepare("UPDATE deletion_requests SET status='deleted' WHERE confirmation_code=?");
$upd->bind_param("s", $confirmation_code);
$upd->execute();
$upd->close();

// Respond per Facebook spec
echo json_encode([
  'url' => $status_url,
  'confirmation_code' => $confirmation_code,
  'app_id' => $app_id,
  'app_slug' => $appSlug,
  'app_name' => $appName
]);
