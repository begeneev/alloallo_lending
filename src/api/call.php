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
if (!$rec) allo_json(['ok' => false, 'error' => 'expired'], 404);
// С номера уже поговорили: страница скажет «вы уже оставляли заявку и поговорили с агентом».
if ($cfg['one_call_per_day'] && !empty($rec['calls'])) allo_json(['ok' => false, 'error' => 'used', 'name' => $rec['name']], 409);
if (!$cfg['widget_url']) allo_json(['ok' => false, 'error' => 'not_configured'], 503);

allo_json([
  'ok' => true,
  'widget' => $cfg['widget_url'],
  'name' => $rec['name'],
  'repeat' => !$cfg['one_call_per_day'],
  'again' => !empty($rec['repeats']), // форму отправили ещё раз — новую заявку не создавали
  'context' => [
    'phone' => $rec['phone'],
    'imya' => $rec['name'],
    'sait' => $rec['site'],
    'sfera' => $rec['industry'],
    'zayavka' => $rec['task'],
    'deal' => $rec['deal'] ? (string)$rec['deal'] : '',
    'segodnya' => allo_today_text(),
  ],
]);
