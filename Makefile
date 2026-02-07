# Makefile for Symfony 8 Docker project
# Usage: make [target]

# Variables
DOCKER_COMP = docker compose
PHP_CONT = $(DOCKER_COMP) exec php
SYMFONY = $(PHP_CONT) bin/console
COMPOSER = $(PHP_CONT) composer

# Colors for output
GREEN  := $(shell tput -Txterm setaf 2)
YELLOW := $(shell tput -Txterm setaf 3)
WHITE  := $(shell tput -Txterm setaf 7)
RESET  := $(shell tput -Txterm sgr0)

.DEFAULT_GOAL := help
.PHONY: help

## —— 🎵 Makefile 🎵 ——————————————————————————————————————————————————————————
help: ## Show this help
	@echo ''
	@echo 'Usage:'
	@echo '  ${YELLOW}make${RESET} ${GREEN}<target>${RESET}'
	@echo ''
	@echo 'Targets:'
	@awk 'BEGIN {FS = ":.*?## "} { \
		if (/^[a-zA-Z_-]+:.*?##.*$$/) {printf "    ${YELLOW}%-20s${GREEN}%s${RESET}\n", $$1, $$2} \
		else if (/^## ——/) {printf "\n${WHITE}%s${RESET}\n", substr($$0, 4)} \
	}' $(MAKEFILE_LIST)

## —— Docker 🐳 ————————————————————————————————————————————————————————————————
up: ## Start containers (HTTP mode)
	@SERVER_NAME=http://localhost $(DOCKER_COMP) up --wait

up-d: ## Start containers in detached mode (HTTP mode)
	@SERVER_NAME=http://localhost $(DOCKER_COMP) up -d --wait

down: ## Stop and remove containers
	@$(DOCKER_COMP) down

stop: ## Stop containers without removing
	@$(DOCKER_COMP) stop

restart: down up ## Restart containers

build: ## Build Docker images
	@$(DOCKER_COMP) build --pull

rebuild: ## Rebuild Docker images from scratch
	@$(DOCKER_COMP) build --pull --no-cache

ps: ## Show running containers
	@$(DOCKER_COMP) ps

logs: ## Show container logs (follow)
	@$(DOCKER_COMP) logs -f

logs-php: ## Show PHP container logs
	@$(DOCKER_COMP) logs -f php

logs-db: ## Show database container logs
	@$(DOCKER_COMP) logs -f database

## —— Composer 🧙 ——————————————————————————————————————————————————————————————
composer: ## Run composer (pass args with ARGS="...")
	@$(COMPOSER) $(ARGS)

install: ## Install dependencies
	@$(COMPOSER) install

update: ## Update dependencies
	@$(COMPOSER) update

require: ## Require a package (pass package with ARGS="vendor/package")
	@$(COMPOSER) require $(ARGS)

require-dev: ## Require a dev package (pass package with ARGS="vendor/package")
	@$(COMPOSER) require --dev $(ARGS)

## —— Symfony 🎶 ———————————————————————————————————————————————————————————————
sf: ## Run Symfony console (pass command with ARGS="...")
	@$(SYMFONY) $(ARGS)

cc: ## Clear Symfony cache
	@$(SYMFONY) cache:clear

about: ## Show Symfony info
	@$(SYMFONY) about

routes: ## List all routes
	@$(SYMFONY) debug:router

## —— Database 🗄️ —————————————————————————————————————————————————————————————
db-create: ## Create database
	@$(SYMFONY) doctrine:database:create --if-not-exists

db-drop: ## Drop database
	@$(SYMFONY) doctrine:database:drop --force --if-exists

db-reset: db-drop db-create migrate ## Reset database (drop, create, migrate)

migrate: ## Run migrations
	@$(SYMFONY) doctrine:migrations:migrate --no-interaction

migrate-diff: ## Generate migration from entity changes
	@$(SYMFONY) doctrine:migrations:diff

migrate-status: ## Show migration status
	@$(SYMFONY) doctrine:migrations:status

schema-validate: ## Validate Doctrine schema
	@$(SYMFONY) doctrine:schema:validate

db-test-create: ## Create test database
	@$(DOCKER_COMP) exec database mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS app_test; GRANT ALL PRIVILEGES ON app_test.* TO 'app'@'%'; FLUSH PRIVILEGES;"

## —— Testing 🧪 ———————————————————————————————————————————————————————————————
test: ## Run PHPUnit tests
	@$(COMPOSER) test

test-coverage: ## Run PHPUnit tests with coverage
	@$(DOCKER_COMP) exec -e XDEBUG_MODE=coverage php composer test:coverage

codecept: ## Run Codeception tests
	@$(COMPOSER) codecept

codecept-functional: ## Run Codeception functional tests
	@$(COMPOSER) codecept:functional

codecept-unit: ## Run Codeception unit tests
	@$(COMPOSER) codecept:unit

test-all: ## Run all tests (PHPUnit + Codeception)
	@$(COMPOSER) test:all

## —— Code Quality 🔍 ——————————————————————————————————————————————————————————
qa: ## Run all QA tools (PHPStan + PHPCS)
	@$(COMPOSER) qa

phpstan: ## Run PHPStan static analysis
	@$(COMPOSER) phpstan

phpcs: ## Check code style with PHPCS
	@$(COMPOSER) phpcs

phpcbf: ## Fix code style with PHPCBF
	@$(COMPOSER) phpcbf

cs-fix: ## Fix code style with PHP-CS-Fixer
	@$(COMPOSER) cs-fix

cs-check: ## Check code style with PHP-CS-Fixer (dry-run)
	@$(COMPOSER) cs-check

lint: phpcs cs-check ## Run all linters (PHPCS + CS-Fixer check)

fix: phpcbf cs-fix ## Fix all code style issues

## —— Shell Access 💻 ——————————————————————————————————————————————————————————
sh: ## Open shell in PHP container
	@$(PHP_CONT) bash

sh-root: ## Open root shell in PHP container
	@$(DOCKER_COMP) exec -u root php bash

mysql: ## Open MySQL CLI
	@$(DOCKER_COMP) exec database mysql -uapp -p'!ChangeMe!' app

mysql-root: ## Open MySQL CLI as root
	@$(DOCKER_COMP) exec database mysql -uroot -proot

## —— Utilities 🛠️ —————————————————————————————————————————————————————————————
perms: ## Fix file permissions
	@$(DOCKER_COMP) exec -u root php chown -R www-data:www-data /app
	@$(DOCKER_COMP) exec -u root php chmod -R 777 /app/var /app/config

clean: ## Clean temporary files
	@$(SYMFONY) cache:clear
	@rm -rf var/cache/* var/log/* var/_output/*

entity: ## Create a new entity (pass name with ARGS="EntityName")
	@$(SYMFONY) make:entity $(ARGS)

controller: ## Create a new controller (pass name with ARGS="ControllerName")
	@$(SYMFONY) make:controller $(ARGS)

first-run: build up db-create db-test-create perms ## Initial setup (build, start, create DBs)
