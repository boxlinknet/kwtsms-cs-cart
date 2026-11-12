<?php

/*
 * FILE:      upgrades/1.4.0/migrations/20261112020222_kwtsms_1_4_0.php
 * CREATED:   2026-11-12 02:02 +03
 * TASK:      596 M3.0
 * AUTHOR:    kwtSMS
 * WHAT:      Phinx migration that takes a 1.3.0 install of the kwtSMS add-on to 1.4.0: creates kwtsms_event_conditions
 *            and kwtsms_vendor_senders. DDL only.
 * WHY:       Milestone 3 (PLAN-1.4.0 "Data model", P3-80). The Upgrade Center runs migrations BEFORE it copies the
 *            package files, so this file must not need any 1.4.0 add-on code: the settings, the language variables and
 *            the version are set by scripts/post.php (PostUpgrade140::run()), which runs after the copy. The migration
 *            creates no row (no condition row, no vendor sender row), turns no feature on and sends nothing. The DDL
 *            is identical to app/addons/kwtsms/addon.xml; a test compares SHOW CREATE TABLE of an upgraded database
 *            with a fresh install. The class is Kwtsms140 because Phinx derives it from the file name
 *            (Util::mapFileNameToClassName).
 * USAGE:     Upgrade Center > Upload upgrade package (tools/build-upgrade-package.sh --from 1.3.0 --to 1.4.0, or an
 *            earlier --from, which also carries the migrations in between). Tests:
 *            (new Kwtsms140('development', 20261112020222))->up(), or ->schema($prefix) on a scratch prefix.
 * DEPENDS:   Phinx (AbstractMigration), CS-Cart db_* functions.
 */

defined('BOOTSTRAP') or die('Access denied');

use Phinx\Migration\AbstractMigration;
use Tygh\Registry;

class Kwtsms140 extends AbstractMigration
{
    /**
     * DDL of the two new tables, identical to app/addons/kwtsms/addon.xml. {t} is the full table name.
     */
    private const NEW_TABLES = [
        'kwtsms_event_conditions' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`event_id` varchar(128) NOT NULL DEFAULT \'\','
            . '`receiver` varchar(15) NOT NULL DEFAULT \'\','
            . '`storefront_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`conditions` text,'
            . '`updated_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`event_id`, `receiver`, `storefront_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET UTF8',
        'kwtsms_vendor_senders' => 'CREATE TABLE IF NOT EXISTS {t} ('
            . '`company_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`sender_id` varchar(11) NOT NULL DEFAULT \'\','
            . '`updated_by` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`updated_at` int(11) unsigned NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`company_id`)'
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
                throw new \RuntimeException('kwtsms 1.4.0 migration: ' . $name . ' was not created');
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
