<?php
// Запись на демонстрацию (кнопка «Записаться на демонстрацию» на главной): имя, телефон, сайт, удобный
// день и время → сделка в Битрикс24 «Аллоалло-Демонстрация: Имя» сразу на стадии bitrix_stage_booked
// («Назначена консультация»), копия на почту. Звонка агенту здесь нет: время подтверждает менеджер.
// Если за последние сутки с этого номера уже была заявка (главная, /demo/ или прошлая запись) — новую сделку
// не заводим: в ту же сделку пишем комментарий с выбранным временем и переводим её на эту стадию (только вперёд).
// Не вышло обновить ту сделку (удалили, нет доступа) — заводим новую.
require __DIR__ . '/_lib.php';

const ALLO_BOOK_SLOTS = ['10-12' => '10:00–12:00', '12-14' => '12:00–14:00', '14-16' => '14:00–16:00', '16-18' => '16:00–18:00'];
const ALLO_BOOK_DAYS_AHEAD = 21;

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

// Время: начало встречи из Google Календаря менеджера (start, ISO; окно берёт свободные часы у /api/slots.php)
// или, если календарь не ответил, — рабочий день до трёх недель вперёд и интервал (по поясу из настроек).
$slot = (string)($in['slot'] ?? '');
$date = (string)($in['date'] ?? '');
$start = (string)($in['start'] ?? '');
$tz = new DateTimeZone($cfg['timezone'] ?: 'Europe/Moscow');
$days = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
$months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$startTs = null;
if ($start !== '') {
  $startTs = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:00:00(\.000)?Z$/', $start) ? strtotime($start) : false;
  if (!$startTs || $startTs < time() + 1800 || $startTs > time() + ALLO_BOOK_DAYS_AHEAD * ALLO_DAY) allo_json(['ok' => false, 'error' => 'slot'], 400);
  $at = (new DateTime('@' . $startTs))->setTimezone($tz);
  $slotText = $days[(int)$at->format('w')] . ', ' . (int)$at->format('j') . ' ' . $months[(int)$at->format('n') - 1] . ', ' . $at->format('H:i') . ' МСК';
} else {
  $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? DateTime::createFromFormat('!Y-m-d', $date, $tz) : false;
  $today = new DateTime('today', $tz);
  $ahead = $day ? (int)$today->diff($day)->format('%r%a') : -1;
  if (!isset(ALLO_BOOK_SLOTS[$slot]) || !$day || $day->format('Y-m-d') !== $date || $ahead < 0 || $ahead > ALLO_BOOK_DAYS_AHEAD || (int)$day->format('N') > 5) {
    allo_json(['ok' => false, 'error' => 'slot'], 400);
  }
  $slotText = $days[(int)$day->format('w')] . ', ' . (int)$day->format('j') . ' ' . $months[(int)$day->format('n') - 1] . ', ' . ALLO_BOOK_SLOTS[$slot] . ' МСК';
}

// Встреча в Google Календарь менеджера: сервис nextbot-demo проверяет по Битриксу, что сделку только что изменили,
// берёт имя и телефон из её контакта и ставит встречу. Не вышло — запись всё равно есть в сделке, время подтвердит менеджер.
function allo_calendar_book($deal, $startTs) {
  $cfg = allo_config();
  $url = $cfg['calendar_book_url'] ?? 'https://nextbot-demo.i-am-ivan-perov.workers.dev/site-book';
  if (!$url || !$deal) return false;
  $body = json_encode(['deal' => (string)$deal, 'start' => gmdate('Y-m-d\TH:i:s\Z', $startTs)]);
  $raw = false;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12]);
    $raw = curl_exec($ch);
    curl_close($ch);
  } else {
    $raw = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n",
      'content' => $body, 'timeout' => 12, 'ignore_errors' => true]]));
  }
  $j = is_string($raw) ? json_decode($raw, true) : null;
  $ok = is_array($j) && !empty($j['ok']);
  allo_log($ok ? 'book_calendar' : 'book_calendar_failed', ['deal' => $deal, 'error' => is_array($j) ? ($j['error'] ?? '') : 'no_response']);
  return $ok;
}

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
  allo_update('book', $pkey, function ($saved) use (&$result, $cfg, $pkey, $phone, $name, $site, $in, $utm, $now, $slotText) {
    $rec = allo_lead_record($phone, $name, $site, $in, $utm, allo_str($in['task'] ?? '', 100) ?: 'Запись на демонстрацию');
    $rec['booking'] = true;
    $rec['slot_text'] = $slotText;

    // Сделка с этого номера за последние сутки: прошлая запись, заявка с главной или с /demo/.
    $deal = $saved['deal'] ?? null;
    if (!$deal) {
      $ph = allo_read('phones', $pkey);
      $old = !empty($ph['code']) ? allo_read('codes', $ph['code']) : null;
      $deal = $old['deal'] ?? null;
    }
    if (!$deal) $deal = allo_read('demo', $pkey)['deal'] ?? null;

    // Сделку могли удалить или перенести так, что вебхук её не видит: тогда заводим новую, иначе запись потеряется.
    $updated = false;
    if ($deal) {
      try {
        $stage = allo_move_deal($deal, 'booked')[0];
        allo_deal_comment($deal, allo_lines([
          ['Запись на демонстрацию', $slotText], ['Имя', $name], ['Телефон', '+' . $phone], ['Сайт компании', $site],
          ['Кнопка на сайте', $rec['task']], ['Страница', $rec['page']],
          ['Согласие на обработку ПД', 'да, ' . $rec['created_text']],
        ]));
        $updated = true;
        $rec['deal'] = $deal;
        $rec['mailed'] = false;
        if ($cfg['mail_mode'] === 'always') allo_lead_mail($rec, '');
        allo_log('book_repeat', ['deal' => $deal, 'stage' => $stage, 'slot' => $slotText]);
      } catch (Throwable $e) {
        allo_log('book_update_failed', ['deal' => $deal, 'error' => allo_safe_error($e->getMessage())]);
      }
    }
    if (!$updated) {
      if (!allo_deliver_lead($rec)) { $result = ['ok' => false, 'error' => 'not_delivered']; return null; }
      allo_write_link_record($rec); // ссылка из сообщения WhatsApp откроет звонок агенту Аллоалло с этой сделкой
      allo_log('book', ['deal' => $rec['deal'], 'slot' => $slotText]);
    }
    $result = ['ok' => true, 'slot_text' => $slotText, 'deal' => $rec['deal']];
    return ['deal' => $rec['deal'], 'expires' => $now + ALLO_DAY];
  });
} catch (Throwable $e) {
  allo_log('book_failed', ['error' => $e->getMessage()]);
  allo_json(['ok' => false, 'error' => 'server'], 500);
}

if (!$result['ok']) allo_json($result, 503);
$calendar = $startTs ? allo_calendar_book($result['deal'], $startTs) : false;
allo_json(['ok' => true, 'slot_text' => $result['slot_text'], 'calendar' => $calendar]);
