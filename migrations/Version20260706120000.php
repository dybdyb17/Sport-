<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les offres promotionnelles payables par lien Stripe (Instagram, ads, bio).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE promo_offer (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(140) NOT NULL, slug VARCHAR(160) NOT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(40) NOT NULL, price NUMERIC(8, 2) NOT NULL, currency VARCHAR(3) DEFAULT 'eur' NOT NULL, status VARCHAR(20) DEFAULT 'draft' NOT NULL, max_quantity INT DEFAULT NULL, starts_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ends_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_PROMO_OFFER_SLUG (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE promo_purchase (id INT AUTO_INCREMENT NOT NULL, offer_id INT NOT NULL, reference VARCHAR(20) NOT NULL, buyer_name VARCHAR(120) NOT NULL, buyer_email VARCHAR(180) NOT NULL, buyer_phone VARCHAR(30) DEFAULT NULL, status VARCHAR(20) DEFAULT 'pending' NOT NULL, amount NUMERIC(8, 2) NOT NULL, currency VARCHAR(3) DEFAULT 'eur' NOT NULL, stripe_checkout_session_id VARCHAR(255) DEFAULT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, paid_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', qr_token VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_PROMO_PURCHASE_OFFER (offer_id), UNIQUE INDEX UNIQ_PROMO_PURCHASE_REFERENCE (reference), UNIQUE INDEX UNIQ_PROMO_PURCHASE_QR_TOKEN (qr_token), CONSTRAINT FK_PROMO_PURCHASE_OFFER FOREIGN KEY (offer_id) REFERENCES promo_offer (id) ON DELETE CASCADE, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promo_purchase DROP FOREIGN KEY FK_PROMO_PURCHASE_OFFER');
        $this->addSql('DROP TABLE promo_purchase');
        $this->addSql('DROP TABLE promo_offer');
    }
}
