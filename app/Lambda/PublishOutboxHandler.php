<?php

namespace App\Lambda;

use App\Application\Outbox\OutboxPublisher;
use App\Application\Telemetry\Metrics;
use Bref\Context\Context;
use Bref\Event\Handler;

/**
 * Lambda agendada (EventBridge, a cada minuto) que publica a outbox na fila de execução.
 *
 * Como o agendamento tem granularidade de 1 minuto, a invocação continua publicando
 * em ciclos curtos até perto do fim do tempo limite, mantendo a latência baixa.
 */
class PublishOutboxHandler implements Handler
{
    /** Margem para terminar o ciclo atual antes do timeout da Lambda. */
    private const SAFETY_MARGIN_MS = 10_000;

    public function __construct(
        private readonly OutboxPublisher $publisher,
        private readonly Metrics $metrics,
        private readonly int $sleepSeconds = 2,
    ) {}

    /**
     * @return array{published: int}
     */
    public function handle(mixed $event, Context $context): array
    {
        try {
            return ['published' => $this->publishUntilTimeout($context)];
        } finally {
            $this->metrics->flush();
        }
    }

    private function publishUntilTimeout(Context $context): int
    {
        $total = 0;

        do {
            $published = $this->publisher->publishPending();

            if ($published === null) {
                // Outra invocação já está publicando.
                break;
            }

            $total += $published;

            if ($published === 0 && $this->hasTimeFor($context, $this->sleepSeconds * 1000)) {
                sleep($this->sleepSeconds);
            }
        } while ($this->hasTimeFor($context, 0));

        return $total;
    }

    private function hasTimeFor(Context $context, int $ms): bool
    {
        return $context->getRemainingTimeInMillis() - $ms > self::SAFETY_MARGIN_MS;
    }
}
