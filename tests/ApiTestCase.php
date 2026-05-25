<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->resetDatabase();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $server
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    protected function jsonRequest(string $method, string $uri, array $payload = [], array $server = []): array
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + $server,
            content: $payload === [] && $method === 'GET' ? null : json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    protected function postJson(string $uri, array $payload): array
    {
        return $this->jsonRequest('POST', $uri, $payload);
    }

    /** @param array<string, mixed> $payload */
    protected function postJsonWithHeaders(string $uri, array $payload, array $server): array
    {
        return $this->jsonRequest('POST', $uri, $payload, $server);
    }

    protected function registerUser(string $email = 'user@example.com'): string
    {
        $response = $this->postJson('/users', [
            'email' => $email,
            'payment_token' => 'tok_test_123',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $response['id']);

        return (string) $response['id'];
    }

    protected function resetDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        foreach (['events', 'subscriptions', 'users', 'messenger_messages'] as $table) {
            try {
                $connection->executeStatement(sprintf('DELETE FROM %s', $table));
            } catch (\Throwable) {
            }
        }
    }
}
