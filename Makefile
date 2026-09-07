.PHONY: up down restart logs sh console cc db-reset fixtures tw-build tw-watch install

DC = docker compose
EXEC = $(DC) exec app

## Démarre le serveur (http://localhost:8830)
up:
	$(DC) up -d
	@echo "→ http://localhost:8830"

down:
	$(DC) down

restart: down up

logs:
	$(DC) logs -f app

## Shell dans le conteneur
sh:
	$(EXEC) bash

## bin/console ARGS="..."
console:
	$(EXEC) php bin/console $(ARGS)

cc:
	$(EXEC) php bin/console cache:clear

install:
	$(EXEC) composer install
	$(EXEC) php bin/console importmap:install

## Recrée la base SQLite + fixtures (jetable)
db-reset:
	$(EXEC) rm -f var/proto.db
	$(EXEC) php bin/console doctrine:schema:create
	$(EXEC) php bin/console doctrine:fixtures:load --no-interaction

fixtures:
	$(EXEC) php bin/console doctrine:fixtures:load --no-interaction

# Tailwind est chargé au runtime via CDN (@tailwindcss/browser) — aucun build CSS.
