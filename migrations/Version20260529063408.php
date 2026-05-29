<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529063408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE subscription (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(30) NOT NULL, time_slot VARCHAR(255) NOT NULL, format VARCHAR(255) NOT NULL, pack_type VARCHAR(255) NOT NULL, persons_count INT NOT NULL, full_access TINYINT NOT NULL, monthly_price NUMERIC(8, 2) NOT NULL, sessions_remaining INT NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, created_at DATETIME NOT NULL, client_id INT NOT NULL, coach_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_A3C664D3AEA34913 (reference), INDEX IDX_A3C664D319EB6921 (client_id), INDEX IDX_A3C664D33C105691 (coach_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D319EB6921 FOREIGN KEY (client_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D33C105691 FOREIGN KEY (coach_id) REFERENCES coach (id)');
        $this->addSql('ALTER TABLE booking ADD format VARCHAR(255) NOT NULL, ADD time_slot VARCHAR(255) NOT NULL, ADD persons_count INT NOT NULL, ADD subscription_id INT DEFAULT NULL, DROP service_type, CHANGE price price NUMERIC(8, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE9A1887DC FOREIGN KEY (subscription_id) REFERENCES subscription (id)');
        $this->addSql('CREATE INDEX IDX_E00CEDDE9A1887DC ON booking (subscription_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D319EB6921');
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D33C105691');
        $this->addSql('DROP TABLE subscription');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDE9A1887DC');
        $this->addSql('DROP INDEX IDX_E00CEDDE9A1887DC ON booking');
        $this->addSql('ALTER TABLE booking ADD service_type VARCHAR(50) NOT NULL, DROP format, DROP time_slot, DROP persons_count, DROP subscription_id, CHANGE price price NUMERIC(6, 2) DEFAULT NULL');
    }
}
