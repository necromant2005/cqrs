<?php

declare(strict_types=1);

namespace App\Tests;

final class UserManagementTest extends ApiTestCase
{
    public function testUserCanBeRegisteredWithPaymentToken(): void
    {
        $response = $this->postJson('/users', [
            'email' => 'user@example.com',
            'payment_token' => 'tok_test_123',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('user@example.com', $response['email']);
        self::assertArrayNotHasKey('payment_token', $response);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $response['id']);

        $storedToken = $this->entityManager->getConnection()->fetchOne('SELECT payment_token FROM users WHERE id = ?', [$response['id']]);
        self::assertSame('tok_test_123', $storedToken);
    }

    public function testRegistrationWithoutPaymentTokenFails(): void
    {
        $response = $this->postJson('/users', ['email' => 'user@example.com']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('payment_token is required.', $response['error']);
    }

    public function testDuplicateEmailFails(): void
    {
        $this->registerUser('same@example.com');
        $response = $this->postJson('/users', [
            'email' => 'same@example.com',
            'payment_token' => 'tok_other',
        ]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('User email already exists.', $response['error']);
    }
}
