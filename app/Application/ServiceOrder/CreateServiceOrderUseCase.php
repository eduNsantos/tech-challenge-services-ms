<?php

namespace App\Application\ServiceOrder;

use App\Domain\ServiceOrder\ServiceOrder;
use App\Domain\ServiceOrder\ServiceOrderContract;
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
        $partsTotal = (float) ($data['parts_total'] ?? 0);
        $status = (string) ($data['status'] ?? 'OPEN');
        $creationDate = isset($data['creation_date'])
            ? new DateTimeImmutable($data['creation_date'])
            : new DateTimeImmutable();

        if ($customerId === '') {
            throw new InvalidArgumentException('The customer_id is required.');
        }

        if ($vehicleId === '') {
            throw new InvalidArgumentException('The vehicle_id is required.');
        }

        if ($servicesTotal < 0 || $partsTotal < 0) {
            throw new InvalidArgumentException('The totals cannot be negative.');
        }

        $serviceOrder = ServiceOrder::create(
            $customerId,
            $vehicleId,
            $servicesTotal,
            $partsTotal,
            $status,
            $creationDate,
        );

        return $this->serviceOrderRepository->save($serviceOrder);
    }
}
