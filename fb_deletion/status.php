<?php
declare(strict_types=1);

// Return JSON if requested, otherwise HTML for humans
function wants_json(): bool {
  if (isset($_GET['format']) && $_GET['format'] === 'json') return true;
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  return stripos($accept, 'application/json') !== false;
}

try {
  // Make mysqli throw exceptions so we never silently fail
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  require_once __DIR__ . '/db.php';

  $id = $_GET['id'] ?? '';
  if ($id === '' || !preg_match('/^[a-f0-9]{8,64}$/i', $id)) {
    if (wants_json()) {
      header('Content-Type: application/json'); http_response_code(400);
      echo json_encode(['error' => 'missing_or_invalid_id']); exit;
    } else {
      http_response_code(400);
      echo "<!doctype html><meta charset='utf-8'><h1>Missing or Invalid ID</h1><p>The confirmation code (?id=) is required and must be a hex string.</p>"; exit;
    }
  }

  // ---- First query: only guaranteed columns (works on any schema)
  $stmt = $conn->prepare("SELECT user_id, status FROM deletion_requests WHERE confirmation_code = ? LIMIT 1");
  $stmt->bind_param("s", $id);
  $stmt->execute();
  $stmt->store_result();

  if ($stmt->num_rows === 0) {
    if (wants_json()) {
      header('Content-Type: application/json'); http_response_code(404);
      echo json_encode(['error' => 'not_found', 'confirmation_code' => $id]); exit;
    } else {
      http_response_code(404);
      echo "<!doctype html><meta charset='utf-8'><h1>Request Not Found</h1><p>No deletion request matches this confirmation code.</p>"; exit;
    }
  }

  $stmt->bind_result($user_id, $status);
  $stmt->fetch();
  $stmt->close();

  // Normalize empty values to something readable
  $user_id = isset($user_id) && $user_id !== '' ? $user_id : null;
  $status  = isset($status)  && $status  !== '' ? $status  : null;

  // ---- Optional: try to read timestamps if those columns exist
  $createdAt = null; $updatedAt = null;
  try {
    // Check if columns exist quickly (no fatal if they don't)
    $hasCreated = false; $hasUpdated = false;

    $res = $conn->query("SHOW COLUMNS FROM deletion_requests LIKE 'created_at'");
    if ($res && $res->num_rows > 0) $hasCreated = true;

    $res = $conn->query("SHOW COLUMNS FROM deletion_requests LIKE 'updated_at'");
    if ($res && $res->num_rows > 0) $hasUpdated = true;

    if ($hasCreated || $hasUpdated) {
      // Build a query only for existing cols
      $selectFields = [];
      if ($hasCreated) $selectFields[] = "created_at";
      if ($hasUpdated) $selectFields[] = "updated_at";
      $cols = implode(", ", $selectFields);

      $q = $conn->prepare("SELECT $cols FROM deletion_requests WHERE confirmation_code = ? LIMIT 1");
      $q->bind_param("s", $id);
      $q->execute();
      $q->store_result();

      if ($q->num_rows > 0) {
        if ($hasCreated && $hasUpdated) {
          $q->bind_result($createdAt, $updatedAt);
        } elseif ($hasCreated) {
          $q->bind_result($createdAt);
        } elseif ($hasUpdated) {
          $q->bind_result($updatedAt);
        }
        $q->fetch();
      }
      $q->close();
    }
  } catch (Throwable $ignored) {
    // If anything about optional cols fails, we just omit them—no crash.
  }

  if (wants_json()) {
    header('Content-Type: application/json');
    $out = [
      'confirmation_code' => $id,
      'user_id'           => $user_id,
      'status'            => $status
    ];
    if ($createdAt !== null) $out['created_at'] = $createdAt;
    if ($updatedAt !== null) $out['updated_at'] = $updatedAt;
    echo json_encode($out); exit;
  }

  // Simple HTML output
  ?>
  <!doctype html>
  <meta charset="utf-8">
  <title>Deletion Status</title>
  <h1>Facebook Data Deletion</h1>
  <p><b>Confirmation Code:</b> <?= htmlspecialchars($id, ENT_QUOTES) ?></p>
  <p><b>User ID:</b> <?= htmlspecialchars($user_id ?? '—', ENT_QUOTES) ?></p>
  <p><b>Status:</b> <?= htmlspecialchars($status ?? '—', ENT_QUOTES) ?></p>
  <?php if ($createdAt !== null): ?>
    <p><b>Requested:</b> <?= htmlspecialchars($createdAt, ENT_QUOTES) ?></p>
  <?php endif; ?>
  <?php if ($updatedAt !== null): ?>
    <p><b>Updated:</b> <?= htmlspecialchars($updatedAt, ENT_QUOTES) ?></p>
  <?php endif; ?>
  <!--<p style="font-size:12px;color:#666">Need JSON? Add <code>?format=json</code>.</p>-->
  <?php

} catch (Throwable $e) {
  error_log('[status.php] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
  if (wants_json()) {
    header('Content-Type: application/json'); http_response_code(500);
    echo json_encode(['error' => 'server_error']);
  } else {
    http_response_code(500);
    echo "<!doctype html><meta charset='utf-8'><h1>Server Error</h1><p>There was a problem loading this request.</p>";
  }
}
