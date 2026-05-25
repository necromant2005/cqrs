<?php

declare(strict_types=1);

namespace App\CommandHandler;

use App\Command\RegisterUserCommand;
use App\Entity\User;
use App\Enum\EventType;
use App\EventStore\EventStore;
use App\Exception\ConflictException;
use App\Exception\BadRequestException;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RegisterUserHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private EventStore $eventStore,
    ) {
    }

    public function __invoke(RegisterUserCommand $command): User
    {
        $email = trim($command->email);
        $paymentToken = trim($command->paymentToken);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BadRequestException('email is required and must be valid.');
        }

        if ($paymentToken === '') {
            throw new BadRequestException('payment_token is required.');
        }

        if ($this->users->findOneBy(['email' => $email]) !== null) {
            throw new ConflictException('User email already exists.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($email, $paymentToken): User {
            $user = new User($email, $paymentToken);
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->eventStore->append(
                $user->id(),
                'User',
                $user->id(),
                null,
                EventType::UserRegistered,
                ['email' => $user->email()],
            );
            $this->entityManager->flush();

            return $user;
        });
    }
}
