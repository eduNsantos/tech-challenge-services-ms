<?php

namespace App\Application\Messaging;

use App\Application\Telemetry\Metrics;
use Aws\Sqs\SqsClient;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nova tentativa das mensagens da execução que esgotaram o redrive do SQS (3 recebimentos) e foram para a DLQ.
 *
 * Cada mensagem volta para a fila de origem com o mesmo MessageGroupId (id da O.S.) e o atributo
 * RedriveAttempt incrementado. Os consumers são idempotentes (processed_messages / sequence), então
 * reenviar é seguro. Ao atingir o limite, a mensagem fica na DLQ para análise manual; como a DLQ é FIFO,
 * as mensagens seguintes da mesma O.S. ficam retidas atrás dela, sem quebrar a ordem.
 */
class DeadLetterRedriver
{
    public const ATTEMPT_ATTRIBUTE = 'RedriveAttempt';

    /** Maior que o timeout da Lambda: uma mensagem recebida não reaparece na mesma invocação. */
    private const VISIBILITY_TIMEOUT = 300;

    public function __construct(
        private readonly SqsClient $client,
        private readonly Metrics $metrics,
    ) {}

    /**
     * @param  Closure(): bool  $hasTime
     * @return array{redriven: int, exhausted: int, failed: int}
     */
    public function redrive(string $deadLetterUrl, string $sourceUrl, int $maxAttempts, Closure $hasTime): array
    {
        $queue = self::queueName($sourceUrl);
        $result = ['redriven' => 0, 'exhausted' => 0, 'failed' => 0];

        while ($hasTime()) {
            $messages = $this->client->receiveMessage([
                'QueueUrl' => $deadLetterUrl,
                'MaxNumberOfMessages' => 10,
                'WaitTimeSeconds' => 0,
                'VisibilityTimeout' => self::VISIBILITY_TIMEOUT,
                'MessageSystemAttributeNames' => ['MessageGroupId', 'MessageDeduplicationId'],
                'MessageAttributeNames' => ['All'],
            ])->get('Messages') ?? [];

            if ($messages === []) {
                break;
            }

            foreach ($messages as $message) {
                $outcome = $this->redriveMessage($message, $deadLetterUrl, $sourceUrl, $queue, $maxAttempts);
                $result[$outcome]++;
                $this->metrics->deadLetterRedrive($queue, $outcome);
            }
        }

        return $result;
    }

    private function redriveMessage(array $message, string $deadLetterUrl, string $sourceUrl, string $queue, int $maxAttempts): string
    {
        $attributes = $message['MessageAttributes'] ?? [];
        $attempt = (int) ($attributes[self::ATTEMPT_ATTRIBUTE]['StringValue'] ?? 0);
        $groupId = $message['Attributes']['MessageGroupId'] ?? null;
        $context = ['queue' => $queue, 'message_id' => $message['MessageId'] ?? null, 'attempt' => $attempt];

        if ($attempt >= $maxAttempts || $groupId === null) {
            // Fica invisível até o fim desta invocação e volta a ser avaliada (e alertada) na próxima.
            Log::error('Dead-letter message exhausted its retries; manual action required.', $context + [
                'service_order_id' => $groupId,
            ]);

            return 'exhausted';
        }

        try {
            $this->client->sendMessage([
                'QueueUrl' => $sourceUrl,
                'MessageBody' => $message['Body'],
                'MessageGroupId' => $groupId,
                // Novo id: a janela de deduplicação (5 min) descartaria o reenvio com o id original.
                'MessageDeduplicationId' => substr($message['Attributes']['MessageDeduplicationId'] ?? $message['MessageId'], 0, 100)
                    .'-redrive-'.($attempt + 1),
                'MessageAttributes' => [
                    ...self::sendableAttributes($attributes),
                    self::ATTEMPT_ATTRIBUTE => ['DataType' => 'Number', 'StringValue' => (string) ($attempt + 1)],
                ],
            ]);

            $this->client->deleteMessage(['QueueUrl' => $deadLetterUrl, 'ReceiptHandle' => $message['ReceiptHandle']]);
        } catch (Throwable $e) {
            report($e);

            return 'failed';
        }

        Log::warning('Dead-letter message sent back for a new attempt.', $context + ['service_order_id' => $groupId]);

        return 'redriven';
    }

    /**
     * O ReceiveMessage devolve campos que o SendMessage não aceita (listas vazias, etc.).
     */
    private static function sendableAttributes(array $attributes): array
    {
        $sendable = [];

        foreach ($attributes as $name => $value) {
            $sendable[$name] = array_intersect_key($value, array_flip(['DataType', 'StringValue', 'BinaryValue']));
        }

        return $sendable;
    }

    public static function queueName(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH) ?: $url);
    }
}
