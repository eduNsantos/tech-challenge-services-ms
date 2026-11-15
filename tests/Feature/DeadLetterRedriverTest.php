<?php

namespace Tests\Feature;

use App\Application\Messaging\DeadLetterRedriver;
use App\Lambda\RedriveDeadLettersHandler;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\Exception\SqsException;
use Aws\Sqs\SqsClient;
use Bref\Context\Context;
use Tests\TestCase;

class DeadLetterRedriverTest extends TestCase
{
    private const DLQ = 'https://sqs.us-east-1.amazonaws.com/1/services-ms-service-order-execution-dlq.fifo';

    private const SOURCE = 'https://sqs.us-east-1.amazonaws.com/1/services-ms-service-order-execution.fifo';

    /** @var list<CommandInterface> */
    private array $commands = [];

    /**
     * @param  list<Result|callable>  $results  respostas na ordem das chamadas ao SQS
     */
    private function client(array $results): SqsClient
    {
        $mock = new MockHandler();

        foreach ($results as $result) {
            $mock->append(function (CommandInterface $command) use ($result) {
                $this->commands[] = $command;

                return is_callable($result) ? $result($command) : $result;
            });
        }

        $client = new SqsClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);
        $this->app->instance(SqsClient::class, $client);

        return $client;
    }

    private function message(string $id, ?int $attempt = null, string $group = 'order-1'): array
    {
        $attributes = ['trace' => ['DataType' => 'String', 'StringValue' => 'abc', 'StringListValues' => []]];

        if ($attempt !== null) {
            $attributes[DeadLetterRedriver::ATTEMPT_ATTRIBUTE] = ['DataType' => 'Number', 'StringValue' => (string) $attempt];
        }

        $message = [
            'MessageId' => $id,
            'ReceiptHandle' => "handle-{$id}",
            'Body' => json_encode(['event_id' => "event-{$id}", 'type' => 'ServiceOrderExecutionFinished']),
            'Attributes' => ['MessageGroupId' => $group, 'MessageDeduplicationId' => "event-{$id}"],
            'MessageAttributes' => $attributes,
        ];

        // O SDK confere os MD5 da resposta, como faria com uma mensagem real.
        $md5 = new \ReflectionMethod(SqsClient::class, 'calculateMessageAttributesMd5');

        return $message + ['MD5OfBody' => md5($message['Body']), 'MD5OfMessageAttributes' => $md5->invoke(null, $message)];
    }

    private function redrive(int $maxAttempts = 4): array
    {
        return app(DeadLetterRedriver::class)->redrive(self::DLQ, self::SOURCE, $maxAttempts, fn () => true);
    }

    private function names(): array
    {
        return array_map(fn (CommandInterface $c) => $c->getName(), $this->commands);
    }

    public function test_sends_dead_letters_back_to_the_source_queue_in_order(): void
    {
        $this->client([
            new Result(['Messages' => [$this->message('m1'), $this->message('m2', 1)]]),
            new Result([]), new Result([]),
            new Result([]), new Result([]),
            new Result(['Messages' => []]),
        ]);

        $this->assertSame(['redriven' => 2, 'exhausted' => 0, 'failed' => 0], $this->redrive());
        $this->assertSame(
            ['ReceiveMessage', 'SendMessage', 'DeleteMessage', 'SendMessage', 'DeleteMessage', 'ReceiveMessage'],
            $this->names(),
        );

        $first = $this->commands[1];
        $this->assertSame(self::SOURCE, $first['QueueUrl']);
        $this->assertSame('order-1', $first['MessageGroupId']);
        $this->assertSame('event-m1-redrive-1', $first['MessageDeduplicationId']);
        $this->assertSame('1', $first['MessageAttributes'][DeadLetterRedriver::ATTEMPT_ATTRIBUTE]['StringValue']);
        $this->assertSame(['DataType' => 'String', 'StringValue' => 'abc'], $first['MessageAttributes']['trace']);
        $this->assertSame(self::DLQ, $this->commands[2]['QueueUrl']);
        $this->assertSame('handle-m1', $this->commands[2]['ReceiptHandle']);

        $this->assertSame('2', $this->commands[3]['MessageAttributes'][DeadLetterRedriver::ATTEMPT_ATTRIBUTE]['StringValue']);
        $this->assertSame('event-m2-redrive-2', $this->commands[3]['MessageDeduplicationId']);
    }

    public function test_keeps_message_in_the_dead_letter_queue_after_the_last_attempt(): void
    {
        $this->client([
            new Result(['Messages' => [$this->message('m1', 4)]]),
            new Result(['Messages' => []]),
        ]);

        $this->assertSame(['redriven' => 0, 'exhausted' => 1, 'failed' => 0], $this->redrive(4));
        $this->assertSame(['ReceiveMessage', 'ReceiveMessage'], $this->names());
    }

    public function test_does_not_delete_when_the_send_fails(): void
    {
        $this->client([
            new Result(['Messages' => [$this->message('m1')]]),
            fn (CommandInterface $c) => new SqsException('throttled', $c),
            new Result(['Messages' => []]),
        ]);

        $this->assertSame(['redriven' => 0, 'exhausted' => 0, 'failed' => 1], $this->redrive());
        $this->assertNotContains('DeleteMessage', $this->names());
    }

    public function test_stops_when_out_of_time(): void
    {
        $this->client([]);

        $result = app(DeadLetterRedriver::class)->redrive(self::DLQ, self::SOURCE, 4, fn () => false);

        $this->assertSame(['redriven' => 0, 'exhausted' => 0, 'failed' => 0], $result);
        $this->assertSame([], $this->commands);
    }

    public function test_lambda_redrives_every_configured_queue(): void
    {
        config([
            'services.sqs.redrive_max_attempts' => 4,
            'services.sqs.redrive_queues' => [
                self::DLQ => self::SOURCE,
                'https://sqs/1/services-ms-service-orders-created-dlq.fifo' => 'https://sqs/1/services-ms-service-orders-created.fifo',
            ],
        ]);
        $this->client([
            new Result(['Messages' => [$this->message('m1')]]),
            new Result([]), new Result([]),
            new Result(['Messages' => []]),
            new Result(['Messages' => []]),
        ]);

        $context = new Context('request-id', (int) (microtime(true) * 1000) + 60_000, 'arn:test', '');
        $result = app(RedriveDeadLettersHandler::class)->handle([], $context);

        $this->assertSame(1, $result['services-ms-service-order-execution.fifo']['redriven']);
        $this->assertSame(0, $result['services-ms-service-orders-created.fifo']['redriven']);
        $this->assertSame('https://sqs/1/services-ms-service-orders-created-dlq.fifo', end($this->commands)['QueueUrl']);
    }
}
