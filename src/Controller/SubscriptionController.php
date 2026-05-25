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
use App\Http\UuidValidator;
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
    public function subscribe(Request $request, SubscribeUserHandler $handler, UuidValidator $uuids): JsonResponse
    {
        $payload = $request->toArray();
        $userId = $uuids->validate((string) ($payload['user_id'] ?? ''), 'user_id');

        $subscription = $handler(new SubscribeUserCommand(
            $userId,
            $this->plan((string) ($payload['plan'] ?? '')),
        ));

        return $this->json($this->subscriptionPayload($subscription), 201);
    }

    #[Route('/cancel', name: 'subscriptions_cancel', methods: ['POST'])]
    public function cancel(Request $request, CancelSubscriptionHandler $handler, UuidValidator $uuids): JsonResponse
    {
        $payload = $request->toArray();
        $userId = $uuids->validate((string) ($payload['user_id'] ?? ''), 'user_id');
        $subscription = $handler(new CancelSubscriptionCommand($userId));

        return $this->json($this->subscriptionPayload($subscription));
    }

    #[Route('/resume', name: 'subscriptions_resume', methods: ['POST'])]
    public function resume(Request $request, ResumeSubscriptionHandler $handler, UuidValidator $uuids): JsonResponse
    {
        $payload = $request->toArray();
        $userId = $uuids->validate((string) ($payload['user_id'] ?? ''), 'user_id');
        $subscription = $handler(new ResumeSubscriptionCommand($userId));

        return $this->json($this->subscriptionPayload($subscription));
    }

    #[Route('/users/{id}/subscription', name: 'users_subscription', methods: ['GET'])]
    public function show(string $id, UserRepository $users, SubscriptionRepository $subscriptions, UuidValidator $uuids): JsonResponse
    {
        $uuids->validate($id, 'id');

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
