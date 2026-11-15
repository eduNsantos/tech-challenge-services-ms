<?php

namespace App\Infrastructure\Telemetry;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Resource\Detectors\Environment;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Monta o MeterProvider do OpenTelemetry a partir de config/telemetry.php.
 *
 * Temporalidade delta: cada request/invocação PHP exporta só o que mediu, e é a
 * temporalidade recomendada pelo New Relic para OTLP.
 */
class MeterProviderFactory
{
    public static function create(array $config): MeterProviderInterface
    {
        if (($config['exporter'] ?? 'none') !== 'otlp') {
            return new NoopMeterProvider;
        }

        $transport = (new OtlpHttpTransportFactory)->create(
            rtrim((string) $config['endpoint'], '/').'/v1/metrics',
            ContentTypes::PROTOBUF,
            timeout: (float) ($config['timeout'] ?? 2),
            maxRetries: 1,
        );

        return MeterProvider::builder()
            ->setResource(self::resource($config))
            ->addReader(new ExportingReader(new MetricExporter($transport, Temporality::DELTA)))
            ->build();
    }

    public static function resource(array $config): ResourceInfo
    {
        $attributes = array_filter([
            ResourceAttributes::SERVICE_NAME => $config['service_name'] ?? 'services-ms',
            ResourceAttributes::SERVICE_NAMESPACE => $config['service_namespace'] ?? null,
            ResourceAttributes::SERVICE_VERSION => $config['service_version'] ?? null,
            ResourceAttributes::SERVICE_INSTANCE_ID => gethostname() ?: null,
            'deployment.environment.name' => config('app.env'),
        ], fn ($value) => $value !== null && $value !== '');

        if ($function = getenv('AWS_LAMBDA_FUNCTION_NAME')) {
            $attributes += [
                ResourceAttributes::CLOUD_PROVIDER => 'aws',
                ResourceAttributes::CLOUD_PLATFORM => 'aws_lambda',
                ResourceAttributes::CLOUD_REGION => getenv('AWS_REGION') ?: null,
                ResourceAttributes::FAAS_NAME => $function,
            ];
        } elseif ($namespace = getenv('POD_NAMESPACE')) {
            $attributes += [
                ResourceAttributes::K8S_NAMESPACE_NAME => $namespace,
                ResourceAttributes::K8S_POD_NAME => gethostname() ?: null,
            ];
        }

        // OTEL_RESOURCE_ATTRIBUTES / OTEL_SERVICE_NAME sobrescrevem os valores acima.
        return ResourceInfo::create(Attributes::create(array_filter($attributes)))
            ->merge((new Environment)->getResource());
    }
}
