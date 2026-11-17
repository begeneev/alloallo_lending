<?php
// Общие функции серверной части лендинга: настройки, хранилище заявок, Битрикс24.
// Работает на PHP 7.4+ (виртуальный хостинг reg.ru), без сторонних библиотек.
//
// Поток: форма → lead.php (сделка в Битриксе, одноразовый код) → /call/#<код> (страница с виджетом,
// в звонок уходит телефон из формы) → call-event.php (соединились / положили трубку → стадия сделки).

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === '_lib.php') { http_response_code(404); exit; }

const ALLO_DAY = 86400;
const ALLO_KEEP = 2 * ALLO_DAY; // данные заявки на сервере живут двое суток, потом удаляются

function allo_json($data, $status = 200) {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Настройки лежат вне папки сайта: деплой (rsync --delete) их не трогает и снаружи их не открыть.
// Порядок поиска: переменная окружения ALLOALLO_CONFIG, затем две папки выше сайта.
// Образец — scripts/alloallo-config.example.php.
function allo_config() {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $paths = array_filter([
    getenv('ALLOALLO_CONFIG') ?: null,
    dirname(__DIR__, 3) . '/alloallo-config.php',
    dirname(__DIR__, 2) . '/alloallo-config.php',
  ]);
  $cfg = ['_path' => null];
  foreach ($paths as $p) {
    if (is_file($p)) { $loaded = include $p; if (is_array($loaded)) { $cfg = $loaded; $cfg['_path'] = $p; } break; }
  }
  $cfg += [
    'bitrix_webhook' => '',
    'bitrix_stage_new' => '',
    'bitrix_stage_called' => '',
    'bitrix_category_id' => 0,
    'bitrix_funnel' => '',
    'bitrix_source_id' => 'WEB',
    'bitrix_assigned_by_id' => 0,
    'widget_url' => '',
    'one_call_per_day' => true,
    'allowed_origins' => [],
    'timezone' => 'Europe/Moscow',
    'leads_per_ip_per_hour' => 6,
    'data_dir' => '',
    'mail_to' => '',
    'mail_mode' => 'always',
    'mail_from' => '',
    'mail_from_name' => 'Сайт Аллоалло',
    'smtp_host' => '',
    'smtp_port' => 0,
    'smtp_secure' => 'ssl',
    'smtp_user' => '',
    'smtp_pass' => '',
  ];
  if (!$cfg['data_dir']) $cfg['data_dir'] = ($cfg['_path'] ? dirname($cfg['_path']) : sys_get_temp_dir()) . '/alloallo-data';
  return $cfg;
}

function allo_log($event, $data = []) {
  $dir = allo_dir('log');
  if (!$dir) return;
  $line = date('c') . ' ' . $event . ' ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
  @file_put_contents($dir . '/' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
}

function allo_dir($sub) {
  $dir = rtrim(allo_config()['data_dir'], '/') . '/' . $sub;
  if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
  return $dir;
}

// Тело запроса: JSON (fetch, sendBeacon) или обычная форма.
function allo_body() {
  $raw = file_get_contents('php://input', false, null, 0, 20000);
  $data = json_decode((string)$raw, true);
  if (is_array($data)) return $data;
  return $_POST;
}

function allo_str($v, $max) {
  $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v));
  return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}

// Только российская аудитория, но номер принимаем в любом виде: 8XXXXXXXXXX и XXXXXXXXXX → 7XXXXXXXXXX.
function allo_phone($raw) {
  $d = preg_replace('/\D/', '', (string)$raw);
  if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
  if (strlen($d) === 10) $d = '7' . $d;
  return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : '';
}

// Запросы только со своего сайта: защищает форму от отправки с чужих страниц.
function allo_check_origin() {
  $allowed = allo_config()['allowed_origins'];
  if (!$allowed) return;
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if ($origin === '' && !empty($_SERVER['HTTP_REFERER'])) {
    $u = parse_url($_SERVER['HTTP_REFERER']);
    if (!empty($u['scheme']) && !empty($u['host'])) $origin = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
  }
  if (!in_array($origin, $allowed, true)) allo_json(['ok' => false, 'error' => 'forbidden'], 403);
}

function allo_only_post() {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); allo_json(['ok' => false, 'error' => 'method'], 405); }
}

// --- Хранилище: по файлу на код и на номер, с блокировкой. ---

function allo_valid_code($code) { return is_string($code) && preg_match('/^[a-f0-9]{32}$/', $code); }

function allo_read($sub, $key) {
  $dir = allo_dir($sub);
  if (!$dir) return null;
  $f = $dir . '/' . $key . '.json';
  if (!is_file($f)) return null;
  $data = json_decode((string)@file_get_contents($f), true);
  if (!is_array($data)) return null;
  if (isset($data['expires']) && $data['expires'] < time()) { @unlink($f); return null; }
  return $data;
}

function allo_write($sub, $key, $data) {
  $dir = allo_dir($sub);
  if (!$dir) throw new RuntimeException('storage');
  $f = $dir . '/' . $key . '.json';
  $tmp = $f . '.' . bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !@rename($tmp, $f)) {
    @unlink($tmp);
    throw new RuntimeException('storage');
  }
  @chmod($f, 0600);
}

// Изменение записи под замком: два запроса подряд (sendBeacon и fetch) не затрут друг друга.
function allo_update($sub, $key, callable $fn) {
  $dir = allo_dir($sub);
  if (!$dir) throw new RuntimeException('storage');
  $lock = fopen($dir . '/' . $key . '.lock', 'c');
  if (!$lock) throw new RuntimeException('storage');
  flock($lock, LOCK_EX);
  try {
    $rec = allo_read($sub, $key);
    $out = $fn($rec);
    if (is_array($out)) allo_write($sub, $key, $out);
    return $out;
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

function allo_phone_key($phone) { return hash('sha256', 'alloallo|' . $phone); }

// Удаляет просроченные записи (заявки старше двух суток). Вызывается изредка из lead.php.
function allo_cleanup() {
  foreach (['codes', 'phones', 'rate', 'cache'] as $sub) {
    $dir = allo_dir($sub);
    if (!$dir) continue;
    foreach (array_merge(glob($dir . '/*.json') ?: [], glob($dir . '/*.lock') ?: []) as $f) {
      if (filemtime($f) < time() - ALLO_KEEP) @unlink($f);
    }
  }
}

// Не больше N заявок в час с одного IP.
function allo_rate_limit() {
  $limit = (int)allo_config()['leads_per_ip_per_hour'];
  if ($limit <= 0) return;
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $key = hash('sha256', 'rate|' . $ip);
  $now = time();
  $over = false;
  allo_update('rate', $key, function ($rec) use ($now, $limit, &$over) {
    $hits = array_values(array_filter($rec['hits'] ?? [], function ($t) use ($now) { return $t > $now - 3600; }));
    if (count($hits) >= $limit) { $over = true; return null; }
    $hits[] = $now;
    return ['hits' => $hits, 'expires' => $now + 3600];
  });
  if ($over) allo_json(['ok' => false, 'error' => 'too_many'], 429);
}

// --- Время для агента и комментариев: по поясу из настроек (по умолчанию Москва). ---

function allo_now() {
  return new DateTime('now', new DateTimeZone(allo_config()['timezone'] ?: 'Europe/Moscow'));
}

function allo_today_text() {
  $days = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
  $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  $n = allo_now();
  return $days[(int)$n->format('w')] . ', ' . (int)$n->format('j') . ' ' . $months[(int)$n->format('n') - 1] . ' ' . $n->format('Y') . ', ' . $n->format('H:i');
}

function allo_duration_text($sec) {
  $sec = max(0, (int)$sec);
  return intdiv($sec, 60) . ':' . str_pad((string)($sec % 60), 2, '0', STR_PAD_LEFT);
}

// --- Битрикс24: входящий вебхук с правами CRM (https://<портал>.bitrix24.ru/rest/<user>/<код>/). ---

function allo_bitrix_on() { return (bool)allo_config()['bitrix_webhook']; }

function allo_bx($method, $params) {
  $url = rtrim(allo_config()['bitrix_webhook'], '/') . '/' . $method . '.json';
  $body = json_encode($params, JSON_UNESCAPED_UNICODE);
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new RuntimeException($method . ': ' . $err);
  } else {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $status = (int)$m[1];
    if ($resp === false) throw new RuntimeException($method . ': no response');
  }
  $j = json_decode((string)$resp, true);
  // 200 без result (например, страница входа при неверном вебхуке) — тоже ошибка.
  if ($status >= 400 || !is_array($j) || isset($j['error']) || !array_key_exists('result', $j)) {
    $e = is_array($j) ? (($j['error'] ?? '') . ' ' . ($j['error_description'] ?? '')) : 'bad_response';
    throw new RuntimeException($method . ': ' . trim($e ?: (string)$status));
  }
  return $j['result'];
}

// --- Стадии сделки. В настройках — название стадии, как оно написано в воронке Битрикс24 («Новый лид»),
// или её код (C29:NEW). Названия сервер переводит в коды сам: читает воронки портала и запоминает на час.
// Воронку находит по стадиям: та, где есть обе. Если такие стадии есть в нескольких — нужна bitrix_funnel.

function allo_norm($s) {
  $s = trim(preg_replace('/\s+/u', ' ', (string)$s));
  $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
  return str_replace(['ё', '«', '»', '"'], ['е', '', '', ''], $s);
}

function allo_is_stage_code($v) { return (bool)preg_match('/^(C\d+:)?[A-Z][A-Z0-9_]*$/', (string)$v); }

// Воронка из кода стадии (C29:NEW → 29); у основной воронки префикса нет.
function allo_category_of($code) {
  if (preg_match('/^C(\d+):/', (string)$code, $m)) return (int)$m[1];
  return (int)allo_config()['bitrix_category_id'];
}

// Воронки сделок портала со стадиями по порядку: [id => ['name' => …, 'stages' => [[code, name], …]]].
function allo_bx_funnels() {
  $cats = [];
  try {
    $res = allo_bx('crm.category.list', ['entityTypeId' => 2]);
    foreach (($res['categories'] ?? []) as $c) $cats[(int)$c['id']] = (string)$c['name'];
  } catch (Throwable $e) {
    // старые порталы: воронки только через crm.dealcategory.list
    foreach ((array)allo_bx('crm.dealcategory.list', []) as $c) $cats[(int)$c['ID']] = (string)$c['NAME'];
  }
  if (!isset($cats[0])) $cats = [0 => 'Общая'] + $cats;
  $out = [];
  foreach ($cats as $id => $name) {
    $list = allo_bx('crm.status.list', ['order' => ['SORT' => 'ASC'], 'filter' => ['ENTITY_ID' => $id ? 'DEAL_STAGE_' . $id : 'DEAL_STAGE']]);
    $stages = [];
    foreach ((array)$list as $st) $stages[] = [(string)$st['STATUS_ID'], (string)$st['NAME']];
    $out[$id] = ['name' => $name, 'stages' => $stages];
  }
  return $out;
}

// Код стадии в воронке по названию: точное совпадение (без учёта регистра, ё/е и кавычек), иначе — единственная
// стадия, название которой начинается с указанного (или наоборот): «Новый лид» найдёт «Новый лид (сайт)».
function allo_find_stage($want, $stages) {
  if (allo_is_stage_code($want)) {
    foreach ($stages as $st) if ($st[0] === $want) return $st[0];
    return null;
  }
  $w = allo_norm($want);
  foreach ($stages as $st) if (allo_norm($st[1]) === $w) return $st[0];
  if (strlen($w) < 6) return null;
  $hits = [];
  foreach ($stages as $st) {
    $n = allo_norm($st[1]);
    if ($n !== '' && (strpos($n, $w) === 0 || strpos($w, $n) === 0)) $hits[] = $st[0];
  }
  return count($hits) === 1 ? $hits[0] : null;
}

// ['new' => код, 'called' => код, 'category' => воронка]. Ошибка — исключение с понятным текстом:
// он попадёт в журнал и в письмо «Внесите вручную».
function allo_stages() {
  static $res = null;
  if ($res !== null) return $res;
  $cfg = allo_config();
  $want = ['new' => trim((string)$cfg['bitrix_stage_new']), 'called' => trim((string)$cfg['bitrix_stage_called'])];
  $set = array_filter($want, 'strlen');
  $named = array_filter($set, function ($v) { return !allo_is_stage_code($v); });
  if (!$named) return $res = $want + ['category' => allo_category_of($want['new'] ?: $want['called'])];

  $key = 'stages-' . substr(hash('sha256', $cfg['bitrix_webhook'] . '|' . json_encode([$want, $cfg['bitrix_funnel']], JSON_UNESCAPED_UNICODE)), 0, 24);
  $cached = allo_read('cache', $key);
  if ($cached && isset($cached['stages'])) return $res = $cached['stages'];

  $funnels = allo_bx_funnels();
  $only = trim((string)$cfg['bitrix_funnel']);
  $found = [];
  foreach ($funnels as $id => $f) {
    if ($only !== '' && (string)$id !== $only && allo_norm($f['name']) !== allo_norm($only)) continue;
    $codes = [];
    foreach ($set as $k => $v) {
      $c = allo_find_stage($v, $f['stages']);
      if ($c === null) continue 2;
      $codes[$k] = $c;
    }
    $found[$id] = $codes;
  }
  if (count($found) > 1) {
    $names = array_map(function ($id) use ($funnels) { return '«' . $funnels[$id]['name'] . '» (' . $id . ')'; }, array_keys($found));
    throw new RuntimeException('такие стадии есть в нескольких воронках: ' . implode(', ', $names) . ' — укажите нужную в bitrix_funnel');
  }
  if (!$found) {
    $all = [];
    foreach ($funnels as $id => $f) {
      if ($only !== '' && (string)$id !== $only && allo_norm($f['name']) !== allo_norm($only)) continue;
      $all[] = '«' . $f['name'] . '»: ' . implode(', ', array_map(function ($st) { return $st[1]; }, $f['stages']));
    }
    $msg = 'не нашлась воронка со стадиями «' . implode('» и «', $set) . '»' . ($only !== '' ? ' (bitrix_funnel = ' . $only . ')' : '') . '. Есть: ' . implode('; ', $all);
    throw new RuntimeException(function_exists('mb_substr') ? mb_substr($msg, 0, 700, 'UTF-8') : substr($msg, 0, 1400));
  }
  $id = array_keys($found)[0];
  $res = $found[$id] + ['new' => '', 'called' => '', 'category' => $id];
  allo_write('cache', $key, ['stages' => $res, 'expires' => time() + 3600]);
  allo_log('bitrix_stages', $res);
  return $res;
}

// Контакт по телефону: есть — берём, нет — заводим.
function allo_contact($phone, $name) {
  $found = allo_bx('crm.duplicate.findbycomm', ['entity_type' => 'CONTACT', 'type' => 'PHONE', 'values' => ['+' . $phone]]);
  if (!empty($found['CONTACT'][0])) return (int)$found['CONTACT'][0];
  $cfg = allo_config();
  $fields = ['NAME' => $name ?: 'Заявка с сайта', 'PHONE' => [['VALUE' => '+' . $phone, 'VALUE_TYPE' => 'WORK']], 'SOURCE_ID' => $cfg['bitrix_source_id']];
  if ($cfg['bitrix_assigned_by_id']) $fields['ASSIGNED_BY_ID'] = (int)$cfg['bitrix_assigned_by_id'];
  return (int)allo_bx('crm.contact.add', ['fields' => $fields]);
}

function allo_lines($pairs) {
  $out = [];
  foreach ($pairs as $p) if (isset($p[1]) && trim((string)$p[1]) !== '') $out[] = $p[0] . ': ' . trim((string)$p[1]);
  return implode("\n", $out);
}

// Сделка по заявке с сайта: стадия bitrix_stage_new.
function allo_create_deal($rec) {
  $cfg = allo_config();
  $st = allo_stages();
  $stage = $st['new'];
  $host = $rec['site'] ? preg_replace('~^https?://(www\.)?~i', '', rtrim($rec['site'], '/')) : '';
  $fields = [
    'TITLE' => 'Аллоалло: ' . ($host ?: $rec['name'] ?: '+' . $rec['phone']),
    'CATEGORY_ID' => $st['category'],
    'CONTACT_ID' => allo_contact($rec['phone'], $rec['name']),
    'SOURCE_ID' => $cfg['bitrix_source_id'],
    'SOURCE_DESCRIPTION' => 'Форма на сайте: ' . $rec['task'],
    'COMMENTS' => allo_lines([
      ['Имя', $rec['name']], ['Телефон', '+' . $rec['phone']], ['Сайт компании', $rec['site']],
      ['Кнопка на сайте', $rec['task']], ['Сфера', $rec['industry']], ['Страница', $rec['page']],
      ['Согласие на обработку ПД', 'да, ' . $rec['created_text']],
    ]),
  ];
  if ($stage) $fields['STAGE_ID'] = $stage;
  if ($cfg['bitrix_assigned_by_id']) $fields['ASSIGNED_BY_ID'] = (int)$cfg['bitrix_assigned_by_id'];
  foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $u) {
    if (!empty($rec['utm'][$u])) $fields[strtoupper($u)] = $rec['utm'][$u];
  }
  $id = (int)allo_bx('crm.deal.add', ['fields' => $fields]);
  if (!$id) throw new RuntimeException('crm.deal.add: no id');
  return $id;
}

// --- Почта: копия каждой заявки (mail_mode = always) или только когда Битрикс24 не принял её (fallback). ---
// Отправка через SMTP почтового ящика (надёжнее: письма не попадают в спам), а если smtp_host пуст —
// встроенной функцией mail() хостинга.

function allo_mail_on() { return (bool)allo_mail_to(); }

function allo_mail_to() {
  $to = allo_config()['mail_to'];
  $list = is_array($to) ? $to : preg_split('/[,;\s]+/', (string)$to);
  return array_values(array_filter(array_map('trim', $list), function ($a) { return filter_var($a, FILTER_VALIDATE_EMAIL); }));
}

function allo_mime($text) { return '=?UTF-8?B?' . base64_encode($text) . '?='; }

function allo_mail($subject, $text) {
  $cfg = allo_config();
  $to = allo_mail_to();
  if (!$to) return false;
  $from = $cfg['mail_from'] ?: ($cfg['smtp_user'] ?: 'noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
  $headers = [
    'From: ' . allo_mime($cfg['mail_from_name']) . ' <' . $from . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: base64',
  ];
  $body = chunk_split(base64_encode($text), 76, "\r\n");
  try {
    if (!$cfg['smtp_host']) {
      $ok = @mail(implode(', ', $to), allo_mime($subject), $body, implode("\r\n", $headers), '-f' . $from);
      if (!$ok) allo_log('mail_failed', ['error' => 'mail() returned false']);
      return $ok;
    }
    $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
    $msg = implode("\r\n", array_merge([
      'Date: ' . date('r'),
      'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
      'To: ' . implode(', ', $to),
      'Subject: ' . allo_mime($subject),
    ], $headers)) . "\r\n\r\n" . $body;
    allo_smtp($from, $to, $msg);
    return true;
  } catch (Throwable $e) {
    allo_log('mail_failed', ['error' => $e->getMessage()]);
    return false;
  }
}

// Минимальный SMTP-клиент: SSL (порт 465), STARTTLS (587) или без шифрования, вход AUTH LOGIN.
function allo_smtp($from, array $to, $msg) {
  $cfg = allo_config();
  $secure = $cfg['smtp_secure'];
  $port = (int)$cfg['smtp_port'] ?: ($secure === 'ssl' ? 465 : 587);
  $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['smtp_host'] . ':' . $port;
  $s = @stream_socket_client($remote, $errno, $errstr, 8);
  if (!$s) throw new RuntimeException('smtp connect: ' . $errstr);
  stream_set_timeout($s, 8);
  $read = function () use ($s) {
    $all = '';
    while (($line = fgets($s, 1024)) !== false) { $all .= $line; if (strlen($line) < 4 || $line[3] !== '-') break; }
    return $all;
  };
  $cmd = function ($line, $expect) use ($s, $read) {
    if ($line !== null) fwrite($s, $line . "\r\n");
    $resp = $read();
    if ((int)substr($resp, 0, 3) !== $expect) throw new RuntimeException('smtp: ' . trim(strtok($resp, "\r\n") ?: 'no answer'));
    return $resp;
  };
  try {
    $cmd(null, 220);
    $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $cmd('EHLO ' . $host, 250);
    if ($secure === 'tls') {
      $cmd('STARTTLS', 220);
      if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('smtp: starttls failed');
      $cmd('EHLO ' . $host, 250);
    }
    if ($cfg['smtp_user']) {
      $cmd('AUTH LOGIN', 334);
      $cmd(base64_encode($cfg['smtp_user']), 334);
      $cmd(base64_encode($cfg['smtp_pass']), 235);
    }
    $cmd('MAIL FROM:<' . $from . '>', 250);
    foreach ($to as $addr) $cmd('RCPT TO:<' . $addr . '>', 250);
    $cmd('DATA', 354);
    // Строки, начинающиеся с точки, удваиваем (правило SMTP).
    $cmd(preg_replace('/^\./m', '..', $msg) . "\r\n.", 250);
    fwrite($s, "QUIT\r\n");
  } finally {
    fclose($s);
  }
}

// Текст ошибки Битрикса без адреса вебхука (в нём ключ доступа).
function allo_safe_error($text) {
  $hook = rtrim((string)allo_config()['bitrix_webhook'], '/');
  return $hook ? str_replace($hook, '[вебхук]', (string)$text) : (string)$text;
}

// Письмо о заявке. $why пусто — заявка уже в Битриксе (копия), иначе — причина, почему её надо внести вручную.
function allo_lead_mail($rec, $why) {
  $utm = [];
  foreach ($rec['utm'] ?? [] as $k => $v) $utm[] = str_replace('utm_', '', $k) . '=' . $v;
  $who = ($rec['name'] ?: 'Без имени') . ', +' . $rec['phone'];
  $subject = ($why ? 'Внесите вручную: ' : '') . 'Заявка с сайта Аллоалло — ' . $who;
  $head = $why ? "Заявка НЕ попала в Битрикс24 — внесите её вручную.\nПричина: " . $why
    : 'Копия заявки. В Битрикс24 она уже есть: сделка №' . $rec['deal'] . '.';
  $text = $head . "\n\n" . allo_lines([
    ['Имя', $rec['name']], ['Телефон', '+' . $rec['phone']], ['Сайт компании', $rec['site']],
    ['Кнопка на сайте', $rec['task']], ['Сфера', $rec['industry']], ['Страница', $rec['page']], ['UTM', implode(', ', $utm)],
    ['Согласие на обработку ПД', 'да, ' . $rec['created_text']],
  ]) . "\n\nЧеловек перешёл на страницу звонка агенту." . ($why ? ' Если он поговорит с агентом, итог придёт следующим письмом.' : '');
  return allo_mail($subject, $text);
}

function allo_deal_comment($deal, $text) {
  allo_bx('crm.timeline.comment.add', ['fields' => ['ENTITY_ID' => (int)$deal, 'ENTITY_TYPE' => 'deal', 'COMMENT' => $text]]);
}
