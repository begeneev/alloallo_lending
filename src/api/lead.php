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
  // Один номер — одна заявка в сутки. Повторная отправка ведёт на ту же страницу звонка, без новой сделки.
  // Если с номера уже поговорили и включено one_call_per_day, второй звонок не даём.
  allo_update('phones', $pkey, function ($saved) use (&$result, $cfg, $phone, $name, $site, $in, $utm, $now) {
    if ($saved && !empty($saved['code'])) {
      $rec = allo_read('codes', $saved['code']);
      if ($rec) {
        if ($cfg['one_call_per_day'] && !empty($rec['calls'])) { $result = ['ok' => false, 'error' => 'already_called']; return null; }
        $result = ['ok' => true, 'code' => $saved['code'], 'repeat' => true];
        return null;
      }
    }
    $code = bin2hex(random_bytes(16));
    $rec = [
      'phone' => $phone, 'name' => $name, 'site' => $site,
      'task' => allo_str($in['task'] ?? '', 100) ?: 'Разговор с агентом и демонстрация',
      'industry' => allo_str($in['industry'] ?? '', 100),
      'page' => allo_str($in['page'] ?? '', 300),
      'utm' => $utm, 'created' => $now, 'created_text' => allo_today_text() . ' МСК',
      'deal' => null, 'bitrix_error' => null, 'calls' => [], 'expires' => $now + ALLO_DAY,
    ];
    // Сделка сразу после формы, копия заявки — на почту (mail_mode = always) или только при сбое Битрикса
    // (fallback). Битрикс не настроен или не ответил — письмо помечено «внесите вручную», а сделку сервер
    // попробует создать ещё раз после разговора (call-event.php).
    // Заявка не дошла ни в Битрикс, ни на почту — звонок не открываем, форма покажет ошибку: заявка не теряется молча.
    if (allo_bitrix_on()) {
      try { $rec['deal'] = allo_create_deal($rec); }
      catch (Throwable $e) { $rec['bitrix_error'] = allo_safe_error($e->getMessage()); allo_log('lead_bitrix_failed', ['error' => $rec['bitrix_error']]); }
    }
    $rec['mailed'] = false;
    if (!$rec['deal']) {
      $why = allo_bitrix_on() ? 'Битрикс24 не ответил (' . $rec['bitrix_error'] . ')' : 'Битрикс24 не подключён';
      $rec['mailed'] = allo_lead_mail($rec, $why);
      if (!$rec['mailed']) {
        allo_log('lead_not_delivered', ['bitrix' => $rec['bitrix_error'] ?: 'not_configured']);
        $result = ['ok' => false, 'error' => 'not_delivered'];
        return null;
      }
    } elseif ($cfg['mail_mode'] === 'always') {
      allo_lead_mail($rec, ''); // копия; не ушла — не страшно, заявка уже в Битриксе
    }
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
