#!/usr/bin/env bash
# Обновление приложения на сервере: забрать код, поставить зависимости, перезапустить.
# Запуск из каталога приложения:  bash deploy/update.sh
set -e

cd "$(dirname "$0")/.."

echo "→ Забираю свежий код из git..."
git pull

echo "→ Устанавливаю зависимости..."
npm install --omit=dev

echo "→ Перезапускаю сервис..."
sudo systemctl restart contract-generator

echo "✓ Готово. Статус:"
systemctl --no-pager status contract-generator | head -n 5
