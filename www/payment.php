<?php
/**
 * payment.php
 * Point of Creation — интеграция ЮKassa (embedded виджет)
 *
 * Переменные окружения:
 *   YUKASSA_SHOP_ID  — ID магазина
 *   YUKASSA_SECRET   — Секретный ключ (test_... для тестового режима)
 *   APP_URL          — Базовый URL (для return_url)
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/billing_core.php';

define('YUKASSA_SHOP_ID', getenv('YUKASSA_SHOP_ID') ?: '');
define('YUKASSA_SECRET', getenv('YUKASSA_SECRET') ?: '');
define('APP_URL', rtrim(getenv('APP_URL') ?: 'http://localhost', '/'));

// ── API-запрос к ЮKassa ───────────────────────────────────────

function yukassa_request(string $method, string $endpoint, array $data = [], string $idempotency_key = ''): array
{
  if (!YUKASSA_SHOP_ID || !YUKASSA_SECRET) {
    return ['_error' => 'not_configured'];
  }

  $url = 'https://api.yookassa.ru/v3/' . $endpoint;
  $ch = curl_init($url);

  $headers = ['Content-Type: application/json'];
  if ($idempotency_key)
    $headers[] = 'Idempotence-Key: ' . $idempotency_key;

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_USERPWD => YUKASSA_SHOP_ID . ':' . YUKASSA_SECRET,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
  ]);

  if ($data)
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));

  $response = curl_exec($ch);
  $errno = curl_errno($ch);
  $error = curl_error($ch);
  curl_close($ch);

  if ($errno)
    return ['_error' => 'curl', '_msg' => $error];

  $decoded = json_decode($response, true);
  return $decoded ?: ['_error' => 'invalid_json', '_raw' => $response];
}

// ── Роутер ────────────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── action=token — создать платёж и вернуть confirmation_token ─
if ($action === 'token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  require_auth();

  $raw = json_decode(file_get_contents('php://input'), true) ?? [];
  $csrf = $raw['csrf'] ?? $_POST['csrf'] ?? '';
  if (!verify_csrf($csrf)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']);
    exit;
  }

  $amount = round((float) ($raw['amount'] ?? 0), 2);
  $user_id = (int) current_user()['id'];

  if ($amount < 1) {
    echo json_encode(['ok' => false, 'error' => 'Минимальная сумма: 1 ₽']);
    exit;
  }
  if ($amount > 100000) {
    echo json_encode(['ok' => false, 'error' => 'Максимальная сумма: 100 000 ₽']);
    exit;
  }

  $return_url = APP_URL . '/billing.php?msg=payment_success';

  $result = yukassa_request('POST', 'payments', [
    'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'RUB'],
    'capture' => true,
    'confirmation' => ['type' => 'embedded'],
    'description' => "Пополнение счёта Point of Creation на {$amount} ₽",
    'metadata' => ['user_id' => $user_id, 'type' => 'topup'],
  ], 'poc-' . $user_id . '-' . time());

  if (isset($result['_error'])) {
    $msg = $result['_error'] === 'not_configured'
      ? 'ЮKassa не настроена (задайте YUKASSA_SHOP_ID и YUKASSA_SECRET)'
      : 'Ошибка соединения с ЮKassa: ' . ($result['_msg'] ?? $result['_error']);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
  }

  if (!isset($result['id'])) {
    echo json_encode(['ok' => false, 'error' => $result['description'] ?? 'Ошибка ЮKassa', '_debug' => $result]);
    exit;
  }

  // Сохранить платёж в БД
  try {
    $db = get_db();
    $db->prepare("INSERT INTO `payments` (`user_id`,`payment_id`,`amount`,`status`,`description`) VALUES (?,?,?,'pending',?)")
      ->execute([$user_id, $result['id'], $amount, "Пополнение через ЮKassa"]);
  } catch (Throwable $e) {
  }

  $token = $result['confirmation']['confirmation_token'] ?? '';
  echo json_encode([
    'ok' => true,
    'token' => $token,
    'payment_id' => $result['id'],
    'return_url' => $return_url,
  ]);
  exit;
}

// ── action=webhook — ЮKassa уведомляет о статусе платежа ──────
if ($action === 'webhook') {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);

  if (!$data || !isset($data['event'], $data['object'])) {
    http_response_code(400);
    exit;
  }

  if ($data['event'] !== 'payment.succeeded') {
    http_response_code(200);
    exit;
  }

  $payment = $data['object'];
  $payment_id = $payment['id'];
  $amount = (float) ($payment['amount']['value'] ?? 0);
  $user_id = (int) ($payment['metadata']['user_id'] ?? 0);

  if (!$user_id || !$payment_id) {
    http_response_code(400);
    exit;
  }

  $db = get_db();
  $stmt = $db->prepare("SELECT `status` FROM `payments` WHERE `payment_id` = ?");
  $stmt->execute([$payment_id]);
  $row = $stmt->fetch();

  if (!$row || $row['status'] === 'succeeded') {
    http_response_code(200);
    exit;
  }

  $db->beginTransaction();
  try {
    $db->prepare("UPDATE `payments` SET `status`='succeeded' WHERE `payment_id`=?")->execute([$payment_id]);
    topup_wallet($user_id, $amount, "Пополнение через ЮKassa (#{$payment_id})");
    $db->commit();
    http_response_code(200);
  } catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
  }
  exit;
}

// ── action=check — проверить статус платежа (резервный путь) ──
if ($action === 'check' && isset($_GET['payment_id'])) {
  header('Content-Type: application/json; charset=utf-8');
  require_auth();

  $payment_id = $_GET['payment_id'];
  $result = yukassa_request('GET', 'payments/' . $payment_id);
  $status = $result['status'] ?? 'unknown';
  $user_id = (int) current_user()['id'];

  if ($status === 'succeeded') {
    $db = get_db();
    $stmt = $db->prepare("SELECT `status` FROM `payments` WHERE `payment_id` = ? AND `user_id` = ?");
    $stmt->execute([$payment_id, $user_id]);
    $row = $stmt->fetch();

    if ($row && $row['status'] !== 'succeeded') {
      $amount = (float) ($result['amount']['value'] ?? 0);
      $db->prepare("UPDATE `payments` SET `status`='succeeded' WHERE `payment_id`=?")->execute([$payment_id]);
      topup_wallet($user_id, $amount, "Пополнение через ЮKassa (#{$payment_id})");
    }
    echo json_encode(['ok' => true, 'status' => 'succeeded']);
  } else {
    echo json_encode(['ok' => true, 'status' => $status]);
  }
  exit;
}

http_response_code(404);
echo 'Not found';