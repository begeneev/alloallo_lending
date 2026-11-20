<?php
// Данные для страницы звонка по одноразовому коду: адрес виджета и контекст звонка.
// Контекст уходит в виджет атрибутом data-alloallo-context: агент видит телефон из формы,
// по нему платформа связывает разговор с заявкой.
require __DIR__ . '/_lib.php';

allo_only_post();
allo_check_origin();
$cfg = allo_config();
$in = allo_body();
$code = $in['code'] ?? '';
if (!allo_valid_code($code)) allo_json(['ok' => false, 'error' => 'expired'], 404);
$rec = allo_read('codes', $code);
// Ссылка из сообщения WhatsApp старше суток: запись заявки удалена, но код подписан — восстанавливаем по сделке.
if (!$rec) {
  try { $rec = allo_restore_code($code); }
  catch (Throwable $e) { allo_log('code_restore_failed', ['error' => allo_safe_error($e->getMessage())]); }
}
if (!$rec) allo_json(['ok' => false, 'error' => 'expired'], 404);
// С номера уже поговорили: страница скажет «вы уже оставляли заявку и поговорили с агентом».
if ($cfg['one_call_per_day'] && !empty($rec['calls'])) allo_json(['ok' => false, 'error' => 'used', 'name' => $rec['name']], 409);
if (!$cfg['widget_url']) allo_json(['ok' => false, 'error' => 'not_configured'], 503);

// Свободные часы календаря менеджера для агента: какой день и час он предложит на демонстрацию.
// Отдает сервис nextbot-demo (функции записи агента живут там же), ответ из кэша за ~0,3 с.
// Не ответил — звонок открывается без них: агент спросит удобное время сам.
function allo_free_slots($url) {
  if (!$url) return [];
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
  $out = [];
  foreach (['sloty', 'varianty', 'den1', 'den2'] as $k) if (is_array($j) && isset($j[$k]) && is_string($j[$k])) $out[$k] = $j[$k];
  if (!$out) allo_log('slots_failed', []);
  return $out;
}

allo_json([
  'ok' => true,
  'widget' => $cfg['widget_url'],
  'name' => $rec['name'],
  'repeat' => !$cfg['one_call_per_day'],
  'again' => !empty($rec['repeats']), // форму отправили ещё раз — новую заявку не создавали
  'context' => allo_call_context($rec) + allo_free_slots($cfg['slots_url'] ?? 'https://nextbot-demo.i-am-ivan-perov.workers.dev/slots'),
]);
