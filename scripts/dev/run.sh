#!/bin/sh
# Локальный стенд с PHP в Docker: сайт из dist/ на http://localhost:4321, имитация Битрикс24 на 4322 внутри контейнера.
# Перед запуском: python3 scripts/build.py. Вызовы Битрикса и письма видны в выводе контейнера (строки BITRIX и MAIL).
# ALLOALLO_BITRIX=fail|off и ALLOALLO_MAIL=fail|off — сбой или отсутствие Битрикса и почты.
# ALLOALLO_ONE_CALL=off — без ограничения «один разговор в сутки»; ALLOALLO_WIDGET=off — виджет не настроен.
cd "$(dirname "$0")/../.." || exit 1
exec docker run --rm --name alloallo-dev -p 4321:4321 \
  -e ALLOALLO_CONFIG=/dev-stand/config.php \
  -e ALLOALLO_ONE_CALL="${ALLOALLO_ONE_CALL:-on}" -e ALLOALLO_WIDGET="${ALLOALLO_WIDGET:-on}" \
  -e ALLOALLO_BITRIX="${ALLOALLO_BITRIX:-on}" -e ALLOALLO_MAIL="${ALLOALLO_MAIL:-on}" \
  -v "$PWD/dist:/app/dist:ro" -v "$PWD/scripts/dev:/dev-stand:ro" \
  php:8.2-cli sh -c 'php -S 127.0.0.1:4322 /dev-stand/mock-bitrix.php & php /dev-stand/mock-smtp.php & exec php -S 0.0.0.0:4321 -t /app/dist /dev-stand/router.php'
