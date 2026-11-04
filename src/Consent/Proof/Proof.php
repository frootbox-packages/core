<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent\Proof;

/**
 * One choice of a visitor as the browser reports it (decision 0264): the
 * random ID of the choice, the version of the services and yes or no per
 * service. Nothing about the visitor.
 */
final readonly class Proof
{
    public const string ID = '/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i';

    private const string VERSION = '/^[0-9a-f]{1,20}$/';

    private const string SERVICE = '/^[a-z0-9][a-z0-9\/._-]{0,149}$/';

    private const int SERVICES = 100;

    /**
     * @param array<string, bool> $choices by name of the service
     * @param string              $at      when it came, e.g. "2026-11-04 12:00:00"
     */
    public function __construct(
        public string $consentId,
        public string $version,
        public array $choices,
        public string $at,
    ) {}

    /**
     * The proof from what the browser sent, or null if it is not one.
     */
    public static function fromBrowser(mixed $data, string $at): ?self
    {
        if (!\is_array($data) || !\is_string($data['id'] ?? null) || !\is_string($data['version'] ?? null) || !\is_array($data['services'] ?? null)) {
            return null;
        }

        if (1 !== preg_match(self::ID, $data['id']) || 1 !== preg_match(self::VERSION, $data['version']) || \count($data['services']) > self::SERVICES) {
            return null;
        }

        $choices = [];

        foreach ($data['services'] as $name => $choice) {
            if (!\is_string($name) || 1 !== preg_match(self::SERVICE, $name) || !\is_bool($choice)) {
                return null;
            }

            $choices[$name] = $choice;
        }

        ksort($choices);

        return new self(strtolower($data['id']), $data['version'], $choices, $at);
    }

    public static function fromLine(string $line): ?self
    {
        $data = json_decode($line, true);

        return \is_array($data) && \is_string($data['at'] ?? null) ? self::fromBrowser($data, $data['at']) : null;
    }

    public function toLine(): string
    {
        return (string) json_encode(['id' => $this->consentId, 'version' => $this->version, 'services' => $this->choices, 'at' => $this->at], \JSON_UNESCAPED_SLASHES);
    }
}
