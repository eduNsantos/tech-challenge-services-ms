<?php

namespace App\Lambda;

use App\Application\Messaging\DeadLetterRedriver;
use App\Application\Telemetry\Metrics;
use App\Application\Telemetry\ServiceOrderJourney;
use Bref\Context\Context;
use Bref\Event\Handler;

/**
 * Lambda agendada (EventBridge) que devolve às filas de origem as mensagens da execução que caíram nas DLQs.
 * As filas vêm de SQS_REDRIVE_QUEUES ({"<url da DLQ>": "<url da fila de origem>"}).
 */
class RedriveDeadLettersHandler implements Handler
{
    private const SAFETY_MARGIN_MS = 10_000;

    public function __construct(
        private readonly DeadLetterRedriver $redriver,
        private readonly Metrics $metrics,
        private readonly ServiceOrderJourney $journey,
    ) {}

    /**
     * @return array<string, array{redriven: int, exhausted: int, failed: int}>
     */
    public function handle(mixed $event, Context $context): array
    {
        try {
            $maxAttempts = (int) config('services.sqs.redrive_max_attempts');
            $hasTime = fn (): bool => $context->getRemainingTimeInMillis() > self::SAFETY_MARGIN_MS;
            $results = [];

            foreach (config('services.sqs.redrive_queues') as $deadLetterUrl => $sourceUrl) {
                $results[DeadLetterRedriver::queueName($sourceUrl)] = $this->redriver->redrive($deadLetterUrl, $sourceUrl, $maxAttempts, $hasTime);
            }

            return $results;
        } finally {
            $this->metrics->flush();
            $this->journey->flush();
        }
    }
}
