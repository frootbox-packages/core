<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Storage;

use SensitiveParameter;

/**
 * The address of a store from the .env (decision 0268), e.g.
 *
 *     s3://<key>:<secret>@fsn1.your-objectstorage.com/kunde-daten?region=fsn1
 *
 * User and password are URL-encoded. The path without its first slash
 * is the bucket or folder, the query holds the options.
 */
final readonly class Dsn
{
    /**
     * @param array<string, string> $options
     */
    public function __construct(
        public string $scheme,
        public string $host,
        public ?int $port = null,
        public string $user = '',
        #[SensitiveParameter]
        public string $password = '',
        public string $path = '',
        public array $options = [],
    ) {}

    /**
     * @throws StorageException
     */
    public static function parse(#[SensitiveParameter] string $dsn): self
    {
        $parts = parse_url(trim($dsn));

        if (false === $parts || !isset($parts['scheme'])) {
            throw new StorageException('The DSN of a store has to look like s3://key:secret@host/bucket.');
        }

        parse_str($parts['query'] ?? '', $query);
        $options = [];

        foreach ($query as $key => $value) {
            if (\is_string($value)) {
                $options[(string) $key] = $value;
            }
        }

        return new self(
            strtolower($parts['scheme']),
            $parts['host'] ?? '',
            $parts['port'] ?? null,
            rawurldecode($parts['user'] ?? ''),
            rawurldecode($parts['pass'] ?? ''),
            trim(rawurldecode($parts['path'] ?? ''), '/'),
            $options,
        );
    }

    public function option(string $name, string $default = ''): string
    {
        return $this->options[$name] ?? $default;
    }

    public function flag(string $name): bool
    {
        return \in_array(strtolower($this->option($name)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Without user and password, for messages.
     */
    public function safe(): string
    {
        return $this->scheme . '://' . $this->host . (null === $this->port ? '' : ':' . $this->port) . '/' . $this->path;
    }
}
