<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les références Stripe et la date de paiement aux adhésions Membre Fondateur.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE founding_claim ADD stripe_checkout_session_id VARCHAR(255) DEFAULT NULL, ADD stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, ADD paid_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FOUNDING_CLAIM_STRIPE_SESSION ON founding_claim (stripe_checkout_session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_FOUNDING_CLAIM_STRIPE_SESSION ON founding_claim');
        $this->addSql('ALTER TABLE founding_claim DROP stripe_checkout_session_id, DROP stripe_payment_intent_id, DROP paid_at');
    }
}
