<?php

/*
 * FILE:      upgrades/1.3.0/migrations/20261104190000_kwtsms_1_3_0.php
 * CREATED:   2026-11-04 18:18 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT:      Phinx migration that takes a 1.2.0 install of the kwtSMS add-on to 1.3.0 (sign-in and security): creates
 *            kwtsms_twofa and kwtsms_backup_codes. DDL only.
 * WHY:       Milestone 2 (PRD 8, amended by Task M2.0). The Upgrade Center runs migrations BEFORE it copies the package
 *            files (P2-R6), so this file must not need any 1.3.0 add-on code: the settings, the language variables and
 *            the version are set by scripts/post.php (PostUpgrade130::run()), which runs after the copy. The migration
 *            creates no row (no kwtsms_twofa row, ever, PRD 13), turns no feature on and holds nobody at login. The
 *            DDL is identical to app/addons/kwtsms/addon.xml; a test compares SHOW CREATE TABLE of an upgraded database
 *            with a fresh install. The class is Kwtsms130 because Phinx derives it from the file name
 *            (Util::mapFileNameToClassName).
 * USAGE:     Upgrade Center > Upload upgrade package (tools/build-upgrade-package.sh --from 1.2.0 --to 1.3.0, or an
 *            earlier --from, which also carries the migrations in between). Tests:
 *            (new Kwtsms130('development', 20261104190000))->up(), or ->schema($prefix) on a scratch prefix.
 * DEPENDS:   Phinx (AbstractMigration), CS-Cart db_* functions.
 */

defined('BOOTSTRAP') or die('Access denied');

use Phinx\Migration\AbstractMigration;
use Tygh\Registry;

class Kwtsms130 extends AbstractMigration
{
    /**
     * DDL of the two new tables, identical to app/addons/kwtsms/addon.xml. {t} is the full table name.
     */
    private const NEW_TABLES = [
        'kwtsms_twofa' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`user_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`enabled` char(1) NOT NULL DEFAULT \'N\','
            . '`phone` varchar(32) NOT NULL DEFAULT \'\','
            . '`enrolled_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`updated_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`user_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET UTF8',
        'kwtsms_backup_codes' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`code_id` int(11) unsigned NOT NULL AUTO_INCREMENT,'
            . '`user_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`code_hash` char(64) NOT NULL DEFAULT \'\','
            . '`salt` char(32) NOT NULL DEFAULT \'\','
            . '`created_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`used_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`code_id`),'
            . 'KEY `idx_user_id` (`user_id`, `used_at`)'
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
     * Creates the two tables. Idempotent: a table that exists is left alone (hasTable check by information_schema).
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
                throw new \RuntimeException('kwtsms 1.3.0 migration: ' . $name . ' was not created');
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
