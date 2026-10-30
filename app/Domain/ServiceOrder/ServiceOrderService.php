<?php

namespace App\Domain\ServiceOrder;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ServiceOrderService
{
    public function __construct(
        private readonly string $serviceOrderId,
        private readonly string $serviceId,
        private readonly int $quantity,
        private readonly float $price,
        private readonly ?DateTimeImmutable $startedAt,
        private readonly ?DateTimeImmutable $finishedAt,
        private readonly ?string $startedUserId,
        private readonly ?string $finishedUserId,
        private readonly string $id,
        private readonly string $name,
    ) {
    }

    public static function create(
        string $serviceOrderId,
        string $serviceId,
        int $quantity,
        float $price,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $finishedAt = null,
        ?string $startedUserId = null,
        ?string $finishedUserId = null,
        ?string $id = null,
        string $name = '',
    ): self {
        $normalizedServiceOrderId = trim($serviceOrderId);
        $normalizedServiceId = trim($serviceId);

        if ($normalizedServiceOrderId === '') {
            throw new InvalidArgumentException('The service_order_id is required.');
        }

        if ($normalizedServiceId === '') {
            throw new InvalidArgumentException('The service_id is required.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('The quantity must be greater than zero.');
        }

        if ($price < 0) {
            throw new InvalidArgumentException('The price cannot be negative.');
        }

        if ($startedAt !== null && $finishedAt !== null && $finishedAt < $startedAt) {
            throw new InvalidArgumentException('The finished_at date cannot be before the started_at date.');
        }

        return new self(
            $normalizedServiceOrderId,
            $normalizedServiceId,
            $quantity,
            $price,
            $startedAt,
            $finishedAt,
            $startedUserId !== null ? trim($startedUserId) : null,
            $finishedUserId !== null ? trim($finishedUserId) : null,
            $id !== null && trim($id) !== '' ? trim($id) : Str::uuid()->toString(),
            trim($name),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function serviceOrderId(): string
    {
        return $this->serviceOrderId;
    }

    public function serviceId(): string
    {
        return $this->serviceId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function price(): float
    {
        return $this->price;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function startedUserId(): ?string
    {
        return $this->startedUserId;
    }

    public function finishedUserId(): ?string
    {
        return $this->finishedUserId;
    }

    public function total(): float
    {
        return $this->price * $this->quantity;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'service_order_id' => $this->serviceOrderId,
            'service_id' => $this->serviceId,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'started_at' => $this->startedAt?->format('Y-m-d H:i:s'),
            'finished_at' => $this->finishedAt?->format('Y-m-d H:i:s'),
            'started_user_id' => $this->startedUserId,
            'finished_user_id' => $this->finishedUserId,
            'total' => $this->total(),
        ];
    }
}
