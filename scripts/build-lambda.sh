#!/usr/bin/env bash
# Monta em build/lambda o pacote das Lambdas (consumers e publisher) do Services MS.
# O Terraform (infra/lambda.tf) compacta esse diretório. Requer PHP 8.4 e Composer.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/build/lambda"

rm -rf "$OUT"
mkdir -p "$OUT"

for path in app bootstrap config database routes resources artisan composer.json composer.lock; do
  cp -R "$ROOT/$path" "$OUT/"
done

# Config do OTel Collector da layer (OPENTELEMETRY_COLLECTOR_CONFIG_URI=/var/task/collector.yaml).
cp "$ROOT/otel/lambda-collector.yaml" "$OUT/collector.yaml"

# Caches locais (config, rotas, pacotes de dev) não podem ir no pacote.
find "$OUT/bootstrap/cache" -type f -name '*.php' -delete

cd "$OUT"
composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist --no-progress

echo "Pacote gerado em $OUT ($(du -sh "$OUT" | cut -f1))"
