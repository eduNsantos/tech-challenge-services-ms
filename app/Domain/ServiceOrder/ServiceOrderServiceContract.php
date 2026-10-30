<?php

namespace App\Domain\ServiceOrder;

interface ServiceOrderServiceContract
{
    public function save(ServiceOrderService $serviceOrderService): ServiceOrderService;
}
