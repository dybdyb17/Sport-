<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma initial SPORT+ : user, coach, booking.
 */
final class Version20260528121639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création des tables user, coach et booking (schéma initial SPORT+)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, nom_complet VARCHAR(100) NOT NULL, phone VARCHAR(20) DEFAULT NULL, role VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE coach (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, specialties JSON NOT NULL, hourly_rate NUMERIC(6, 2) NOT NULL, UNIQUE INDEX UNIQ_3F596DCCA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE booking (id INT AUTO_INCREMENT NOT NULL, client_id INT NOT NULL, coach_id INT NOT NULL, reference VARCHAR(20) NOT NULL, service_type VARCHAR(50) NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, status VARCHAR(30) DEFAULT \'pending\' NOT NULL, message LONGTEXT DEFAULT NULL, price NUMERIC(6, 2) DEFAULT NULL, created_at DATETIME NOT NULL, confirmed_at DATETIME DEFAULT NULL, stripe_data JSON DEFAULT NULL, UNIQUE INDEX UNIQ_E00CEDDEAEA34913 (reference), INDEX IDX_E00CEDDE19EB6921 (client_id), INDEX IDX_E00CEDDE3C105691 (coach_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE coach ADD CONSTRAINT FK_3F596DCCA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE19EB6921 FOREIGN KEY (client_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE3C105691 FOREIGN KEY (coach_id) REFERENCES coach (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDE19EB6921');
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDE3C105691');
        $this->addSql('ALTER TABLE coach DROP FOREIGN KEY FK_3F596DCCA76ED395');
        $this->addSql('DROP TABLE booking');
        $this->addSql('DROP TABLE coach');
        $this->addSql('DROP TABLE `user`');
    }
}
