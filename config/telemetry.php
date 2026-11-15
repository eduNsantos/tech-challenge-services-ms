<?php

/*
| Métricas OpenTelemetry (OTLP/HTTP).
|
| O MS nunca fala direto com o New Relic: exporta para um OTel Collector
| (Deployment no EKS para a API web; layer/extension na Lambda), que repassa
| para o New Relic. Sem OTEL_METRICS_EXPORTER=otlp nada é exportado.
*/

return [

    // "otlp" liga a exportação; qualquer outro valor (padrão "none") usa um provider no-op.
    'exporter' => env('OTEL_METRICS_EXPORTER', 'none'),

    // Base do endpoint OTLP/HTTP do collector; o SDK acrescenta /v1/metrics.
    'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318'),

    // Tempo máximo por exportação. A exportação roda no fim da request/invocação.
    'timeout' => (float) env('OTEL_EXPORTER_OTLP_TIMEOUT_SECONDS', 2),

    'service_name' => env('OTEL_SERVICE_NAME', 'services-ms'),

    'service_namespace' => env('OTEL_SERVICE_NAMESPACE', 'tech-challenge'),

    'service_version' => env('APP_VERSION'),

];
