<?php

declare(strict_types=1);

namespace App\Exception;

final class UnauthorizedException extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 401);
    }
}
