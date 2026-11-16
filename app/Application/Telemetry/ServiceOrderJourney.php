<?php

namespace App\Application\Telemetry;

use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use Throwable;

/**
 * Jornada da O.S. no Services MS: um log OTel por passo, com o id da O.S.
 *
 * As métricas (Metrics) não podem levar IDs, por causa da cardinalidade. Estes logs levam:
 * no New Relic eles caem em `Log` com `service_order_id`, o mesmo atributo que a aplicação
 * e o payment-service usam. É isso que permite seguir uma O.S. pelos três serviços.
 * Nunca enviar dados pessoais (nome, documento, e-mail).
 */
class ServiceOrderJourney
{
    public const SOURCE = 'services-ms';

    private LoggerInterface $logger;

    public function __construct(private readonly LoggerProviderInterface $provider)
    {
        $this->logger = $provider->getLogger('services-ms.journey');
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    public function record(string $event, string $serviceOrderId, array $attributes = [], bool $warning = false): void
    {
        $severity = $warning ? Severity::WARN : Severity::INFO;

        try {
            $this->logger->emit(
                (new LogRecord("service_order_journey: {$event}"))
                    ->setSeverityNumber($severity)
                    ->setSeverityText($severity->name)
                    ->setAttributes([
                        'event' => $event,
                        'service_order_id' => $serviceOrderId,
                        'journey.source' => self::SOURCE,
                        'level' => $severity->name,
                    ] + array_filter($attributes, fn ($value) => $value !== null)),
            );
        } catch (Throwable $e) {
            logger()->warning('OpenTelemetry journey log failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Exporta os logs pendentes. Chamado nos mesmos pontos de Metrics::flush().
     */
    public function flush(): void
    {
        try {
            $this->provider->forceFlush();
        } catch (Throwable $e) {
            logger()->warning('OpenTelemetry logs flush failed', ['error' => $e->getMessage()]);
        }
    }
}
