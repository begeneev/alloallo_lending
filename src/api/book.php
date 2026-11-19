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

// День — рабочий, от сегодня до трёх недель вперёд (по поясу из настроек); время — один из интервалов.
$slot = (string)($in['slot'] ?? '');
$date = (string)($in['date'] ?? '');
$tz = new DateTimeZone($cfg['timezone'] ?: 'Europe/Moscow');
$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? DateTime::createFromFormat('!Y-m-d', $date, $tz) : false;
$today = new DateTime('today', $tz);
$ahead = $day ? (int)$today->diff($day)->format('%r%a') : -1;
if (!isset(ALLO_BOOK_SLOTS[$slot]) || !$day || $day->format('Y-m-d') !== $date || $ahead < 0 || $ahead > ALLO_BOOK_DAYS_AHEAD || (int)$day->format('N') > 5) {
  allo_json(['ok' => false, 'error' => 'slot'], 400);
}
$days = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
$months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$slotText = $days[(int)$day->format('w')] . ', ' . (int)$day->format('j') . ' ' . $months[(int)$day->format('n') - 1] . ', ' . ALLO_BOOK_SLOTS[$slot] . ' МСК';

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
      allo_log('book', ['deal' => $rec['deal'], 'slot' => $slotText]);
    }
    $result = ['ok' => true, 'slot_text' => $slotText];
    return ['deal' => $rec['deal'], 'expires' => $now + ALLO_DAY];
  });
} catch (Throwable $e) {
  allo_log('book_failed', ['error' => $e->getMessage()]);
  allo_json(['ok' => false, 'error' => 'server'], 500);
}

if (!$result['ok']) allo_json($result, 503);
allo_json($result);
