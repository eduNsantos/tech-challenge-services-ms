<?php

namespace App\Lambda;

use App\Application\ServiceOrder\ProcessServiceOrderApprovalChanged;
use App\Application\ServiceOrder\ProcessServiceOrderCreated;
use InvalidArgumentException;

/**
 * Lambda acionada pela fila FIFO service-orders-created (monólito -> MS).
 */
class ServiceOrdersCreatedHandler extends FifoSqsHandler
{
    public function __construct(
        private readonly ProcessServiceOrderCreated $createdProcessor,
        private readonly ProcessServiceOrderApprovalChanged $approvalProcessor,
    ) {}

    protected function eventTypes(): array
    {
        return [ProcessServiceOrderCreated::TYPE, ProcessServiceOrderApprovalChanged::TYPE];
    }

    protected function process(array $payload): bool
    {
        return match ($payload['type'] ?? null) {
            ProcessServiceOrderCreated::TYPE => $this->createdProcessor->handle($payload),
            ProcessServiceOrderApprovalChanged::TYPE => $this->approvalProcessor->handle($payload),
            default => throw new InvalidArgumentException('Unsupported service order event type.'),
        };
    }
}
