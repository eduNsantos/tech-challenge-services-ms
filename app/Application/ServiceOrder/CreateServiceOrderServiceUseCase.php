<?php

namespace App\Application\ServiceOrder;

use App\Domain\ServiceOrder\ServiceOrderService;
use App\Domain\ServiceOrder\ServiceOrderServiceContract;
use DateTimeImmutable;
use InvalidArgumentException;

class CreateServiceOrderServiceUseCase
{
    public function __construct(
        private readonly ServiceOrderServiceContract $serviceOrderServiceRepository,
    ) {
    }

    public function execute(array $data): ServiceOrderService
    {
        $serviceOrderId = trim((string) ($data['service_order_id'] ?? ''));
        $serviceId = trim((string) ($data['service_id'] ?? ''));
        $quantity = (int) ($data['quantity'] ?? 0);
        $price = (float) ($data['price'] ?? 0);
        $startedUserId = isset($data['started_user_id']) && $data['started_user_id'] !== ''
            ? trim((string) $data['started_user_id'])
            : null;
        $finishedUserId = isset($data['finished_user_id']) && $data['finished_user_id'] !== ''
            ? trim((string) $data['finished_user_id'])
            : null;

        $startedAt = isset($data['started_at']) && $data['started_at'] !== ''
            ? new DateTimeImmutable($data['started_at'])
            : null;
        $finishedAt = isset($data['finished_at']) && $data['finished_at'] !== ''
            ? new DateTimeImmutable($data['finished_at'])
            : null;

        if ($serviceOrderId === '') {
            throw new InvalidArgumentException('The service_order_id is required.');
        }

        if ($serviceId === '') {
            throw new InvalidArgumentException('The service_id is required.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('The quantity must be greater than zero.');
        }

        if ($price < 0) {
            throw new InvalidArgumentException('The price cannot be negative.');
        }

        $serviceOrderService = ServiceOrderService::create(
            $serviceOrderId,
            $serviceId,
            $quantity,
            $price,
            $startedAt,
            $finishedAt,
            $startedUserId,
            $finishedUserId,
            $data['id'] ?? null,
            (string) ($data['name'] ?? ''),
        );

        return $this->serviceOrderServiceRepository->save($serviceOrderService);
    }
}
