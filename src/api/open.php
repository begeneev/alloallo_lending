<?php
// Страница звонка по номеру телефона: /call/?phone=<номер> — ссылка из сообщения в WhatsApp после заявки.
// Номер может прийти из Битрикс24 как угодно: «+7 (777) 123-45-67», «8 777 1234567», «7771234567» без +7.
// Оставляем цифры и приводим к виду 7XXXXXXXXXX, как номер из формы. Дальше:
// 1) заявка с этого номера за сутки уже есть — отдаём её код (та же сделка, тот же контекст звонка);
// 2) заявки нет (прошли сутки или номер пришёл не с формы) — заводим код заново и ищем открытую сделку
//    контакта с этим номером в воронке лендинга; новую сделку не создаём. Не нашли — сделку создаст
//    call-event.php после разговора, как при сбое Битрикса.
require __DIR__ . '/_lib.php';

allo_only_post();
allo_check_origin();
$in = allo_body();

// Номер из ссылки: только цифры. Несколько номеров через запятую — берём первый.
function allo_link_phone($raw) {
  $first = preg_split('/[,;]/', (string)$raw)[0];
  $d = preg_replace('/\D/', '', $first);
  if (strlen($d) > 2 && substr($d, 0, 2) === '00') $d = substr($d, 2); // 007… — международный префикс
  if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);   // 8 777… → 7 777…
  if (strlen($d) === 10) $d = '7' . $d;                               // 777… без кода страны
  return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : '';
}

// Открытая сделка контакта с этим номером в воронке лендинга и имя контакта. Ничего не нашли — [null, ''].
function allo_find_open_deal($phone) {
  $found = allo_bx('crm.duplicate.findbycomm', ['entity_type' => 'CONTACT', 'type' => 'PHONE', 'values' => ['+' . $phone]]);
  $ids = array_slice(array_map('intval', (array)($found['CONTACT'] ?? [])), 0, 20);
  if (!$ids) return [null, ''];
  $filter = ['CONTACT_ID' => $ids, 'STAGE_SEMANTIC_ID' => 'P'];
  $cat = allo_stages()['category'] ?? null;
  if ($cat !== null && $cat !== '') $filter['CATEGORY_ID'] = (int)$cat;
  $deals = allo_bx('crm.deal.list', ['order' => ['DATE_MODIFY' => 'DESC'], 'filter' => $filter, 'select' => ['ID', 'CONTACT_ID']]);
  $deal = null; $contact = 0;
  foreach ((array)$deals as $d) { $deal = (int)$d['ID']; $contact = (int)$d['CONTACT_ID']; break; }
  if (!$deal) return [null, ''];
  $name = '';
  if ($contact) {
    $list = allo_bx('crm.contact.list', ['filter' => ['ID' => [$contact]], 'select' => ['ID', 'NAME']]);
    foreach ((array)$list as $c) { $name = trim((string)($c['NAME'] ?? '')); break; }
  }
  return [$deal, $name];
}

$phone = allo_link_phone($in['phone'] ?? '');
if (!$phone) allo_json(['ok' => false, 'error' => 'expired'], 404);

$now = time();
$result = null;
$saved = allo_read('phones', allo_phone_key($phone));
if ($saved && !empty($saved['code']) && allo_read('codes', $saved['code'])) allo_json(['ok' => true, 'code' => $saved['code']]);
// Новый код — не чаще лимита формы с одного адреса: чтобы по ссылке не перебирали номера.
allo_rate_limit();
try {
  allo_update('phones', allo_phone_key($phone), function ($saved) use (&$result, $phone, $now) {
    if ($saved && !empty($saved['code']) && allo_read('codes', $saved['code'])) {
      $result = ['ok' => true, 'code' => $saved['code']];
      return null;
    }
    $deal = null; $name = '';
    if (allo_bitrix_on()) {
      try { list($deal, $name) = allo_find_open_deal($phone); }
      catch (Throwable $e) { allo_log('open_bitrix_failed', ['error' => allo_safe_error($e->getMessage())]); }
    }
    $code = bin2hex(random_bytes(16));
    $rec = allo_lead_record($phone, $name, '', ['page' => 'Ссылка из WhatsApp'], [], 'Ссылка на звонок из WhatsApp');
    $rec['deal'] = $deal;
    allo_write('codes', $code, $rec);
    allo_log('open_by_phone', ['code' => substr($code, 0, 8), 'deal' => $deal]);
    $result = ['ok' => true, 'code' => $code];
    return ['code' => $code, 'expires' => $now + ALLO_DAY];
  });
} catch (Throwable $e) {
  allo_log('open_failed', ['error' => $e->getMessage()]);
  allo_json(['ok' => false, 'error' => 'server'], 500);
}

allo_json($result);
