<?php

/*
 * FILE:      app/addons/kwtsms/Tygh/Addons/Kwtsms/Admin/HelpPage.php
 * CREATED:   2026-10-21 04:39 +03
 * TASK:      596
 * AUTHOR:    kwtSMS
 * WHAT: The Help page of the kwtsms admin controller: the facts of THIS install that the help text needs (the exact
 * crontab line from the core root path and config.admin_index, the URL-mode line, whether the core lock backend
 * gives mutual exclusion), the nine kwtSMS links, the SMS length numbers, the template placeholders, and which
 *            pages of the add-on the administrator may open (so the text links only to pages they can see).
 * WHY:       PRD 6.10 and 6.11. The page is plain documentation in English and Arabic (language variables
 *            kwtsms.help.*); only what depends on the install is computed here. The cron line is the one the Settings
 * page shows (php <root>/<admin script> --dispatch=kwtsms_cron.run, a path with unusual characters quoted for the
 *            shell). The URL mode is POST only with the key in the header X-Kwtsms-Cron-Key; the Help page shows a
 * placeholder, never the real key (the key is for the gateway privilege and lives on Settings). Without a lock
 * backend of database or redis the core falls back to a dummy store: there is no mutual exclusion between two
 * cron runs (Task 1.7 concern 3), which the page says. SMS lengths come from MessageCleaner so the text can't
 *            drift from the counter.
 * USAGE:     $page = HelpPage::fromContainer(Tygh::$app);
 *            $view = $page->viewData($has_privilege);    // GET kwtsms.help; callable (string $privilege): bool
 *            HelpPage::LINKS                                // key => https URL
 *            HelpPage::lockGivesMutualExclusion('dummy')  // false
 * DEPENDS: Settings\ConfigInterface, Admin\RequestGate (privilege of each page), Sms\MessageCleaner,
 * Templates\Defaults;
 *            CS-Cart Registry and fn_url() (through protected methods a test replaces).
 */

namespace Tygh\Addons\Kwtsms\Admin;

use Tygh\Addons\Kwtsms\Settings\ConfigInterface;
use Tygh\Addons\Kwtsms\Sms\MessageCleaner;
use Tygh\Addons\Kwtsms\Templates\Defaults;

class HelpPage
{
    /**
     * The kwtSMS pages the help text links to: PRD 6.10's list and the API documentation PDF. Each answered HTTP 200 on
     * 2026-10-21 (curl check in the Task 1.15 report). External links carry rel="noopener noreferrer" in the template.
     *
     * @var array<string,string>
     */
    public const LINKS = [
        'home' => 'https://www.kwtsms.com',
        'support' => 'https://www.kwtsms.com/support.html',
        'sender_id' => 'https://www.kwtsms.com/sender-id-help.html',
        'faq' => 'https://www.kwtsms.com/faq_all.php',
        'api_docs' => 'https://www.kwtsms.com/api-documentation.html',
        'integrations' => 'https://www.kwtsms.com/integrations.html',
        'developers' => 'https://www.kwtsms.com/developers.html',
        'best_practices' => 'https://www.kwtsms.com/articles/sms-api-implementation-best-practices.html',
        'api_pdf' => 'https://www.kwtsms.com/doc/KwtSMS.com_API_Documentation_v41.pdf',
    ];

    /** Core lock backends that give mutual exclusion; any other value falls back to the core dummy store. */
    private const LOCKING_BACKENDS = ['database', 'redis'];

    /** The pages of the add-on the text may link to, in the order of PRD 7. */
    private const PAGES = ['dashboard', 'settings', 'templates', 'integrations', 'admin_alerts', 'logs'];

    /** What the cron controller is called by. */
    private const DISPATCH = 'kwtsms_cron.run';

    /** @var ConfigInterface */
    private $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * @param \ArrayAccess<string,mixed> $app Tygh::$app
     */
    public static function fromContainer($app): self
    {
        return new self($app['kwtsms.config']);
    }

    /** True for the lock backends that stop two cron runs from overlapping. */
    public static function lockGivesMutualExclusion(string $backend): bool
    {
        return in_array($backend, self::LOCKING_BACKENDS, true);
    }

    /**
     * Everything the template shows besides the static text.
     *
     * @param callable $has_privilege (string $privilege): bool
     *
     * @return array<string,mixed>
     */
    public function viewData(callable $has_privilege): array
    {
        $root = rtrim($this->rootDir(), '/');
        $script = trim($this->adminIndex());
        $script = $script === '' ? 'admin.php' : $script;
        $command = 'php ' . self::shellPath($root . '/' . $script) . ' --dispatch=' . self::DISPATCH;
        $backend = $this->lockBackend();

        $pages = [];
        foreach (self::PAGES as $mode) {
            // A page that is not built yet (not in Navigation::PAGES) is never linked: it would answer 404.
            $allowed = isset(RequestGate::MODES[$mode])
                && call_user_func($has_privilege, RequestGate::MODES[$mode][1]) === true;
            $pages[$mode] = $allowed && in_array($mode, Navigation::PAGES, true);
        }

        return [
            'cron' => [
                'root' => $root,
                'admin_index' => $script,
                'command' => $command,
                'line' => '* * * * * ' . $command,
                'url' => $this->cronUrl(),
                // POST only; the key travels in a header, never in the query string; the real key is not shown here.
                'curl' => "curl -fsS -X POST -H 'X-Kwtsms-Cron-Key: YOUR_CRON_KEY' " . self::shellArg($this->cronUrl()),
                'url_enabled' => $this->config->cronUrlEnabled(),
                'lock_backend' => $backend,
                'lock_ok' => self::lockGivesMutualExclusion($backend),
            ],
            'links' => self::LINKS,
            'sms' => [
                'gsm_single' => MessageCleaner::GSM_SINGLE,
                'gsm_multi' => MessageCleaner::GSM_MULTI,
                'unicode_single' => MessageCleaner::UNICODE_SINGLE,
                'unicode_multi' => MessageCleaner::UNICODE_MULTI,
                'max_pages' => MessageCleaner::MAX_PAGES,
            ],
            'placeholders' => Defaults::PLACEHOLDERS,
            'pages' => $pages,
        ];
    }

    /** A path as one shell word: left alone when it holds only safe characters, else single-quoted. */
    private static function shellPath(string $path): string
    {
        return preg_match('~^[A-Za-z0-9_./:@%+=,-]+$~', $path) === 1 ? $path : self::quote($path);
    }

    /** A URL as one single-quoted shell word. */
    private static function shellArg(string $url): string
    {
        return self::quote($url);
    }

    private static function quote(string $text): string
    {
        return "'" . str_replace("'", "'\\''", $text) . "'";
    }

    // ================================================================== seams

    /** The core root directory of this install (config.dir.root). */
    protected function rootDir(): string
    {
        return (string) \Tygh\Registry::get('config.dir.root');
    }

    /** The admin script of this install (config.admin_index, admin.php unless renamed). */
    protected function adminIndex(): string
    {
        return (string) \Tygh\Registry::get('config.admin_index');
    }

    /** The URL of the cron controller (URL mode). */
    protected function cronUrl(): string
    {
        return (string) fn_url(self::DISPATCH, 'A', 'current');
    }

    /** The core lock backend (config.lock_backend). */
    protected function lockBackend(): string
    {
        return (string) \Tygh\Registry::get('config.lock_backend');
    }
}
