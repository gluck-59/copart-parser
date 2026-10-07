#!/usr/bin/env bash
# Copart Parser — сбор+импорт и рассылка.
# Окружение берётся из .env: ENVIROMENT=dev (локальный Mac) | prod (Linux-сервер).
set -euo pipefail

cd "$(dirname "$0")"

ENVIROMENT=$(grep -E '^ENVIROMENT=' .env 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'" | xargs)

case "${ENVIROMENT:-}" in
  dev)
    PHP_RUN=(docker compose exec -T php84 php /var/www/html)
    ;;
  prod)
    PHP_RUN=(docker exec php84 php /var/www/copart-parser)
    ;;
  *)
    echo "run.sh: в .env не задан ENVIROMENT (dev|prod)" >&2
    exit 1
    ;;
esac

case "${1:-}" in
  cycle)
    # a) парсинг + импорт в базу
    if [ "$ENVIROMENT" = dev ]; then
      docker compose up --build -d copart-parser
      docker compose logs -f copart-parser
    else
      SINCE=$(date -u +%Y-%m-%dT%H:%M:%SZ)
      docker restart copart-parser-run
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
      "${PHP_RUN[@]}" import.php
    fi
    ;;
  notify)
    # b) рассылка
    "${PHP_RUN[@]}" notify.php
    ;;
  *)
    echo "usage: ./run.sh {cycle|notify}" >&2
    exit 1
    ;;
esac