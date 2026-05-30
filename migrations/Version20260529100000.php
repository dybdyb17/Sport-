<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suppression TRIO : migre les bookings/subscriptions trio → duo (persons_count=2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE booking SET format='duo', persons_count=2 WHERE format='trio'");
        $this->addSql("UPDATE subscription SET format='duo', persons_count=2 WHERE format='trio'");
    }

    public function down(Schema $schema): void
    {
        // Irréversible : on ne peut pas savoir quels duos étaient des trios
        $this->addSql('SELECT 1');
    }
}
