<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le check-in coach pour les achats promo Instagram.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE promo_purchase ADD checkin_by_id INT DEFAULT NULL, ADD checkin_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('CREATE INDEX IDX_PROMO_PURCHASE_CHECKIN_BY ON promo_purchase (checkin_by_id)');
        $this->addSql('ALTER TABLE promo_purchase ADD CONSTRAINT FK_PROMO_PURCHASE_CHECKIN_BY FOREIGN KEY (checkin_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promo_purchase DROP FOREIGN KEY FK_PROMO_PURCHASE_CHECKIN_BY');
        $this->addSql('DROP INDEX IDX_PROMO_PURCHASE_CHECKIN_BY ON promo_purchase');
        $this->addSql('ALTER TABLE promo_purchase DROP checkin_by_id, DROP checkin_at');
    }
}
