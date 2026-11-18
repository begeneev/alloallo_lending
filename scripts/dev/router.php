<?php
// Локальный стенд (только для разработки, на хостинг не попадает): php -S с этим роутером отдаёт dist/,
// а по /__mock/widget.js — имитацию виджета платформы. Запуск — scripts/dev/run.sh.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__mock/widget.js') {
  header('Content-Type: application/javascript; charset=utf-8');
  readfile(__DIR__ . '/mock-widget.js');
  return true;
}
// Демо-страница: настоящие виджеты платформы с localhost не работают (платформа пускает только аллоалло.рф),
// поэтому отдаём страницу с имитацией виджета вместо них.
if ($path === '/demo/' || $path === '/demo/index.html') {
  header('Content-Type: text/html; charset=utf-8');
  echo preg_replace('~https://voice\.nextbot\.kz/embed/alloallo-widget\.js\?[^"]+~', '/__mock/widget.js', file_get_contents($_SERVER['DOCUMENT_ROOT'] . '/demo/index.html'));
  return true;
}
return false;
