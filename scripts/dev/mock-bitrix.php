<?php
// Имитация REST Битрикс24 для локального стенда: печатает каждый вызов в журнал сервера и отвечает как портал.
$method = preg_replace('~^.*/([a-z.]+)\.json$~', '$1', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$body = file_get_contents('php://input');
error_log('BITRIX ' . $method . ' ' . $body);
$results = [
  'crm.duplicate.findbycomm' => [],
  'crm.contact.add' => 11,
  'crm.deal.add' => 501,
  'crm.deal.update' => true,
  'crm.timeline.comment.add' => 77,
];
header('Content-Type: application/json');
if (!array_key_exists($method, $results)) { http_response_code(400); echo json_encode(['error' => 'ERROR_METHOD_NOT_FOUND']); return; }
echo json_encode(['result' => $results[$method]]);
