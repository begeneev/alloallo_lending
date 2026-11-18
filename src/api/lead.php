<?php
// Заявка с формы лендинга: проверка → сделка в Битрикс24 (стадия bitrix_stage_new) → одноразовая ссылка
// на страницу звонка /call/#<код>. Код живёт сутки; по нему страница звонка получает телефон для виджета.
require __DIR__ . '/_lib.php';

allo_only_post();
allo_check_origin();
$cfg = allo_config();
$in = allo_body();

// Ловушка для ботов: поле скрыто от людей, заполняют его только скрипты.
if (!empty($in['company_extra'])) allo_json(['ok' => false, 'error' => 'invalid'], 400);

$phone = allo_phone($in['phone'] ?? '');
$name = allo_str($in['name'] ?? '', 100);
$site = allo_str($in['site'] ?? '', 200);
$consent = in_array($in['consent'] ?? '', [true, 'on', '1', 1, 'true'], true);
if (!$phone) allo_json(['ok' => false, 'error' => 'phone'], 400);
if ($name === '') allo_json(['ok' => false, 'error' => 'name'], 400);
if (!$consent) allo_json(['ok' => false, 'error' => 'consent'], 400);

allo_rate_limit();
if (random_int(1, 20) === 1) allo_cleanup();

$utm = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $u) {
  if (!empty($in['utm'][$u])) $utm[$u] = allo_str($in['utm'][$u], 200);
}

$now = time();
$pkey = allo_phone_key($phone);
$result = null;

try {
  // Один номер — одна заявка в сутки. Повторная отправка ведёт на ту же страницу звонка, без новой сделки;
  // страница звонка скажет, что заявка уже была (repeats), а если с номера уже поговорили и включено
  // one_call_per_day — что разговор уже был и повторный будет завтра.
  allo_update('phones', $pkey, function ($saved) use (&$result, $cfg, $phone, $name, $site, $in, $utm, $now) {
    if ($saved && !empty($saved['code'])) {
      $rec = allo_read('codes', $saved['code']);
      if ($rec) {
        allo_update('codes', $saved['code'], function ($r) use ($now) {
          if (!$r) return null;
          $r['repeats'] = ($r['repeats'] ?? 0) + 1;
          $r['repeat_at'] = $now;
          return $r;
        });
        allo_log('lead_repeat', ['code' => substr($saved['code'], 0, 8), 'deal' => $rec['deal'], 'called' => !empty($rec['calls'])]);
        $result = ['ok' => true, 'code' => $saved['code'], 'repeat' => true];
        return null;
      }
    }
    $code = bin2hex(random_bytes(16));
    $rec = allo_lead_record($phone, $name, $site, $in, $utm, allo_str($in['task'] ?? '', 100) ?: 'Разговор с агентом и демонстрация');
    // Сделка сразу после формы, копия — на почту. Битрикс не настроен или не ответил — письмо «внесите вручную»,
    // а сделку сервер попробует создать ещё раз после разговора (call-event.php).
    if (!allo_deliver_lead($rec)) { $result = ['ok' => false, 'error' => 'not_delivered']; return null; }
    allo_write('codes', $code, $rec);
    allo_log('lead', ['code' => substr($code, 0, 8), 'deal' => $rec['deal'], 'task' => $rec['task']]);
    $result = ['ok' => true, 'code' => $code];
    return ['code' => $code, 'expires' => $now + ALLO_DAY];
  });
} catch (Throwable $e) {
  allo_log('lead_failed', ['error' => $e->getMessage()]);
  allo_json(['ok' => false, 'error' => 'server'], 500);
}

if (!$result['ok']) allo_json($result, $result['error'] === 'not_delivered' ? 503 : 409);
allo_json(['ok' => true, 'url' => '/call/#' . $result['code']]);
