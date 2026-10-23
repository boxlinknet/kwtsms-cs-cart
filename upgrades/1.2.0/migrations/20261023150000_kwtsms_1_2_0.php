<?php

/*
 * FILE:      upgrades/1.2.0/migrations/20261023150000_kwtsms_1_2_0.php
 * CREATED:   2026-10-23 17:32 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Phinx migration that takes a 1.1.0 install of the kwtSMS add-on to 1.2.0 (the phone and SMS-code
 *            release): creates kwtsms_otp_codes, kwtsms_rate_limits and kwtsms_phone_index. DDL only.
 * WHY:       Phase 3 Milestone 1 (PRD 8 amended by Task M1.1a). The Upgrade Center runs migrations BEFORE it copies
 *            the package files (P2-R6), so this file must not need any 1.2.0 add-on code: the settings, the
 *            language variables and the version are set by scripts/post.php (PostUpgrade::run()), which runs after
 *            the copy. The migration creates no row, turns no feature on and starts no phone processing (P3-31).
 *            The DDL is identical to app/addons/kwtsms/addon.xml; a test compares SHOW CREATE TABLE of an upgraded
 *            database with a fresh install. The class is Kwtsms120 because Phinx derives it from the file name
 *            (Util::mapFileNameToClassName).
 * USAGE:     Upgrade Center > Upload upgrade package (tools/build-upgrade-package.sh --from 1.1.0 --to 1.2.0, or
 *            --from 1.0.0 --to 1.2.0, which also carries the 1.1.0 migration). Tests:
 *            (new Kwtsms120('development', 20261023150000))->up(), or ->schema($prefix) on a scratch prefix.
 * DEPENDS:   Phinx (AbstractMigration), CS-Cart db_* functions.
 */

defined('BOOTSTRAP') or die('Access denied');

use Phinx\Migration\AbstractMigration;
use Tygh\Registry;

class Kwtsms120 extends AbstractMigration
{
    /**
     * DDL of the three new tables, identical to app/addons/kwtsms/addon.xml. {t} is the full table name.
     */
    private const NEW_TABLES = [
        'kwtsms_otp_codes' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`code_id` int(11) unsigned NOT NULL AUTO_INCREMENT,'
            . '`purpose` varchar(16) NOT NULL DEFAULT \'\','
            . '`subject` varchar(32) NOT NULL DEFAULT \'\','
            . '`user_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`session_hash` char(64) NOT NULL DEFAULT \'\','
            . '`code_hash` char(64) NOT NULL DEFAULT \'\','
            . '`salt` char(32) NOT NULL DEFAULT \'\','
            . '`status` char(1) NOT NULL DEFAULT \'A\','
            . '`created_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`expires_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`ip` varchar(45) NOT NULL DEFAULT \'\','
            . '`storefront_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`code_id`),'
            . 'KEY `purpose_subject` (`purpose`, `subject`, `status`),'
            . 'KEY `idx_created_at` (`created_at`),'
            . 'KEY `idx_user_id` (`user_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET UTF8',
        'kwtsms_rate_limits' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`bucket` varchar(128) NOT NULL DEFAULT \'\','
            . '`hits` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`window_start` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`last_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`locked_until` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`bucket`),'
            . 'KEY `idx_last_at` (`last_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET UTF8',
        'kwtsms_phone_index' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`user_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`phone` varchar(32) NOT NULL DEFAULT \'\','
            . '`verified_phone` varchar(32) DEFAULT NULL,'
            . '`verified_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`updated_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`user_id`),'
            . 'KEY `idx_phone` (`phone`),'
            . 'UNIQUE KEY `uniq_verified_phone` (`verified_phone`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET UTF8',
    ];

    /**
     * Phinx entry point (up only). DDL only: the files, settings, language variables and version come from
     * scripts/post.php after the file copy.
     */
    public function up(): void
    {
        $this->schema((string) Registry::get('config.table_prefix'));
    }

    /**
     * Creates the three tables. Idempotent: a table that exists is left alone.
     *
     * @param string $prefix Table prefix (the store's, or a scratch prefix in a test)
     *
     * @return void
     */
    public function schema(string $prefix): void
    {
        foreach (self::NEW_TABLES as $name => $ddl) {
            $table = $prefix . $name;
            if (!self::tableExists($table)) {
                db_query(str_replace('{t}', '?p', $ddl), '`' . $table . '`');
            }
            if (!self::tableExists($table)) {
                throw new \RuntimeException('kwtsms 1.2.0 migration: ' . $name . ' was not created');
            }
        }
    }

    private static function tableExists(string $table): bool
    {
        return (int) db_get_field(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?s',
            $table
        ) > 0;
    }
}
