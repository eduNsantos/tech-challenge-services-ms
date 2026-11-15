.PHONY: help build build-deploy up down logs test lint clean

-include .env
export

REGISTRY       ?= ghcr.io
USERNAME       ?= edunsantos
IMAGE_NAME     = tech-challenge-services-ms
IMAGE_TAG      ?= latest
FULL_IMAGE     = $(REGISTRY)/$(USERNAME)/$(IMAGE_NAME):$(IMAGE_TAG)

## Exibe esta ajuda
help:
	@echo "Comandos disponíveis:"
	@grep -E '^[a-zA-Z_-]+:.*?##' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

## Build da imagem Docker
build:
	@echo "Building Docker image: $(FULL_IMAGE)"
	docker build -t $(FULL_IMAGE) .
	@echo "✓ Build concluído: $(FULL_IMAGE)"

## Build da imagem e push para GHCR
build-deploy: build
	@echo "Pushing image to registry: $(FULL_IMAGE)"
	docker push $(FULL_IMAGE)
	@echo "✓ Deploy concluído: $(FULL_IMAGE)"
	@echo ""
	@echo "Para atualizar o Kubernetes, execute:"
	@echo "  kubectl rollout restart deployment/services-ms -n services-ms"

## Sobe os containers de desenvolvimento
up:
	@if [ ! -f .env ]; then cp .env.example .env; fi
	docker compose up -d
	@echo "✓ Containers iniciados"

## Para e remove os containers
down:
	docker compose down
	@echo "✓ Containers parados"

## Exibe logs dos containers
logs:
	docker compose logs -f

## Roda testes
test:
	docker compose exec php php artisan test

## Formata o código com Laravel Pint
lint:
	docker compose exec php ./vendor/bin/pint

## Limpeza de containers, volumes e imagens locais
clean:
	docker compose down -v
	docker rmi $(FULL_IMAGE) 2>/dev/null || true
	@echo "✓ Limpeza concluída"
