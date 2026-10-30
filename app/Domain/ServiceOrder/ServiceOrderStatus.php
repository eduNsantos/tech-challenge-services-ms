<?php

namespace App\Domain\ServiceOrder;

enum ServiceOrderStatus: string
{
    case Received = 'recebida';
    case InDiagnosis = 'em_diagnostico';
    case AwaitingApproval = 'aguardando_aprovacao';
    case InProgress = 'em_execucao';
    case Finished = 'finalizada';
    case Delivered = 'entregue';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Received => [self::InDiagnosis, self::AwaitingApproval],
            self::InDiagnosis => [self::AwaitingApproval],
            self::AwaitingApproval => [self::InProgress],
            self::InProgress => [self::Finished],
            self::Finished => [self::Delivered],
            self::Delivered => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public static function fromInput(string $value): self
    {
        $status = self::tryFrom(strtolower(trim($value)));

        if ($status === null) {
            throw new \InvalidArgumentException('Invalid service order status.');
        }

        return $status;
    }
}
