<?php
// Свободное время для окна «Запись на демонстрацию» (book.js): начала встреч по Google Календарю менеджера.
// Отдает сервис nextbot-demo (он же ставит встречу в календарь, см. book.php), ответ из его кэша за ~0,3 с.
// starts — моменты в UTC (ISO), окно показывает их по Москве. Сервис не ответил — ok:false, окно покажет
// прежние интервалы, а время подтвердит менеджер.
require __DIR__ . '/_lib.php';

allo_only_post();
allo_check_origin();
$cfg = allo_config();
$url = $cfg['slots_url'] ?? 'https://nextbot-demo.i-am-ivan-perov.workers.dev/slots';

$raw = false;
if (function_exists('curl_init')) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5]);
  $raw = curl_exec($ch);
  curl_close($ch);
} else {
  $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]));
}
$j = is_string($raw) ? json_decode($raw, true) : null;
$starts = [];
$from = time() + 3600; // не раньше чем через час
$to = time() + 21 * ALLO_DAY;
foreach ((is_array($j) && isset($j['starts']) && is_array($j['starts'])) ? $j['starts'] : [] as $s) {
  $t = is_string($s) ? strtotime($s) : false;
  if ($t && $t >= $from && $t <= $to && $t % 3600 === 0) $starts[] = gmdate('Y-m-d\TH:i:s\Z', $t);
}
if (!$starts) { allo_log('book_slots_failed', []); allo_json(['ok' => false, 'error' => 'unavailable']); }
allo_json(['ok' => true, 'starts' => array_values(array_unique($starts))]);
