<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof ApiException) {
            $event->setResponse(new JsonResponse(['error' => $exception->getMessage()], $exception->statusCode()));

            return;
        }

        if ($exception instanceof JsonException) {
            $event->setResponse(new JsonResponse(['error' => 'Invalid JSON payload.'], 400));
        }
    }
}
