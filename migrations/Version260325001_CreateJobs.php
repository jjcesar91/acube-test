<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version260325001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create jobs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE jobs (
                id          UUID         NOT NULL,
                status      VARCHAR(20)  NOT NULL,
                input_file_path  TEXT    NOT NULL,
                output_file_path TEXT    DEFAULT NULL,
                input_format     VARCHAR(10) NOT NULL,
                output_format    VARCHAR(10) NOT NULL,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                error_message TEXT        DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('COMMENT ON COLUMN jobs.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN jobs.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN jobs.updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE jobs');
    }
}
