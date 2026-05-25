<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\RegisterUserCommand;
use App\CommandHandler\RegisterUserHandler;
use App\Exception\NotFoundException;
use App\Repository\EventRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class UserController extends AbstractController
{
    #[Route('/users', name: 'users_register', methods: ['POST'])]
    public function register(Request $request, RegisterUserHandler $handler): JsonResponse
    {
        $payload = $request->toArray();
        $user = $handler(new RegisterUserCommand(
            (string) ($payload['email'] ?? ''),
            (string) ($payload['payment_token'] ?? ''),
        ));

        return $this->json([
            'id' => $user->id(),
            'email' => $user->email(),
            'payment_token' => $user->paymentToken(),
            'created_at' => $user->createdAt()->format(DATE_ATOM),
            'updated_at' => $user->updatedAt()->format(DATE_ATOM),
        ], 201);
    }

    #[Route('/users/{id}/events', name: 'users_events', methods: ['GET'])]
    public function events(string $id, UserRepository $users, EventRepository $events): JsonResponse
    {
        if ($users->find($id) === null) {
            throw new NotFoundException('User not found.');
        }

        return $this->json(array_map(static fn ($event): array => [
            'event_type' => $event->eventType()->value,
            'previous_status' => $event->previousStatus(),
            'new_status' => $event->newStatus(),
            'occurred_at' => $event->occurredAt()->format(DATE_ATOM),
            'metadata' => $event->payload(),
        ], $events->findTimelineForUser($id)));
    }
}
