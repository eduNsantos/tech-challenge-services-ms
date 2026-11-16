<?php

namespace App\Infrastructure\Telemetry;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\NoopLoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;

/**
 * Monta o LoggerProvider do OpenTelemetry (logs da jornada da O.S.) a partir de config/telemetry.php.
 *
 * Usa o mesmo collector e o mesmo resource das métricas. O processor em lote só exporta no
 * flush do fim da request/invocação (ver ServiceOrderJourney::flush()).
 */
class LoggerProviderFactory
{
    public static function create(array $config): LoggerProviderInterface
    {
        if (($config['logs_exporter'] ?? 'none') !== 'otlp') {
            return NoopLoggerProvider::getInstance();
        }

        $transport = (new OtlpHttpTransportFactory)->create(
            rtrim((string) $config['endpoint'], '/').'/v1/logs',
            ContentTypes::PROTOBUF,
            timeout: (float) ($config['timeout'] ?? 2),
            maxRetries: 1,
        );

        return LoggerProvider::builder()
            ->setResource(MeterProviderFactory::resource($config))
            ->addLogRecordProcessor(new BatchLogRecordProcessor(new LogsExporter($transport), Clock::getDefault()))
            ->build();
    }
}
