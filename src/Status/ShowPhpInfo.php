<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Status;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use ReflectionException;
use ReflectionExtension;

/**
 * How PHP is set up, like phpinfo() but as data and without what it
 * shows of the environment, the request and secrets (decision 0335).
 * For superusers only, as every system.* query.
 *
 * @implements Query<PhpInfo>
 */
#[AsQuery('system.php-info', 'Shows how PHP is set up: versions, SAPI, ini files, OPcache, the most important directives and every loaded extension with its directives, without environment variables and with secrets masked. Search filters extensions and directives by name')]
final readonly class ShowPhpInfo implements Query
{
    /**
     * The directives most often asked for, in this order.
     */
    public const array ESSENTIALS = [
        'memory_limit',
        'max_execution_time',
        'max_input_time',
        'max_input_vars',
        'upload_max_filesize',
        'post_max_size',
        'max_file_uploads',
        'file_uploads',
        'allow_url_fopen',
        'date.timezone',
        'default_charset',
        'display_errors',
        'error_reporting',
        'log_errors',
        'open_basedir',
        'disable_functions',
        'realpath_cache_size',
        'session.save_handler',
        'opcache.enable',
    ];

    public const string MASK = '***';

    /**
     * Names of directives whose value is a secret, e.g. mysqli.default_pw.
     */
    private const string SECRET_NAME = '/(^|[._])(pw|pwd|pass|passwd|password|secret|token|key|auth)([._]|$)/i';

    public function __construct(
        #[Description('Only extensions and directives whose name contains this')]
        #[Length(max: 100)]
        public ?string $search = null,
    ) {}

    public function handle(): PhpInfo
    {
        $settings = $this->settings();
        $search = null === $this->search || '' === trim($this->search) ? null : trim($this->search);

        return new PhpInfo(
            version: \PHP_VERSION,
            zendVersion: zend_version(),
            sapi: \PHP_SAPI,
            os: $this->os(),
            server: \is_string($_SERVER['SERVER_SOFTWARE'] ?? null) && '' !== $_SERVER['SERVER_SOFTWARE'] ? $_SERVER['SERVER_SOFTWARE'] : null,
            iniFile: false === php_ini_loaded_file() ? null : php_ini_loaded_file(),
            scannedFiles: $this->scannedFiles(),
            opcache: $this->opcache(),
            essentials: array_values(array_filter(array_map(static fn(string $name): ?PhpSetting => $settings[$name] ?? null, self::ESSENTIALS))),
            extensions: $this->filter($this->extensions($settings), $search),
            search: $search,
        );
    }

    /**
     * Every directive by name, secrets masked.
     *
     * @return array<string, PhpSetting>
     */
    private function settings(): array
    {
        $settings = [];

        $all = ini_get_all(null, true);

        foreach (false === $all ? [] : $all as $name => $entry) {
            $name = (string) $name;
            $entry = \is_array($entry) ? $entry : [];
            $settings[$name] = new PhpSetting(
                $name,
                self::mask($name, self::text($entry['local_value'] ?? null)),
                self::mask($name, self::text($entry['global_value'] ?? null)),
            );
        }

        ksort($settings);

        return $settings;
    }

    /**
     * The loaded extensions by name, each with its directives. What none
     * claims ends up under Other.
     *
     * @param array<string, PhpSetting> $settings
     *
     * @return list<PhpExtension>
     */
    private function extensions(array $settings): array
    {
        $names = array_unique([...get_loaded_extensions(), ...get_loaded_extensions(true)]);
        natcasesort($names);
        $extensions = [];
        $claimed = [];

        foreach ($names as $name) {
            $own = [];

            try {
                foreach (array_keys(new ReflectionExtension($name)->getINIEntries()) as $directive) {
                    if (isset($settings[$directive]) && !isset($claimed[$directive])) {
                        $own[$directive] = $settings[$directive];
                        $claimed[$directive] = true;
                    }
                }
            } catch (ReflectionException) {
                // A Zend extension without a regular one, e.g. Xdebug.
            }

            ksort($own);
            $version = phpversion($name);
            $extensions[] = new PhpExtension($name, false === $version || '' === $version ? null : $version, array_values($own));
        }

        $other = array_values(array_diff_key($settings, $claimed));

        if ([] !== $other) {
            $extensions[] = new PhpExtension('Other', null, $other);
        }

        return $extensions;
    }

    /**
     * @param list<PhpExtension> $extensions
     *
     * @return list<PhpExtension>
     */
    private function filter(array $extensions, ?string $search): array
    {
        if (null === $search) {
            return $extensions;
        }

        $found = [];

        foreach ($extensions as $extension) {
            if (false !== mb_stripos($extension->name, $search)) {
                $found[] = $extension;

                continue;
            }

            $settings = array_values(array_filter($extension->settings, static fn(PhpSetting $setting): bool => false !== mb_stripos($setting->name, $search)));

            if ([] !== $settings) {
                $found[] = new PhpExtension($extension->name, $extension->version, $settings);
            }
        }

        return $found;
    }

    /**
     * Hides the value of a directive that sounds like a secret, and
     * credentials inside addresses, e.g. tcp://redis:6379?auth=… for sessions.
     */
    public static function mask(string $name, ?string $value): ?string
    {
        if (null === $value || '' === $value) {
            return $value;
        }

        if (1 === preg_match(self::SECRET_NAME, $name)) {
            return self::MASK;
        }

        $value = preg_replace('#(://)[^/@\s]+@#', '$1' . self::MASK . '@', $value) ?? self::MASK;

        return preg_replace('/\b(auth|password|passwd|pass|pwd|secret|token)=[^&;,\s]+/i', '$1=' . self::MASK, $value) ?? self::MASK;
    }

    private static function text(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }

    private function os(): string
    {
        // php_uname() may be among the disabled functions.
        if (!\function_exists('php_uname')) {
            return \PHP_OS_FAMILY;
        }

        return trim(php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m'));
    }

    /**
     * @return list<string>
     */
    private function scannedFiles(): array
    {
        $files = php_ini_scanned_files();

        if (false === $files) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $files)), static fn(string $file): bool => '' !== $file));
    }

    private function opcache(): PhpOpcache
    {
        $status = \function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        if (!\is_array($status) || true !== ($status['opcache_enabled'] ?? null)) {
            return new PhpOpcache(false);
        }

        $memory = \is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
        $statistics = \is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
        $jit = \is_array($status['jit'] ?? null) ? $status['jit'] : [];

        return new PhpOpcache(
            enabled: true,
            memoryUsed: \is_int($memory['used_memory'] ?? null) ? $memory['used_memory'] : null,
            memoryFree: \is_int($memory['free_memory'] ?? null) ? $memory['free_memory'] : null,
            hitRate: \is_float($statistics['opcache_hit_rate'] ?? null) || \is_int($statistics['opcache_hit_rate'] ?? null) ? round((float) $statistics['opcache_hit_rate'], 1) : null,
            jit: true === ($jit['enabled'] ?? null) && true === ($jit['on'] ?? null),
        );
    }
}
