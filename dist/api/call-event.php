<?php
// События звонка со страницы /call/:
//   connected — виджет соединился: отмечаем начало разговора (ссылка становится использованной);
//   ended     — положили трубку или закрыли страницу во время разговора: пишем в сделку длительность
//               и переводим её на стадию bitrix_stage_called.
// Длительность считает сервер (от connected до ended), страница присылает свою только для сверки.
// Повторное ended (fetch и sendBeacon при закрытии страницы) ничего не меняет.
require __DIR__ . '/_lib.php';

allo_only_post();
allo_check_origin();
$cfg = allo_config();
$in = allo_body();
$code = $in['code'] ?? '';
$event = $in['event'] ?? '';
if (!allo_valid_code($code) || !in_array($event, ['connected', 'ended'], true)) allo_json(['ok' => false, 'error' => 'invalid'], 400);

$now = time();
$finished = null;
try {
  $rec = allo_update('codes', $code, function ($rec) use ($event, $now, $in, &$finished) {
    if (!$rec) return null;
    $calls = $rec['calls'];
    $last = count($calls) - 1;
    $open = $last >= 0 && empty($calls[$last]['ended']);
    if ($event === 'connected') {
      if ($open) return null; // повтор того же события
      $calls[] = ['started' => $now, 'ended' => null, 'duration' => null];
    } else {
      if (!$open) return null; // разговора не было или он уже закрыт
      $client = isset($in['duration']) ? max(0, min(7200, (int)$in['duration'])) : null;
      $server = max(0, $now - $calls[$last]['started']);
      $calls[$last]['ended'] = $now;
      $calls[$last]['duration'] = $server;
      $calls[$last]['client_duration'] = $client;
      $calls[$last]['reason'] = allo_str($in['reason'] ?? '', 40);
      $finished = $calls[$last];
    }
    $rec['calls'] = $calls;
    // Пока идёт разговор, запись живёт, даже если сутки истекают.
    $rec['expires'] = max($rec['expires'], $now + 3 * 3600);
    return $rec;
  });
} catch (Throwable $e) {
  allo_log('call_event_failed', ['error' => $e->getMessage()]);
  allo_json(['ok' => false, 'error' => 'server'], 500);
}

if ($event === 'connected' || !$finished) allo_json(['ok' => true]);

// Разговор закончился: сделка → стадия «разговор состоялся», в ленту — время и длительность.
$rec = allo_read('codes', $code);
if (!$rec) allo_json(['ok' => true]);
$n = count($rec['calls']);
$summary = [
  ['Разговор с агентом на сайте', $n > 1 ? 'состоялся (звонок ' . $n . ')' : 'состоялся'],
  ['Когда', allo_today_text() . ' МСК'],
  ['Длительность', allo_duration_text($finished['duration'])],
  ['Как закончился', $finished['reason'] === 'page_closed' ? 'страницу закрыли во время разговора' : 'положили трубку'],
  ['Телефон, переданный агенту', '+' . $rec['phone']],
];
$bitrix = 'off';
$created = false;
if (allo_bitrix_on()) {
  try {
    $deal = $rec['deal'];
    if (!$deal) {
      $deal = allo_create_deal($rec);
      $created = true;
      allo_update('codes', $code, function ($r) use ($deal) { if ($r) { $r['deal'] = $deal; $r['bitrix_error'] = null; } return $r; });
    }
    // Стадию двигаем только вперёд: если за время разговора сделку уже перевели дальше (агент записал
    // на демонстрацию, менеджер взял в работу), не возвращаем её на «разговор состоялся».
    $st = allo_stages();
    if ($st['called']) {
      $current = $created ? $st['new'] : (string)(allo_bx('crm.deal.get', ['id' => (int)$deal])['STAGE_ID'] ?? '');
      if (!$st['new'] || $current === $st['new'] || $current === '') {
        if ($current !== $st['called']) allo_bx('crm.deal.update', ['id' => (int)$deal, 'fields' => ['STAGE_ID' => $st['called']]]);
      } else {
        $summary[] = ['Стадия', 'не меняли: сделку уже перевели дальше'];
      }
    }
    allo_deal_comment($deal, allo_lines($summary));
    $bitrix = 'ok';
  } catch (Throwable $e) {
    $bitrix = 'failed';
    allo_log('call_end_bitrix_failed', ['code' => substr($code, 0, 8), 'error' => allo_safe_error($e->getMessage())]);
  }
}
// Заявка ушла письмом мимо Битрикса или Битрикс не ответил сейчас — итог разговора тоже письмом.
$mailed = null;
if (!empty($rec['mailed']) && ($created || $bitrix !== 'ok') || $bitrix === 'failed') {
  $who = $rec['name'] . ', +' . $rec['phone'];
  if ($bitrix === 'ok') {
    $subject = 'Итог разговора: ' . $who;
    $head = 'Битрикс24 снова отвечает: сделка №' . $deal . ' создана, итог разговора записан в неё. Если заявку уже внесли вручную — объедините сделки.';
  } elseif (!empty($rec['deal'])) {
    $subject = 'Внесите вручную: итог разговора — ' . $who;
    $head = 'Сделку №' . $rec['deal'] . ' в Битрикс24 обновить не удалось — переведите стадию и добавьте итог вручную.';
  } else {
    $subject = 'Внесите вручную: итог разговора — ' . $who;
    $head = 'Итог разговора в Битрикс24 не записан — добавьте его к заявке вручную.';
  }
  $mailed = allo_mail($subject, $head . "\n\n" . allo_lines($summary));
}
allo_log('call_ended', ['code' => substr($code, 0, 8), 'deal' => $rec['deal'] ?? null, 'duration' => $finished['duration'], 'bitrix' => $bitrix, 'mail' => $mailed]);
allo_json(['ok' => true, 'bitrix' => $bitrix]);
