<?php

namespace App\Lambda;

use App\Application\ServiceOrder\ProcessServiceOrderStatusChanged;

/**
 * Lambda acionada pela fila FIFO service-order-status-changed (aplicação -> MS).
 */
class ServiceOrderStatusChangedHandler extends FifoSqsHandler
{
    public function __construct(private readonly ProcessServiceOrderStatusChanged $processor) {}

    protected function process(array $payload): bool
    {
        return $this->processor->handle($payload);
    }
}
