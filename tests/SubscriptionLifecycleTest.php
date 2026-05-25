<?php

declare(strict_types=1);

namespace App\Tests;

use App\Enum\SubscriptionStatus;
use DateTimeImmutable;

final class SubscriptionLifecycleTest extends ApiTestCase
{
    public function testSubscribeCreatesActiveSubscription(): void
    {
        $userId = $this->registerUser();

        $response = $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($userId, $response['user_id']);
        self::assertSame('active', $response['status']);
        self::assertTrue($response['access_active']);
    }

    public function testDuplicateActiveSubscriptionIsRejected(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);

        $response = $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'yearly']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('User already has an active subscription.', $response['error']);
    }

    public function testCancelChangesStatusAndKeepsAccessUntilPeriodEnd(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);

        $response = $this->postJson('/cancel', ['user_id' => $userId]);

        self::assertResponseIsSuccessful();
        self::assertSame('canceled', $response['status']);
        self::assertTrue($response['access_active']);
    }

    public function testResumeBeforePeriodEndChangesStatusBackToActive(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);
        $this->postJson('/cancel', ['user_id' => $userId]);

        $response = $this->postJson('/resume', ['user_id' => $userId]);

        self::assertResponseIsSuccessful();
        self::assertSame('active', $response['status']);
    }

    public function testResumeAfterPeriodEndFails(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);
        $this->postJson('/cancel', ['user_id' => $userId]);
        $this->expireSubscription($userId, SubscriptionStatus::Canceled);

        $response = $this->postJson('/resume', ['user_id' => $userId]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Subscription cannot be resumed after current period end.', $response['error']);
    }

    public function testExpiredSubscriptionCanBeSubscribedAgain(): void
    {
        $userId = $this->registerUser();
        $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'monthly']);
        $this->expireSubscription($userId, SubscriptionStatus::Expired);

        $response = $this->postJson('/subscribe', ['user_id' => $userId, 'plan' => 'yearly']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('active', $response['status']);
        self::assertSame('yearly', $response['plan']);
    }

    public function testSubscribeWithInvalidUserIdFails(): void
    {
        $response = $this->postJson('/subscribe', ['user_id' => 'not-a-uuid', 'plan' => 'monthly']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('user_id must be a valid UUID.', $response['error']);
    }

    public function testCancelWithInvalidUserIdFails(): void
    {
        $response = $this->postJson('/cancel', ['user_id' => 'not-a-uuid']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('user_id must be a valid UUID.', $response['error']);
    }

    public function testResumeWithInvalidUserIdFails(): void
    {
        $response = $this->postJson('/resume', ['user_id' => 'not-a-uuid']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('user_id must be a valid UUID.', $response['error']);
    }

    public function testReadSubscriptionWithInvalidUserIdFails(): void
    {
        $response = $this->jsonRequest('GET', '/users/not-a-uuid/subscription');

        self::assertResponseStatusCodeSame(400);
        self::assertSame('id must be a valid UUID.', $response['error']);
    }

    private function expireSubscription(string $userId, SubscriptionStatus $status): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE subscriptions SET status = ?, current_period_end = ? WHERE user_id = ?',
            [$status->value, (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), $userId],
        );
        $this->entityManager->clear();
    }
}
