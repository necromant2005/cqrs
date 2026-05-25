<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\BadRequestException;
use Symfony\Component\Uid\Uuid;

final readonly class UuidValidator
{
    public function validate(string $value, string $field): string
    {
        if (!Uuid::isValid($value)) {
            throw new BadRequestException(sprintf('%s must be a valid UUID.', $field));
        }

        return $value;
    }
}
