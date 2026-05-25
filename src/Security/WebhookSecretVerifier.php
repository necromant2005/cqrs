<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\UnauthorizedException;
use Symfony\Component\HttpFoundation\Request;

final readonly class WebhookSecretVerifier
{
    public function __construct(private string $secret)
    {
    }

    public function verify(Request $request): void
    {
        $providedSecret = (string) $request->headers->get('X-Webhook-Secret', '');

        if ($providedSecret === '' || !hash_equals($this->secret, $providedSecret)) {
            throw new UnauthorizedException('Invalid webhook secret.');
        }
    }
}
