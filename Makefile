SHELL := /bin/sh
COMPOSE := docker compose
UID := 1000:1000
DB_USERNAME = $(shell grep ^DB_USERNAME= .env 2>/dev/null | cut -d= -f2)
DB_PASSWORD = $(shell grep ^DB_PASSWORD= .env 2>/dev/null | cut -d= -f2)
PHP_RUN := docker run --rm -u $(UID) -v $(CURDIR)/backend:/var/www/backend -e COMPOSER_HOME=/tmp/composer podryad-php:dev
NODE_RUN := docker run --rm -u $(UID) -v $(CURDIR)/frontend:/app -w /app -e npm_config_cache=/app/.npm-cache node:22-alpine
PY := /home/diyar/server/fl-monitor/.venv/bin/python

.PHONY: env up down restart reset logs ps seed shell notify test test-php test-front verify screenshots layout

env:
	@sh tools/make-env.sh

up: env
	$(COMPOSE) build
	$(COMPOSE) up -d --wait
	@echo "Кабинет:  http://127.0.0.1:8311"
	@echo "API:      http://127.0.0.1:8311/docs/api/"
	@echo "Почта:    http://127.0.0.1:8313"

down:
	$(COMPOSE) down

restart: down up

reset: env
	$(COMPOSE) down -v
	$(MAKE) up

logs:
	$(COMPOSE) logs -f --tail=100

ps:
	$(COMPOSE) ps

seed:
	$(COMPOSE) exec podryad-app php artisan migrate:fresh --seed --force

shell:
	$(COMPOSE) exec podryad-app sh

notify:
	$(COMPOSE) exec podryad-app php artisan permits:notify

test: test-php test-front

test-php: env
	@$(COMPOSE) build -q podryad-app
	$(COMPOSE) up -d --wait podryad-db
	@test -f backend/vendor/autoload.php || $(PHP_RUN) composer install --no-interaction --no-progress
	@docker run --rm -u $(UID) --network podryad_default -v $(CURDIR)/backend:/var/www/backend \
		-e DB_CONNECTION=pgsql -e DB_HOST=podryad-db \
		-e DB_USERNAME=$(DB_USERNAME) -e DB_PASSWORD=$(DB_PASSWORD) \
		-e DB_DATABASE=podryad_test podryad-php:dev php vendor/bin/phpunit --testdox --colors=always

test-front:
	$(NODE_RUN) sh -c "npm ci --no-audit --no-fund --loglevel=error && npm run typecheck"

verify:
	$(PY) tools/e2e.py

screenshots:
	$(PY) tools/screenshots.py shots

layout:
	$(PY) tools/screenshots.py layout
