<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create billing API users, subscriptions, and events tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id VARCHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, payment_token VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (email)');

        $this->addSql('CREATE TABLE subscriptions (id VARCHAR(36) NOT NULL, user_id VARCHAR(36) NOT NULL, plan VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, current_period_start DATETIME NOT NULL, current_period_end DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id), CONSTRAINT FK_SUBSCRIPTIONS_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_subscriptions_user ON subscriptions (user_id)');

        $this->addSql('CREATE TABLE events (id VARCHAR(36) NOT NULL, aggregate_id VARCHAR(36) NOT NULL, aggregate_type VARCHAR(50) NOT NULL, user_id VARCHAR(36) DEFAULT NULL, subscription_id VARCHAR(36) DEFAULT NULL, event_type VARCHAR(100) NOT NULL, payload CLOB NOT NULL --(DC2Type:json)
        , previous_status VARCHAR(20) DEFAULT NULL, new_status VARCHAR(20) DEFAULT NULL, external_event_id VARCHAR(255) DEFAULT NULL, occurred_at DATETIME NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_events_user ON events (user_id)');
        $this->addSql('CREATE INDEX idx_events_external_event ON events (external_event_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_events_external_event_type ON events (external_event_id, event_type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE events');
        $this->addSql('DROP TABLE subscriptions');
        $this->addSql('DROP TABLE users');
    }
}
