<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Install;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Database\ConnectionFactory;
use Frootbox\Core\Frootbox;
use Frootbox\Core\ProjectPaths;
use Throwable;

/**
 * The steps of "bin/frootbox install" that need no running installation:
 * checking requirements, testing the database and writing .env.
 */
final readonly class Installer
{
    public const string DEFAULT_DATABASE_URL = 'pdo-sqlite:///storage/database.sqlite';

    /** PHP extension by URL scheme of the supported databases. */
    private const array DRIVERS = [
        'pdo-sqlite' => 'pdo_sqlite',
        'pdo-mysql' => 'pdo_mysql',
    ];

    public function __construct(private ProjectPaths $paths) {}

    /**
     * Whether the database is configured, by .env or by the server.
     *
     * @param array<string, string> $serverEnvironment
     */
    public function isConfigured(array $serverEnvironment): bool
    {
        return is_file($this->dotenvFile()) || isset($serverEnvironment['DATABASE_URL']);
    }

    /**
     * What prevents the installation, empty if nothing.
     *
     * @return list<string>
     */
    public function problems(?string $databaseUrl, bool $writesDotenv): array
    {
        $problems = [];

        if (version_compare(\PHP_VERSION, Frootbox::MINIMUM_PHP_VERSION, '<')) {
            $problems[] = \sprintf('Frootbox needs PHP %s or newer, this is PHP %s.', Frootbox::MINIMUM_PHP_VERSION, \PHP_VERSION);
        }

        if (null !== $databaseUrl) {
            $scheme = strtolower((string) strstr($databaseUrl, '://', true));
            $extension = self::DRIVERS[$scheme] ?? null;

            if (null === $extension) {
                $problems[] = \sprintf('The database "%s" is not supported. Use SQLite (pdo-sqlite://) or MySQL/MariaDB (pdo-mysql://).', $scheme);
            } elseif (!\extension_loaded($extension)) {
                $problems[] = \sprintf('The PHP extension %s is missing.', $extension);
            }
        }

        foreach ([$this->paths->var(), $this->paths->storage()] as $folder) {
            if (!is_dir($folder) && !@mkdir($folder, 0o775, true) || !is_writable($folder)) {
                $problems[] = \sprintf('The folder %s must be writable.', $folder);
            }
        }

        if ($writesDotenv && !is_writable($this->paths->root)) {
            $problems[] = \sprintf('The folder %s must be writable to create .env.', $this->paths->root);
        }

        return $problems;
    }

    /**
     * Connects once, so a wrong URL is noticed before anything is written.
     *
     * @return string|null The error, or null if the connection works
     */
    public function testConnection(string $databaseUrl): ?string
    {
        try {
            $connection = ConnectionFactory::create(new Config(Environment::Production, ['database' => ['url' => $databaseUrl]]), $this->paths);
            $connection->executeQuery('SELECT 1');
            $connection->close();
        } catch (Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }

    /**
     * Writes .env from .env.example, readable only for the owner.
     */
    public function writeDotenv(Environment $environment, string $databaseUrl): void
    {
        $example = $this->paths->root . '/.env.example';
        $contents = is_file($example) ? (string) file_get_contents($example) : '';
        $contents = self::set($contents, 'APP_ENV', $environment->value);
        $contents = self::set($contents, 'DATABASE_URL', "'" . $databaseUrl . "'");
        $contents = self::set($contents, 'APP_SECRET', bin2hex(random_bytes(32)));

        if (false === file_put_contents($this->dotenvFile(), $contents)) {
            throw new InstallException(\sprintf('Could not write %s.', $this->dotenvFile()));
        }

        chmod($this->dotenvFile(), 0o600);
    }

    /**
     * A MySQL or MariaDB URL. User, password and name are encoded, so any
     * character works.
     */
    public static function mysqlUrl(string $host, int $port, string $database, string $user, string $password): string
    {
        return \sprintf(
            'pdo-mysql://%s:%s@%s:%d/%s?charset=utf8mb4',
            rawurlencode($user),
            rawurlencode($password),
            $host,
            $port,
            rawurlencode($database),
        );
    }

    /**
     * A random password for the first user when nobody can type one.
     */
    public static function generatePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $password = '';

        for ($i = 0; $i < 20; ++$i) {
            $password .= $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return $password;
    }

    private function dotenvFile(): string
    {
        return $this->paths->root . '/.env';
    }

    /**
     * Replaces "NAME=…" or appends it.
     */
    private static function set(string $contents, string $name, string $value): string
    {
        $line = $name . '=' . $value;
        $pattern = '/^' . $name . '=.*$/m';

        if (1 === preg_match($pattern, $contents)) {
            return (string) preg_replace_callback($pattern, static fn(): string => $line, $contents, 1);
        }

        return rtrim($contents) . ('' === $contents ? '' : "\n") . $line . "\n";
    }
}
