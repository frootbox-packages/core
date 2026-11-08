<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Http;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;

/**
 * The proxies whose X-Forwarded-* headers count (decision 0343), from
 * "http.trusted_proxies" in config/app.yaml: addresses or networks in
 * CIDR, IPv4 and IPv6. Empty by default, then no such header counts.
 */
final readonly class TrustedProxies
{
    /**
     * @var list<array{string, int}> Packed network and prefix length
     */
    private array $networks;

    /**
     * @param list<string> $entries
     */
    public function __construct(array $entries = [])
    {
        $networks = [];

        foreach ($entries as $entry) {
            $networks[] = self::parse($entry);
        }

        $this->networks = $networks;
    }

    public static function fromConfig(Config $config): self
    {
        $entries = $config->get('http.trusted_proxies', []);

        if (null === $entries) {
            return new self();
        }

        if (!\is_array($entries) || !array_is_list($entries)) {
            throw new ConfigException('"http.trusted_proxies" in config/app.yaml must be a list of addresses or networks such as 10.0.0.0/8.');
        }

        return new self(array_map(static fn(mixed $entry): string => \is_string($entry) ? $entry : throw new ConfigException('"http.trusted_proxies" in config/app.yaml must only hold addresses or networks such as 10.0.0.0/8.'), $entries));
    }

    public function any(): bool
    {
        return [] !== $this->networks;
    }

    public function trusts(string $address): bool
    {
        $packed = @inet_pton($address);

        if (false === $packed) {
            return false;
        }

        foreach ($this->networks as [$network, $bits]) {
            if (\strlen($network) === \strlen($packed) && self::within($packed, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{string, int}
     */
    private static function parse(string $entry): array
    {
        $parts = explode('/', trim($entry), 2);
        $packed = @inet_pton($parts[0]);

        if (false === $packed) {
            throw new ConfigException(\sprintf('"%s" in "http.trusted_proxies" is no address or network such as 10.0.0.0/8.', $entry));
        }

        $max = 8 * \strlen($packed);
        $bits = isset($parts[1]) ? (1 === preg_match('/^[0-9]{1,3}$/D', $parts[1]) ? (int) $parts[1] : -1) : $max;

        if ($bits < 0 || $bits > $max) {
            throw new ConfigException(\sprintf('"%s" in "http.trusted_proxies" has a wrong prefix length.', $entry));
        }

        return [$packed, $bits];
    }

    private static function within(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);

        if (0 !== strncmp($address, $network, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if (0 === $rest) {
            return true;
        }

        $mask = 0xFF << (8 - $rest) & 0xFF;

        return (\ord($address[$bytes]) & $mask) === (\ord($network[$bytes]) & $mask);
    }
}
