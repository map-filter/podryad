#!/bin/sh
set -e
cd "$(dirname "$0")/.."
if [ -f .env ]; then
    exit 0
fi
random() {
    head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"
}
key="base64:$(head -c 32 /dev/urandom | base64)"
sed -e "s|^APP_KEY=.*|APP_KEY=${key}|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$(random 24)|" \
    .env.example > .env
echo "Создан .env со случайными паролями"
