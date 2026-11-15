<?php

namespace App\Application\Outbox;

use App\Application\Telemetry\Metrics;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Publica os eventos pendentes da outbox na fila FIFO de execução (MS -> aplicação).
 */
class OutboxPublisher
{
    public const SUPPORTED_TYPES = ['ServiceOrderExecutionStarted', 'ServiceOrderExecutionFinished'];

    private const LOCK_NAME = 'services-ms-outbox-publisher';

    public function __construct(
        private readonly SqsClient $client,
        private readonly Metrics $metrics,
    ) {}

    /**
     * @return int|null eventos publicados, ou null se outro publisher já estiver rodando.
     */
    public function publishPending(int $limit = 50): ?int
    {
        $url = config('services.sqs.service_order_execution_url');

        if (! $url) {
            throw new RuntimeException('SQS_SERVICE_ORDER_EXECUTION_URL is not configured.');
        }

        // Um único publisher por vez preserva a ordem por O.S., mesmo com
        // invocações sobrepostas do agendamento.
        if (! $this->acquireLock()) {
            return null;
        }

        try {
            return $this->publish($url, $limit);
        } finally {
            $this->releaseLock();
        }
    }

    private function publish(string $url, int $limit): int
    {
        $events = DB::table('outbox')->whereNull('published_at')->orderBy('id')->limit($limit)->get();
        $published = 0;
        $blockedGroups = [];
        $queue = basename(parse_url($url, PHP_URL_PATH) ?: $url);

        foreach ($events as $event) {
            // Preserva a ordem por O.S.: se um evento falhar, os seguintes do mesmo grupo esperam.
            if (isset($blockedGroups[$event->group_id])) {
                continue;
            }

            if (! in_array($event->type, self::SUPPORTED_TYPES, true)) {
                report(new RuntimeException("Unsupported outbox event type {$event->type}."));
                $this->metrics->messageSent($queue, 'unknown', 'unsupported_event_type');
                $blockedGroups[$event->group_id] = true;

                continue;
            }

            try {
                $this->client->sendMessage([
                    'QueueUrl' => $url,
                    'MessageBody' => $event->payload,
                    'MessageGroupId' => $event->group_id,
                    'MessageDeduplicationId' => $event->event_id,
                ]);
            } catch (Throwable $e) {
                report($e);
                $this->metrics->messageSent($queue, $event->type, $e::class);
                $blockedGroups[$event->group_id] = true;

                continue;
            }

            $publishedAt = now();
            DB::table('outbox')->where('id', $event->id)->update(['published_at' => $publishedAt]);
            $this->metrics->messageSent(
                $queue,
                $event->type,
                lagSeconds: (float) Carbon::parse($event->created_at)->diffInSeconds($publishedAt),
            );
            $published++;
        }

        return $published;
    }

    private function acquireLock(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return true;
        }

        return (int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [self::LOCK_NAME])->acquired === 1;
    }

    private function releaseLock(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        }
    }
}
