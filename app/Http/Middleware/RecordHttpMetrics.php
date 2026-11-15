<?php

namespace App\Http\Middleware;

use App\Application\Telemetry\Metrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mede http.server.request.duration das rotas da API (grupo "api").
 * O /up fica de fora: as probes do Kubernetes só gerariam ruído.
 */
class RecordHttpMetrics
{
    public function __construct(private readonly Metrics $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        $response = $next($request);

        $this->metrics->httpRequest(
            $request->getMethod(),
            // Template da rota (ex.: service-orders/{id}), nunca a URL com IDs.
            $request->route()?->uri(),
            $response->getStatusCode(),
            (hrtime(true) - $startedAt) / 1e9,
        );

        return $response;
    }
}
