<?php

namespace App\Domain\ServiceOrder;

interface ServiceOrderContract
{
    public function save(ServiceOrder $serviceOrder): ServiceOrder;
}