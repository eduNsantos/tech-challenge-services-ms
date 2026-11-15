<?php

namespace App\Lambda;

use App\Application\Telemetry\Metrics;
use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Base dos consumers de filas FIFO acionados pelo event source mapping do SQS.
 *
 * Mensagens com falha voltam para a fila via batchItemFailures (ReportBatchItemFailures);
 * após o maxReceiveCount da fila, o redrive as envia para a DLQ. Em filas FIFO, a AWS
 * exige que o processamento pare na primeira falha e que as mensagens restantes do lote
 * também sejam devolvidas, para não quebrar a ordem do grupo.
 */
abstract class FifoSqsHandler extends SqsHandler
{
    /**
     * @return bool true quando a mensagem alterou o estado; false quando foi ignorada (duplicada/antiga).
     *
     * @throws InvalidArgumentException quando a mensagem é inválida.
     */
    abstract protected function process(array $payload): bool;

    /**
     * Tipos de evento aceitos pelo consumer; qualquer outro vira "unknown" na métrica
     * para não criar séries com valores arbitrários vindos da mensagem.
     *
     * @return list<string>
     */
    abstract protected function eventTypes(): array;

    public function handleSqs(SqsEvent $event, Context $context): void
    {
        $metrics = app(Metrics::class);

        try {
            $this->processRecords($event, $metrics);
        } finally {
            // O processo da Lambda é reaproveitado: exporta ao fim de cada invocação.
            $metrics->flush();
        }
    }

    private function processRecords(SqsEvent $event, Metrics $metrics): void
    {
        $failed = false;

        foreach ($event->getRecords() as $record) {
            if ($failed) {
                $this->markAsFailed($record);

                continue;
            }

            $startedAt = hrtime(true);
            $payload = null;
            $outcome = 'failed';
            $errorType = null;

            try {
                $payload = json_decode($record->getBody(), true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($payload)) {
                    throw new InvalidArgumentException('The message body must be a JSON object.');
                }

                $processed = $this->process($payload);
                $outcome = $processed ? 'processed' : 'skipped';

                logger()->info(($processed ? 'Processed ' : 'Skipped ').static::class, [
                    'event_id' => $payload['event_id'] ?? null,
                    'message_id' => $record->getMessageId(),
                ]);
            } catch (JsonException|InvalidArgumentException $e) {
                // Mensagem inválida nunca terá sucesso: volta para a fila e o redrive a envia para a DLQ.
                logger()->error('Invalid SQS message', [
                    'handler' => static::class,
                    'error' => $e->getMessage(),
                    'message_id' => $record->getMessageId(),
                ]);
                $failed = true;
                $outcome = 'invalid';
                $errorType = $e::class;
                $this->markAsFailed($record);
            } catch (Throwable $e) {
                report($e);
                $failed = true;
                $errorType = $e::class;
                $this->markAsFailed($record);
            } finally {
                $metrics->messageProcessed(
                    $record->getQueueName(),
                    $this->eventType($payload),
                    $outcome,
                    (hrtime(true) - $startedAt) / 1e9,
                    $errorType,
                );
            }
        }
    }

    private function eventType(mixed $payload): string
    {
        $type = is_array($payload) ? ($payload['type'] ?? null) : null;

        return in_array($type, $this->eventTypes(), true) ? $type : 'unknown';
    }
}
