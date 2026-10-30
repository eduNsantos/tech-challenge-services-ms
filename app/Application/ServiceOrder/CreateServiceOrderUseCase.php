<?php

namespace App\Application\ServiceOrder;

use App\Domain\ServiceOrder\ServiceOrder;
use App\Domain\ServiceOrder\ServiceOrderContract;
use App\Domain\ServiceOrder\ServiceOrderStatus;
use DateTimeImmutable;
use InvalidArgumentException;

class CreateServiceOrderUseCase
{
    public function __construct(
        private readonly ServiceOrderContract $serviceOrderRepository,
    ) {
    }

    public function execute(array $data): ServiceOrder
    {
        $customerId = trim((string) ($data['customer_id'] ?? ''));
        $vehicleId = trim((string) ($data['vehicle_id'] ?? ''));
        $servicesTotal = (float) ($data['services_total'] ?? 0);
        $status = (string) ($data['status'] ?? ServiceOrderStatus::Received->value);
        $creationDate = isset($data['creation_date'])
            ? new DateTimeImmutable($data['creation_date'])
            : new DateTimeImmutable();

        if ($customerId === '') {
            throw new InvalidArgumentException('The customer_id is required.');
        }

        if ($vehicleId === '') {
            throw new InvalidArgumentException('The vehicle_id is required.');
        }

        if ($servicesTotal < 0) {
            throw new InvalidArgumentException('The services_total cannot be negative.');
        }

        $serviceOrder = ServiceOrder::create(
            $customerId,
            $vehicleId,
            $servicesTotal,
            $status,
            $creationDate,
            $data['id'] ?? null,
        );

        return $this->serviceOrderRepository->save($serviceOrder);
    }
}
