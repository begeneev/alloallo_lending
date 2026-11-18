<?php
// Заявка с демо-страницы /demo/: имя и телефон перед звонком демо-агенту. Для каждого агента — своя заявка
// (свой одноразовый код и свой звонок, один разговор с агентом в сутки), а сделка в Битрикс24 на номер одна:
// «Аллоалло-Демо: Имя». Первая заявка с номера заводит её, заявки другим агентам пишут в неё комментарий.
// Стадии те же, что у заявки с главной: «Новый лид» → «Начал звонок» → «Созвонился с агентом» (call-event.php).
require __DIR__ . '/_lib.php';

allo_only_post();
allo_check_origin();
$cfg = allo_config();
$in = allo_body();

// Ловушка для ботов: поле скрыто от людей, заполняют его только скрипты.
if (!empty($in['company_extra'])) allo_json(['ok' => false, 'error' => 'invalid'], 400);

$agent = (string)($in['agent'] ?? '');
if (!isset(ALLO_DEMO_AGENTS[$agent])) allo_json(['ok' => false, 'error' => 'agent'], 400);
$phone = allo_phone($in['phone'] ?? '');
$name = allo_str($in['name'] ?? '', 100);
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
$title = ALLO_DEMO_AGENTS[$agent];
$result = null;

try {
  // Запись номера: сделка на номер и коды заявок по агентам. Живёт сутки с первой демо-заявки.
  allo_update('demo', allo_phone_key($phone), function ($saved) use (&$result, $cfg, $agent, $title, $phone, $name, $in, $utm, $now) {
    $saved = $saved ?: ['deal' => null, 'agents' => [], 'expires' => $now + ALLO_DAY];

    // Этому агенту с номера уже оставляли контакты: тот же код, новую заявку не заводим.
    $old = $saved['agents'][$agent] ?? '';
    $rec = $old ? allo_read('codes', $old) : null;
    if ($rec) {
      $used = $cfg['one_call_per_day'] && !empty($rec['calls']);
      allo_log('demo_repeat', ['code' => substr($old, 0, 8), 'agent' => $agent, 'deal' => $rec['deal'], 'called' => !empty($rec['calls'])]);
      $result = $used ? ['ok' => true, 'used' => true] : ['ok' => true, 'code' => $old, 'repeat' => true, 'context' => allo_call_context($rec)];
      return null;
    }

    $code = bin2hex(random_bytes(16));
    $rec = allo_lead_record($phone, $name, '', $in, $utm, 'Демо-агент: ' . $title);
    $rec['demo'] = $agent;
    if ($saved['deal']) {
      // Сделка на номер уже есть (звонил другому демо-агенту): отмечаем в ней новую заявку.
      $rec['deal'] = $saved['deal'];
      $rec['mailed'] = false;
      try { allo_deal_comment($rec['deal'], allo_lines([['Заявка на демо-звонок', $title], ['Имя', $name], ['Телефон', '+' . $phone], ['Согласие на обработку ПД', 'да, ' . $rec['created_text']]])); }
      catch (Throwable $e) { allo_log('demo_comment_failed', ['error' => allo_safe_error($e->getMessage())]); }
      if ($cfg['mail_mode'] === 'always') allo_lead_mail($rec, '');
    } elseif (!allo_deliver_lead($rec)) {
      $result = ['ok' => false, 'error' => 'not_delivered'];
      return null;
    }
    allo_write('codes', $code, $rec);
    $saved['agents'][$agent] = $code;
    if (!$saved['deal'] && $rec['deal']) $saved['deal'] = $rec['deal'];
    allo_log('demo_lead', ['code' => substr($code, 0, 8), 'agent' => $agent, 'deal' => $rec['deal']]);
    $result = ['ok' => true, 'code' => $code, 'context' => allo_call_context($rec)];
    return $saved;
  });
} catch (Throwable $e) {
  allo_log('demo_lead_failed', ['error' => $e->getMessage()]);
  allo_json(['ok' => false, 'error' => 'server'], 500);
}

if (!$result['ok']) allo_json($result, $result['error'] === 'not_delivered' ? 503 : 409);
$result['repeat_call'] = !$cfg['one_call_per_day']; // можно ли звонить агенту ещё раз после разговора
allo_json($result);
