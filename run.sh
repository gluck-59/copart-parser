#!/usr/bin/env bash
# Copart Parser — сбор+импорт и рассылка.
# Окружение берётся из .env: ENVIROMENT=dev (локальный Mac) | prod (Linux-сервер).
set -euo pipefail

cd "$(dirname "$0")"

ENVIROMENT=$(grep -E '^ENVIROMENT=' .env 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'" | xargs)

case "${ENVIROMENT:-}" in
  dev)
    PHP=(docker compose exec -T php84 php)
    PHP_DIR=/var/www/html
    ;;
  prod)
    PHP=(docker exec php84 php)
    PHP_DIR=/var/www/copart-parser
    ;;
  *)
    echo "run.sh: в .env не задан ENVIROMENT (dev|prod)" >&2
    exit 1
    ;;
esac

# prod: контейнер вотчера создаём при первом запуске и поднимаем, если он down.
# Cmd читает ссылку поиска из БД; если её нет — шлём уведомление и не парсим.
ensure_runner() {
  if docker inspect copart-parser-run >/dev/null 2>&1; then
    docker restart copart-parser-run >/dev/null
  else
    docker run -d --name copart-parser-run --restart unless-stopped \
      --network opengluck \
      -e TZ=Europe/Moscow \
      -v /var/www/copart-parser/output:/app/output \
      -v /var/www/copart-parser/import.log:/app/import.log \
      -v /var/www/copart-parser/bot.log:/app/bot.log \
      -v /var/www/copart-parser/.env:/app/.env \
      copart-parser:latest \
      sh -c 'URL=$(php /app/search_url.php); if [ -n "$URL" ]; then node scraper.js "$URL"; else php /app/notify_no_filters.php; fi; echo SCRAPER_DONE; php /app/parse_watch.php'
  fi
}

case "${1:-}" in
  cycle)
    if [ "$ENVIROMENT" = dev ]; then
      # a) парсинг + импорт в базу
      docker compose up --build -d copart-parser
      docker compose logs -f copart-parser
    else
      URL=$("${PHP[@]}" "$PHP_DIR/search_url.php")
      if [ -z "$URL" ]; then
        "${PHP[@]}" "$PHP_DIR/notify_no_filters.php"
        echo "run.sh: ссылка поиска не задана — парсинг пропущен"
        exit 0
      fi

      SINCE=$(date -u +%Y-%m-%dT%H:%M:%SZ)
      ensure_runner
      echo "run.sh: ждём завершения сбора (SCRAPER_DONE)..."
      done=0
      for _ in $(seq 1 120); do
        if docker logs --since "$SINCE" copart-parser-run 2>&1 | grep -q SCRAPER_DONE; then
          done=1
          break
        fi
        sleep 5
      done
      if [ "$done" -ne 1 ]; then
        echo "run.sh: предупреждение: SCRAPER_DONE не найден за 10 минут" >&2
      fi
      "${PHP[@]}" "$PHP_DIR/import.php"
    fi
    ;;
  notify)
    # b) рассылка
    "${PHP[@]}" "$PHP_DIR/notify.php"
    ;;
  *)
    echo "usage: ./run.sh {cycle|notify}" >&2
    exit 1
    ;;
esac
