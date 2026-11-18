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
  'DEAL_STAGE' => [['NEW', 'Новая'], ['PREPARATION', 'Подготовка документов'], ['WON', 'Сделка успешна', 'success'], ['LOSE', 'Сделка провалена', 'failure']],
  // «Новый лид» есть в двух воронках, как на портале: сервер выбирает ту, где есть все три стадии.
  'DEAL_STAGE_59' => [['C59:NEW', 'Новый лид'], ['C59:PREPARATION', 'Оплатил курс'], ['C59:WON', 'Купил', 'success']],
  'DEAL_STAGE_61' => [['C61:NEW', 'Новый лид'], ['C61:UC_JJQIEM', 'Заявка голос'], ['C61:PREPARATION', 'Отправлено сообщение'],
    ['C61:PREPAYMENT_INVOIC', 'В работе у ИИ-агента'], ['C61:UC_TRYSNP', 'Начал звонок'], ['C61:UC_FK8BK2', 'Созвонился с агентом'],
    ['C61:EXECUTING', 'Назначена консультация'], ['C61:WON', 'Сделка успешна', 'success'], ['C61:LOSE', 'Сделка провалена', 'failure']],
];
switch ($method) {
  // Номер +79043174313 уже записан в чужой контакт «итоговый Тест», +79001112233 — в контакт «Иван Петров».
  case 'crm.duplicate.findbycomm':
    $known = ['+79043174313' => [101265], '+79001112233' => [12]];
    $r = isset($known[$in['values'][0] ?? '']) ? ['CONTACT' => $known[$in['values'][0]]] : [];
    break;
  case 'crm.contact.list':
    $people = [101265 => ['итоговый', 'Тест'], 12 => ['Иван', 'Петров']];
    $r = [];
    foreach ((array)($in['filter']['ID'] ?? []) as $id) if (isset($people[$id])) $r[] = ['ID' => (string)$id, 'NAME' => $people[$id][0], 'LAST_NAME' => $people[$id][1]];
    break;
  case 'crm.contact.add': $r = 11; break;
  // Робот портала: на «Новый лид» отправляет сообщение в WhatsApp и переводит сделку на «Отправлено сообщение».
  case 'crm.deal.add': $r = 501; $s0 = $in['fields']['STAGE_ID'] ?? 'NEW'; file_put_contents($stageFile, $s0 === 'C61:NEW' ? 'C61:PREPARATION' : $s0); break;
  case 'crm.deal.update': $r = true; if (isset($in['fields']['STAGE_ID'])) file_put_contents($stageFile, $in['fields']['STAGE_ID']); break;
  case 'crm.deal.get': $r = ['ID' => (string)($in['id'] ?? 0), 'STAGE_ID' => is_file($stageFile) ? file_get_contents($stageFile) : 'C61:NEW']; break;
  case 'crm.timeline.comment.add': $r = 77; break;
  case 'crm.category.list': $r = ['categories' => [['id' => 0, 'name' => 'Общая'], ['id' => 59, 'name' => 'Интенсив'], ['id' => 61, 'name' => 'Лидген на разработку']]]; break;
  case 'crm.status.list':
    $r = [];
    foreach ($funnels[$in['filter']['ENTITY_ID'] ?? ''] ?? [] as $i => $s) $r[] = ['STATUS_ID' => $s[0], 'NAME' => $s[1], 'SORT' => ($i + 1) * 10, 'EXTRA' => ['SEMANTICS' => $s[2] ?? 'process']];
    break;
  default: http_response_code(400); header('Content-Type: application/json'); echo json_encode(['error' => 'ERROR_METHOD_NOT_FOUND']); return;
}
header('Content-Type: application/json');
echo json_encode(['result' => $r], JSON_UNESCAPED_UNICODE);
