<?php
// Локальный стенд (только для разработки, на хостинг не попадает): php -S с этим роутером отдаёт dist/,
// а по /__mock/widget.js — имитацию виджета платформы. Запуск — scripts/dev/run.sh.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__mock/widget.js') {
  header('Content-Type: application/javascript; charset=utf-8');
  readfile(__DIR__ . '/mock-widget.js');
  return true;
}
return false;
