<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Check;

/**
 * A package a checked package needs, with its license as Composer
 * installed it. Not installed here means version and license unknown.
 */
final readonly class Dependency implements \JsonSerializable
{
    private const string COPYLEFT = '/^(?:A?GPL|LGPL|MPL)\b/i';

    /**
     * @param list<string> $license Several mean "one of them"
     */
    public function __construct(
        public string $name,
        public bool $installed,
        public ?string $version = null,
        public array $license = [],
    ) {}

    /**
     * Copyleft only when every license to choose from is one (decision
     * 0181): GPL, AGPL, LGPL or MPL.
     */
    public function copyleft(): bool
    {
        if ([] === $this->license) {
            return false;
        }

        foreach ($this->license as $license) {
            if (1 !== preg_match(self::COPYLEFT, ltrim($license, '('))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{name: string, installed: bool, version: string|null, license: list<string>}
     */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'installed' => $this->installed, 'version' => $this->version, 'license' => $this->license];
    }
}
