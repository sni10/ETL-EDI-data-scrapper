##################
# Config
##################

ENVIRONMENT ?= test
ENV_FILE=./docker/config-envs/$(ENVIRONMENT)/.env.$(ENVIRONMENT)
COMPOSE_FILES = -f ./docker-compose.yml -f ./docker/config-envs/$(ENVIRONMENT)/docker-compose.override.yml


##################
# Docker Compose
##################

dc_build:
	docker-compose $(COMPOSE_FILES) build --pull

dc_up:
	docker-compose $(COMPOSE_FILES) up -d --build --force-recreate --remove-orphans

dc_down:
	docker-compose $(COMPOSE_FILES) down --volumes --rmi local --remove-orphans

dc_restart:
	docker-compose $(COMPOSE_FILES) down --volumes --rmi local --remove-orphans
	docker-compose $(COMPOSE_FILES) up -d --build --force-recreate

dc_logs:
	docker-compose $(COMPOSE_FILES) logs -f

dc_ps:
	docker-compose $(COMPOSE_FILES) ps

dc_exec:
	docker-compose $(COMPOSE_FILES) exec -u www-data php-fpm bash

##################
# Cleanup
##################

docker_clean:
	docker system prune -af --volumes
	docker builder prune -af
	docker image prune -af
