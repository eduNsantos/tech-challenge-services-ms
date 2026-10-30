<?php

namespace App\Console\Commands;

use Aws\Sqs\SqsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishOutbox extends Command
{
    protected $signature = 'outbox:publish {--once : Publish pending events and exit} {--sleep=2 : Seconds between polls}';

    protected $description = 'Publish pending outbox events to the SQS FIFO status-changed queue';

    public function handle(): int
    {
        $url = config('services.sqs.service_order_status_changed_url');

        if (! $url) {
            $this->error('SQS_SERVICE_ORDER_STATUS_CHANGED_URL is not configured.');

            return self::FAILURE;
        }

        $client = $this->client();

        do {
            $published = $this->publishPending($client, $url);

            if (! $this->option('once') && $published === 0) {
                sleep((int) $this->option('sleep'));
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    private function publishPending(SqsClient $client, string $url): int
    {
        $events = DB::table('outbox')->whereNull('published_at')->orderBy('id')->limit(50)->get();
        $published = 0;
        $blockedGroups = [];

        foreach ($events as $event) {
            // Preserva a ordem por O.S.: se um evento falhar, os seguintes do mesmo grupo esperam.
            if (isset($blockedGroups[$event->group_id])) {
                continue;
            }

            try {
                $client->sendMessage([
                    'QueueUrl' => $url,
                    'MessageBody' => $event->payload,
                    'MessageGroupId' => $event->group_id,
                    'MessageDeduplicationId' => $event->event_id,
                ]);
            } catch (Throwable $e) {
                report($e);
                $blockedGroups[$event->group_id] = true;

                continue;
            }

            DB::table('outbox')->where('id', $event->id)->update(['published_at' => now()]);
            $published++;
        }

        return $published;
    }

    private function client(): SqsClient
    {
        $config = ['version' => 'latest', 'region' => config('services.sqs.region')];

        if ($endpoint = config('services.sqs.endpoint')) {
            $config['endpoint'] = $endpoint;
        }

        return new SqsClient($config);
    }
}
