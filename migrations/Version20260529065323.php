<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529065323 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE founding_claim (id INT AUTO_INCREMENT NOT NULL, claim_number INT NOT NULL, sessions_used INT NOT NULL, bilan_done_at DATETIME DEFAULT NULL, claimed_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, offer_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_D4339E653C674EE (offer_id), UNIQUE INDEX UNIQ_D4339E6A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE founding_offer (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, title VARCHAR(100) NOT NULL, description LONGTEXT NOT NULL, total_places INT NOT NULL, places_taken INT NOT NULL, sessions_included INT NOT NULL, price NUMERIC(8, 2) NOT NULL, regular_price NUMERIC(8, 2) DEFAULT NULL, includes_free_bilan TINYINT NOT NULL, includes_priority_night TINYINT NOT NULL, badge_name VARCHAR(50) NOT NULL, is_active TINYINT NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_83FC60FF77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE founding_claim ADD CONSTRAINT FK_D4339E653C674EE FOREIGN KEY (offer_id) REFERENCES founding_offer (id)');
        $this->addSql('ALTER TABLE founding_claim ADD CONSTRAINT FK_D4339E6A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE booking ADD covered_by VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE founding_claim DROP FOREIGN KEY FK_D4339E653C674EE');
        $this->addSql('ALTER TABLE founding_claim DROP FOREIGN KEY FK_D4339E6A76ED395');
        $this->addSql('DROP TABLE founding_claim');
        $this->addSql('DROP TABLE founding_offer');
        $this->addSql('ALTER TABLE booking DROP covered_by');
    }
}
