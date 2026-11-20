.PHONY: up down restart logs sh console cc db-reset fixtures install v2-stack v2-dump import-v2 import-v2-exemple

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

## ─── Import ORéOF v2 (cf. docs/import-v2.md) ───
## Relie le proto à la pile oreof-stack locale (base « oreof-db »)
v2-stack:
	$(DC) -f compose.yaml -f compose.v2-stack.yaml up -d

## Charge un dump SQL v2 dans une base jetable : make v2-dump DUMP=/chemin/oreof_v2.sql
v2-dump:
	OREOF_V2_DUMP=$(DUMP) $(DC) -f compose.yaml -f compose.v2-dump.yaml up -d

## make import-v2 ARGS="--list" | ARGS="--formation=33 --formation=482"
import-v2:
	$(EXEC) php bin/console app:import-v2 $(ARGS)

## Rejoue l'extrait d'exemple versionné (aucune base v2 nécessaire)
import-v2-exemple:
	$(EXEC) php bin/console app:import-v2 --from=data/import-v2/exemple.json

