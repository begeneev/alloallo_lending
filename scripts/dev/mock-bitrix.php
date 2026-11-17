<?php
// Имитация REST Битрикс24 для локального стенда: печатает каждый вызов в журнал сервера и отвечает как портал.
// Две воронки со стадиями; стадия последней сделки хранится в /tmp/mock-bitrix-stage, чтобы crm.deal.get
// отвечал правдоподобно (её можно подменить руками и проверить, что стадия не откатывается назад).
$method = preg_replace('~^.*/([a-z.]+)\.json$~', '$1', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$body = file_get_contents('php://input');
$in = json_decode($body, true) ?: [];
error_log('BITRIX ' . $method . ' ' . $body);
$stageFile = '/tmp/mock-bitrix-stage';
$funnels = [
  'DEAL_STAGE' => [['NEW', 'Новая'], ['PREPARATION', 'Подготовка документов'], ['WON', 'Сделка успешна'], ['LOSE', 'Сделка провалена']],
  'DEAL_STAGE_29' => [['C29:NEW', 'Новый лид'], ['C29:UC_K4T1QX', 'Созвонился с агентом'], ['C29:UC_8XW2ZL', 'Демо назначено'], ['C29:WON', 'Клиент'], ['C29:LOSE', 'Отказ']],
];
switch ($method) {
  case 'crm.duplicate.findbycomm': $r = []; break;
  case 'crm.contact.add': $r = 11; break;
  case 'crm.deal.add': $r = 501; file_put_contents($stageFile, $in['fields']['STAGE_ID'] ?? 'NEW'); break;
  case 'crm.deal.update': $r = true; if (isset($in['fields']['STAGE_ID'])) file_put_contents($stageFile, $in['fields']['STAGE_ID']); break;
  case 'crm.deal.get': $r = ['ID' => (string)($in['id'] ?? 0), 'STAGE_ID' => is_file($stageFile) ? file_get_contents($stageFile) : 'C29:NEW']; break;
  case 'crm.timeline.comment.add': $r = 77; break;
  case 'crm.category.list': $r = ['categories' => [['id' => 0, 'name' => 'Общая'], ['id' => 29, 'name' => 'Аллоалло']]]; break;
  case 'crm.status.list':
    $r = [];
    foreach ($funnels[$in['filter']['ENTITY_ID'] ?? ''] ?? [] as $i => $s) $r[] = ['STATUS_ID' => $s[0], 'NAME' => $s[1], 'SORT' => ($i + 1) * 10];
    break;
  default: http_response_code(400); header('Content-Type: application/json'); echo json_encode(['error' => 'ERROR_METHOD_NOT_FOUND']); return;
}
header('Content-Type: application/json');
echo json_encode(['result' => $r], JSON_UNESCAPED_UNICODE);
