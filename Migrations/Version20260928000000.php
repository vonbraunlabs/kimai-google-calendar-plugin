<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260928000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the tables for the Google Calendar plugin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE kimai2_google_calendar_accounts (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, google_email VARCHAR(180) DEFAULT NULL, access_token LONGTEXT DEFAULT NULL, refresh_token LONGTEXT DEFAULT NULL, token_expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', calendar_id VARCHAR(255) NOT NULL, sync_events TINYINT(1) DEFAULT 1 NOT NULL, sync_tasks TINYINT(1) DEFAULT 0 NOT NULL, task_duration INT DEFAULT 30 NOT NULL, skip_declined TINYINT(1) DEFAULT 1 NOT NULL, skip_free TINYINT(1) DEFAULT 0 NOT NULL, mapping_rules LONGTEXT DEFAULT NULL, UNIQUE INDEX gcal_account_user_uniq (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE kimai2_google_calendar_links (id INT AUTO_INCREMENT NOT NULL, account_id INT NOT NULL, timesheet_id INT DEFAULT NULL, source_type VARCHAR(10) NOT NULL, source_id VARCHAR(255) NOT NULL, title VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_A59885089B6B5FBA (account_id), INDEX IDX_A5988508ABDD46BE (timesheet_id), UNIQUE INDEX gcal_link_source_uniq (account_id, source_type, source_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE kimai2_google_calendar_accounts ADD CONSTRAINT FK_DA642574A76ED395 FOREIGN KEY (user_id) REFERENCES kimai2_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_google_calendar_links ADD CONSTRAINT FK_A59885089B6B5FBA FOREIGN KEY (account_id) REFERENCES kimai2_google_calendar_accounts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_google_calendar_links ADD CONSTRAINT FK_A5988508ABDD46BE FOREIGN KEY (timesheet_id) REFERENCES kimai2_timesheet (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kimai2_google_calendar_links');
        $this->addSql('DROP TABLE kimai2_google_calendar_accounts');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
