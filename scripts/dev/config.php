<?php
// Настройки локального стенда: Битрикс — имитация на порту 4322, почта — на 2525, виджет — /__mock/widget.js.
return [
  // ALLOALLO_BITRIX=fail / off — Битрикс не отвечает / не подключён; ALLOALLO_MAIL=fail / off — то же для почты.
  'bitrix_webhook' => getenv('ALLOALLO_BITRIX') === 'off' ? '' : (getenv('ALLOALLO_BITRIX') === 'fail' ? 'http://127.0.0.1:4399/rest/1/test/' : 'http://127.0.0.1:4322/rest/1/test/'),
  'mail_to' => getenv('ALLOALLO_MAIL') === 'off' ? '' : 'sales@example.ru',
  'mail_from' => 'site@example.ru',
  'smtp_host' => '127.0.0.1',
  'smtp_port' => getenv('ALLOALLO_MAIL') === 'fail' ? 2599 : 2525,
  'smtp_secure' => '',
  'smtp_user' => 'site@example.ru',
  'smtp_pass' => 'test',
  // ALLOALLO_STAGE_NEW / ALLOALLO_STAGE_CALLED — подменить стадии (название или код) для проверки
  'bitrix_stage_new' => getenv('ALLOALLO_STAGE_NEW') ?: 'Новый лид',
  'bitrix_stage_called' => getenv('ALLOALLO_STAGE_CALLED') ?: 'Созвонился с агентом',
  'widget_url' => getenv('ALLOALLO_WIDGET') === 'off' ? '' : '/__mock/widget.js',
  'one_call_per_day' => getenv('ALLOALLO_ONE_CALL') !== 'off',
  'allowed_origins' => ['http://localhost:4321', 'http://127.0.0.1:4321'],
  'leads_per_ip_per_hour' => 50,
  'data_dir' => '/tmp/alloallo-data',
];
