<?php
/**
 * payment.php
 * Point of Creation — интеграция ЮKassa (тестовый режим)
 *
 * Документация: https://yookassa.ru/developers/api
 * Тестовые карты: https://yookassa.ru/developers/payment-acceptance/testing
 *
 * Переменные окружения (docker-compose.yml):
 *   YUKASSA_SHOP_ID   — ID магазина (из личного кабинета ЮKassa)
 *   YUKASSA_SECRET    — Секретный ключ (начинается с test_... в тестовом режиме)
 *   APP_URL           — Базовый URL приложения (например https://yourdomain.com)
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/billing_core.php';

define('YUKASSA_SHOP_ID', getenv('YUKASSA_SHOP_ID') ?: '');
define('YUKASSA_SECRET', getenv('YUKASSA_SECRET') ?: '');
define('APP_URL', rtrim(getenv('APP_URL') ?: 'http://localhost:8080', '/'));

// ══════════════════════════════════════════════════════════════
//  API-обёртка ЮKassa
// ══════════════════════════════════════════════════════════════

function yukassa_request(string $method, string $endpoint, array $data = [], string $idempotency_key = ''): array
{
  $url = 'https://api.yookassa.ru/v3/' . $endpoint;
  $ch = curl_init($url);

  $headers = ['Content-Type: application/json'];
  if ($idempotency_key) {
    $headers[] = 'Idempotency-Key: ' . $idempotency_key;
  }

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_USERPWD => YUKASSA_SHOP_ID . ':' . YUKASSA_SECRET,
    CURLOPT_TIMEOUT => 30,
  ]);

  if ($data) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  }

  $response = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($response === false) {
    return ['error' => 'Ошибка соединения с ЮKassa'];
  }

  $decoded = json_decode($response, true);
  return $decoded ?: ['error' => 'Некорректный ответ ЮKassa'];
}

// ══════════════════════════════════════════════════════════════
//  Создать платёж
// ══════════════════════════════════════════════════════════════

function create_payment(int $user_id, float $amount, string $description, string $return_url): array
{
  if (!YUKASSA_SHOP_ID || !YUKASSA_SECRET) {
    return ['ok' => false, 'error' => 'ЮKassa не настроена. Задайте YUKASSA_SHOP_ID и YUKASSA_SECRET.'];
  }

  if ($amount < 1) {
    return ['ok' => false, 'error' => 'Минимальная сумма платежа: 1 ₽'];
  }

  $idempotency_key = 'poc-' . $user_id . '-' . time();

  $result = yukassa_request('POST', 'payments', [
    'amount' => [
      'value' => number_format($amount, 2, '.', ''),
      'currency' => 'RUB',
    ],
    'capture' => true,
    'confirmation' => [
      'type' => 'redirect',
      'return_url' => $return_url,
    ],
    'description' => $description,
    'metadata' => [
      'user_id' => $user_id,
      'type' => 'topup',
    ],
  ], $idempotency_key);

  if (isset($result['id'])) {
    // Сохранить платёж в БД
    $db = get_db();
    $db->prepare("
            INSERT INTO `payments`
                (`user_id`, `payment_id`, `amount`, `status`, `description`)
            VALUES (?, ?, ?, 'pending', ?)
        ")->execute([$user_id, $result['id'], $amount, $description]);

    // ЮKassa может вернуть confirmation_url в разных полях в зависимости от типа
    $conf_url = $result['confirmation']['confirmation_url']
      ?? $result['confirmation']['confirm_url']
      ?? '';

    return [
      'ok' => true,
      'payment_id' => $result['id'],
      'confirmation_url' => $conf_url,
      '_debug_full' => $result, // полный ответ для отладки
    ];
  }

  return [
    'ok' => false,
    'error' => $result['description'] ?? ($result['message'] ?? 'Ошибка создания платежа'),
    '_debug' => $result, // временно для отладки
  ];
}

// ══════════════════════════════════════════════════════════════
//  Webhook — ЮKassa уведомляет нас о статусе платежа
//  URL: POST /payment.php?action=webhook
// ══════════════════════════════════════════════════════════════

function handle_webhook(): void
{
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);

  if (!$data || !isset($data['event'], $data['object'])) {
    http_response_code(400);
    exit;
  }

  $event = $data['event'];
  $payment = $data['object'];

  if ($event !== 'payment.succeeded') {
    http_response_code(200);
    exit; // Остальные события игнорируем
  }

  $payment_id = $payment['id'];
  $amount = (float) ($payment['amount']['value'] ?? 0);
  $meta = $payment['metadata'] ?? [];
  $user_id = (int) ($meta['user_id'] ?? 0);

  if (!$user_id || !$payment_id) {
    http_response_code(400);
    exit;
  }

  $db = get_db();

  // Проверить что платёж ещё не обработан
  $stmt = $db->prepare("SELECT `status` FROM `payments` WHERE `payment_id` = ?");
  $stmt->execute([$payment_id]);
  $row = $stmt->fetch();

  if (!$row || $row['status'] === 'succeeded') {
    http_response_code(200);
    exit; // Уже обработан или не найден
  }

  $db->beginTransaction();
  try {
    // Обновить статус платежа
    $db->prepare("UPDATE `payments` SET `status`='succeeded' WHERE `payment_id`=?")
      ->execute([$payment_id]);

    // Пополнить кошелёк
    topup_wallet($user_id, $amount, "Пополнение через ЮKassa (#{$payment_id})");

    $db->commit();
    http_response_code(200);
  } catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
  }
  exit;
}

// ══════════════════════════════════════════════════════════════
//  Обработка return_url — пользователь вернулся с оплаты
//  URL: GET /payment.php?action=return&payment_id=...
// ══════════════════════════════════════════════════════════════

function handle_return(): void
{
  require_auth();
  $payment_id = $_GET['payment_id'] ?? '';

  if (!$payment_id) {
    header('Location: /billing.php?msg=payment_error');
    exit;
  }

  // Проверить статус платежа через API
  $result = yukassa_request('GET', 'payments/' . $payment_id);

  if (($result['status'] ?? '') === 'succeeded') {
    // Платёж может уже быть обработан вебхуком — проверим
    $db = get_db();
    $stmt = $db->prepare("SELECT `status` FROM `payments` WHERE `payment_id` = ?");
    $stmt->execute([$payment_id]);
    $row = $stmt->fetch();

    if ($row && $row['status'] !== 'succeeded') {
      // Обработать если вебхук не пришёл (резервный путь)
      $amount = (float) ($result['amount']['value'] ?? 0);
      $user_id = (int) (current_user()['id'] ?? 0);
      $db->prepare("UPDATE `payments` SET `status`='succeeded' WHERE `payment_id`=?")->execute([$payment_id]);
      topup_wallet($user_id, $amount, "Пополнение через ЮKassa (#{$payment_id})");
    }
    header('Location: /billing.php?msg=payment_success');
  } else {
    header('Location: /billing.php?msg=payment_failed');
  }
  exit;
}

// ══════════════════════════════════════════════════════════════
//  Роутер
// ══════════════════════════════════════════════════════════════

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'webhook') {
  handle_webhook();
} elseif ($action === 'return') {
  handle_return();
} elseif ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  require_auth();
  header('Content-Type: application/json');

  if (!verify_csrf($_POST['csrf'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']);
    exit;
  }

  $amount = (float) ($_POST['amount'] ?? 0);
  $user_id = (int) (current_user()['id'] ?? 0);
  // ЮKassa требует публичный HTTPS URL (не localhost)
  // Если APP_URL = localhost — предупредить пользователя
  if (strpos(APP_URL, 'localhost') !== false || strpos(APP_URL, '127.0.0.1') !== false) {
    echo json_encode([
      'ok' => false,
      'error' => 'ЮKassa не поддерживает localhost. Задайте публичный URL в APP_URL (docker-compose.yml). Для тестирования используйте ngrok: https://ngrok.com',
    ]);
    exit;
  }
  $return_url = APP_URL . '/payment.php?action=return';

  $result = create_payment($user_id, $amount, "Пополнение счёта Point of Creation", $return_url);

  if ($result['ok'] && !empty($result['confirmation_url'])) {
    // Редиректить на страницу оплаты ЮKassa
    header('Location: ' . $result['confirmation_url']);
    exit;
  }

  echo json_encode($result);
} else {
  http_response_code(404);
  echo 'Not found';
}