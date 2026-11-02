<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema: accounts and profiles, companies and offers, the application pipeline,
 * saved views, document templates, relevance scores, briefs and the two audit tables.
 *
 * Reviewed by hand for MariaDB 11.4: UUIDv7 primary keys are stored as BINARY(16), every table
 * is InnoDB / utf8mb4 / utf8mb4_unicode_ci (MyISAM would accept the foreign keys and silently
 * discard them), and job_offer carries the FULLTEXT index the keyword search needs.
 * Reversible: down() drops the foreign keys before the tables.
 */
final class Version20260930093447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial boardtheboat schema (users, companies, offers, applications, views, briefs).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, locale VARCHAR(5) NOT NULL, verified TINYINT NOT NULL, active TINYINT NOT NULL, last_login_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE application (id BINARY(16) NOT NULL, pipeline_status VARCHAR(20) NOT NULL, target_role VARCHAR(180) DEFAULT NULL, contact_channel VARCHAR(20) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, applied_at DATETIME DEFAULT NULL, follow_up_at DATETIME DEFAULT NULL, relance_count INT NOT NULL, last_relance_at DATETIME DEFAULT NULL, snoozed_until DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id BINARY(16) NOT NULL, company_id BINARY(16) NOT NULL, job_offer_id BINARY(16) DEFAULT NULL, letter_template_id BINARY(16) DEFAULT NULL, cv_template_id BINARY(16) DEFAULT NULL, INDEX idx_application_owner_status (owner_id, pipeline_status), INDEX idx_application_owner_followup (owner_id, follow_up_at), INDEX idx_application_company (company_id), UNIQUE INDEX uniq_application_owner_target (owner_id, company_id, job_offer_id), INDEX IDX_A45BDDC17E3C61F9 (owner_id), INDEX IDX_A45BDDC13481D195 (job_offer_id), INDEX IDX_A45BDDC16E71D666 (letter_template_id), INDEX IDX_A45BDDC11BA40B45 (cv_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE company (id BINARY(16) NOT NULL, visibility VARCHAR(16) NOT NULL, name VARCHAR(200) NOT NULL, normalized_name VARCHAR(200) NOT NULL, siren VARCHAR(9) DEFAULT NULL, website VARCHAR(255) DEFAULT NULL, domain VARCHAR(180) DEFAULT NULL, career_url VARCHAR(500) DEFAULT NULL, sector VARCHAR(120) DEFAULT NULL, naf_code VARCHAR(8) DEFAULT NULL, size VARCHAR(16) NOT NULL, city VARCHAR(120) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, source VARCHAR(40) NOT NULL, external_id VARCHAR(120) DEFAULT NULL, career_page_status VARCHAR(16) NOT NULL, last_checked_at DATETIME DEFAULT NULL, notes LONGTEXT DEFAULT NULL, why_interesting LONGTEXT DEFAULT NULL, fingerprint VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id BINARY(16) DEFAULT NULL, INDEX idx_company_owner_visibility (owner_id, visibility), INDEX idx_company_normalized_name (normalized_name), INDEX idx_company_domain (domain), INDEX idx_company_career_status (career_page_status, last_checked_at), UNIQUE INDEX uniq_company_source_external (source, external_id), INDEX IDX_4FBF094F7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE company_brief (id BINARY(16) NOT NULL, status VARCHAR(16) NOT NULL, summary LONGTEXT DEFAULT NULL, activities LONGTEXT DEFAULT NULL, recent_news LONGTEXT DEFAULT NULL, values_keywords JSON NOT NULL, letter_hooks JSON NOT NULL, cv_keywords JSON NOT NULL, source_urls JSON NOT NULL, generator VARCHAR(40) DEFAULT NULL, generated_at DATETIME DEFAULT NULL, failure_reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, company_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_584AB262979B1AD6 (company_id), INDEX idx_brief_status_generated (status, generated_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE document_template (id BINARY(16) NOT NULL, kind VARCHAR(20) NOT NULL, name VARCHAR(120) NOT NULL, language VARCHAR(5) NOT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id BINARY(16) NOT NULL, INDEX idx_template_owner_kind (owner_id, kind), UNIQUE INDEX uniq_template_owner_kind_name (owner_id, kind, name), INDEX IDX_18A1EEDA7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE import_batch (id BINARY(16) NOT NULL, source VARCHAR(40) NOT NULL, filename VARCHAR(255) DEFAULT NULL, status VARCHAR(16) NOT NULL, counts JSON NOT NULL, errors JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, created_by_id BINARY(16) DEFAULT NULL, INDEX idx_import_batch_status (status, created_at), INDEX IDX_155EFD1AB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE job_offer (id BINARY(16) NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, location VARCHAR(180) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, work_mode VARCHAR(16) NOT NULL, contract_type VARCHAR(20) NOT NULL, status VARCHAR(16) NOT NULL, source VARCHAR(40) NOT NULL, external_id VARCHAR(120) NOT NULL, published_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, url VARCHAR(1000) NOT NULL, salary_text VARCHAR(180) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, company_id BINARY(16) NOT NULL, INDEX idx_offer_status_published (status, published_at), INDEX idx_offer_company (company_id), INDEX idx_offer_country_contract (country, contract_type), FULLTEXT INDEX ft_offer_title_description (title, description), UNIQUE INDEX uniq_offer_source_external (source, external_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE pipeline_run (id BINARY(16) NOT NULL, status VARCHAR(16) NOT NULL, trigger_source VARCHAR(20) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, counts JSON NOT NULL, errors JSON NOT NULL, INDEX idx_pipeline_run_started (started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE profile (id BINARY(16) NOT NULL, cv_text LONGTEXT DEFAULT NULL, target_roles JSON NOT NULL, keywords JSON NOT NULL, sectors JSON NOT NULL, skills JSON NOT NULL, preferred_locations JSON NOT NULL, languages JSON NOT NULL, remote_preference VARCHAR(16) NOT NULL, default_region VARCHAR(16) NOT NULL, internship_start_date DATE DEFAULT NULL, internship_duration_weeks INT DEFAULT NULL, relance_delay_days INT NOT NULL, max_relances INT NOT NULL, top_n INT NOT NULL, fingerprint VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_8157AA0FA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE relevance_score (id BINARY(16) NOT NULL, score INT NOT NULL, breakdown JSON NOT NULL, reasons JSON NOT NULL, profile_fingerprint VARCHAR(64) NOT NULL, company_fingerprint VARCHAR(64) NOT NULL, computed_at DATETIME NOT NULL, user_id BINARY(16) NOT NULL, company_id BINARY(16) NOT NULL, INDEX idx_relevance_user_score (user_id, score), UNIQUE INDEX uniq_relevance_user_company (user_id, company_id), INDEX IDX_6755E29FA76ED395 (user_id), INDEX IDX_6755E29F979B1AD6 (company_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE saved_view (id BINARY(16) NOT NULL, name VARCHAR(80) NOT NULL, filters JSON NOT NULL, sort VARCHAR(40) NOT NULL, is_default TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id BINARY(16) NOT NULL, INDEX idx_saved_view_owner_default (owner_id, is_default), UNIQUE INDEX uniq_saved_view_owner_name (owner_id, name), INDEX IDX_F0C2599B7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE application ADD CONSTRAINT FK_A45BDDC17E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE application ADD CONSTRAINT FK_A45BDDC1979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE application ADD CONSTRAINT FK_A45BDDC13481D195 FOREIGN KEY (job_offer_id) REFERENCES job_offer (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE application ADD CONSTRAINT FK_A45BDDC16E71D666 FOREIGN KEY (letter_template_id) REFERENCES document_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE application ADD CONSTRAINT FK_A45BDDC11BA40B45 FOREIGN KEY (cv_template_id) REFERENCES document_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE company ADD CONSTRAINT FK_4FBF094F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE company_brief ADD CONSTRAINT FK_584AB262979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_template ADD CONSTRAINT FK_18A1EEDA7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE import_batch ADD CONSTRAINT FK_155EFD1AB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE job_offer ADD CONSTRAINT FK_288A3A4E979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE profile ADD CONSTRAINT FK_8157AA0FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE relevance_score ADD CONSTRAINT FK_6755E29FA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE relevance_score ADD CONSTRAINT FK_6755E29F979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE saved_view ADD CONSTRAINT FK_F0C2599B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE application DROP FOREIGN KEY FK_A45BDDC17E3C61F9');
        $this->addSql('ALTER TABLE application DROP FOREIGN KEY FK_A45BDDC1979B1AD6');
        $this->addSql('ALTER TABLE application DROP FOREIGN KEY FK_A45BDDC13481D195');
        $this->addSql('ALTER TABLE application DROP FOREIGN KEY FK_A45BDDC16E71D666');
        $this->addSql('ALTER TABLE application DROP FOREIGN KEY FK_A45BDDC11BA40B45');
        $this->addSql('ALTER TABLE company DROP FOREIGN KEY FK_4FBF094F7E3C61F9');
        $this->addSql('ALTER TABLE company_brief DROP FOREIGN KEY FK_584AB262979B1AD6');
        $this->addSql('ALTER TABLE document_template DROP FOREIGN KEY FK_18A1EEDA7E3C61F9');
        $this->addSql('ALTER TABLE import_batch DROP FOREIGN KEY FK_155EFD1AB03A8386');
        $this->addSql('ALTER TABLE job_offer DROP FOREIGN KEY FK_288A3A4E979B1AD6');
        $this->addSql('ALTER TABLE profile DROP FOREIGN KEY FK_8157AA0FA76ED395');
        $this->addSql('ALTER TABLE relevance_score DROP FOREIGN KEY FK_6755E29FA76ED395');
        $this->addSql('ALTER TABLE relevance_score DROP FOREIGN KEY FK_6755E29F979B1AD6');
        $this->addSql('ALTER TABLE saved_view DROP FOREIGN KEY FK_F0C2599B7E3C61F9');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE application');
        $this->addSql('DROP TABLE company');
        $this->addSql('DROP TABLE company_brief');
        $this->addSql('DROP TABLE document_template');
        $this->addSql('DROP TABLE import_batch');
        $this->addSql('DROP TABLE job_offer');
        $this->addSql('DROP TABLE pipeline_run');
        $this->addSql('DROP TABLE profile');
        $this->addSql('DROP TABLE relevance_score');
        $this->addSql('DROP TABLE saved_view');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
