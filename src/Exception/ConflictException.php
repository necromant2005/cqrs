<?php

declare(strict_types=1);

namespace App\Exception;

final class ConflictException extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 409);
    }
}
