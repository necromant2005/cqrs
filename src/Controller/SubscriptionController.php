<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\CancelSubscriptionCommand;
use App\Command\ResumeSubscriptionCommand;
use App\Command\SubscribeUserCommand;
use App\CommandHandler\CancelSubscriptionHandler;
use App\CommandHandler\ResumeSubscriptionHandler;
use App\CommandHandler\SubscribeUserHandler;
use App\Entity\Subscription;
use App\Enum\SubscriptionPlan;
use App\Exception\BadRequestException;
use App\Exception\NotFoundException;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use ValueError;

final class SubscriptionController extends AbstractController
{
    #[Route('/subscribe', name: 'subscriptions_subscribe', methods: ['POST'])]
    public function subscribe(Request $request, SubscribeUserHandler $handler): JsonResponse
    {
        $payload = $request->toArray();
        $subscription = $handler(new SubscribeUserCommand(
            (string) ($payload['user_id'] ?? ''),
            $this->plan((string) ($payload['plan'] ?? '')),
        ));

        return $this->json($this->subscriptionPayload($subscription), 201);
    }

    #[Route('/cancel', name: 'subscriptions_cancel', methods: ['POST'])]
    public function cancel(Request $request, CancelSubscriptionHandler $handler): JsonResponse
    {
        $payload = $request->toArray();
        $subscription = $handler(new CancelSubscriptionCommand((string) ($payload['user_id'] ?? '')));

        return $this->json($this->subscriptionPayload($subscription));
    }

    #[Route('/resume', name: 'subscriptions_resume', methods: ['POST'])]
    public function resume(Request $request, ResumeSubscriptionHandler $handler): JsonResponse
    {
        $payload = $request->toArray();
        $subscription = $handler(new ResumeSubscriptionCommand((string) ($payload['user_id'] ?? '')));

        return $this->json($this->subscriptionPayload($subscription));
    }

    #[Route('/users/{id}/subscription', name: 'users_subscription', methods: ['GET'])]
    public function show(string $id, UserRepository $users, SubscriptionRepository $subscriptions): JsonResponse
    {
        $user = $users->find($id);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        $subscription = $subscriptions->findOneByUser($user);
        if ($subscription === null) {
            throw new NotFoundException('Subscription not found.');
        }

        return $this->json($this->subscriptionPayload($subscription));
    }

    private function plan(string $value): SubscriptionPlan
    {
        try {
            return SubscriptionPlan::from($value);
        } catch (ValueError) {
            throw new BadRequestException('plan must be monthly or yearly.');
        }
    }

    /** @return array<string, mixed> */
    private function subscriptionPayload(Subscription $subscription): array
    {
        return [
            'user_id' => $subscription->user()->id(),
            'status' => $subscription->status()->value,
            'plan' => $subscription->plan()->value,
            'current_period_start' => $subscription->currentPeriodStart()->format(DATE_ATOM),
            'current_period_end' => $subscription->currentPeriodEnd()->format(DATE_ATOM),
            'access_active' => $subscription->accessActive(new DateTimeImmutable()),
        ];
    }
}
