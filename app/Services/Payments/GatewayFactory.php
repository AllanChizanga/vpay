<?php

namespace App\Services\Payments;

class GatewayFactory
{
    public static function make(string $gateway): PaymentGatewayInterface
    {
        return match ($gateway) {
            'paynow' => new PaynowService(),
            default => throw new \InvalidArgumentException("Unsupported gateway: {$gateway}"),
        };
    }
}
