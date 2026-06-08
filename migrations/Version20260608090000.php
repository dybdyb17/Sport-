<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stocke les photos coachs en BDD pour les conserver après redéploiement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach ADD photo_data LONGTEXT DEFAULT NULL, ADD photo_mime_type VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE coach DROP photo_data, DROP photo_mime_type');
    }
}
