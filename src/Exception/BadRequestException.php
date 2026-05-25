<?php

declare(strict_types=1);

namespace App\Exception;

final class BadRequestException extends ApiException
{
    public function __construct(string $message = 'Invalid request payload.')
    {
        parent::__construct($message, 400);
    }
}
