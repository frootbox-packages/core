<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Check;

/**
 * The result of extension:check for one package (decision 0278): what
 * is wrong, what is worth a look and what the package brings. The JSON
 * form is the basis for the report of the marketplace.
 */
final readonly class CheckReport
{
    /**
     * @param list<string>       $license      As in composer.json, several mean "one of them"
     * @param string|null        $frootbox     The constraint for frootbox/core in "require"
     * @param list<CheckFinding> $errors
     * @param list<CheckFinding> $warnings
     * @param list<Dependency>   $dependencies Also those of the dependencies, without descending into frootbox/*
     */
    public function __construct(
        public string $name,
        public ?string $version,
        public array $license,
        public ?string $frootbox,
        public array $errors,
        public array $warnings,
        public CheckContents $contents,
        public array $dependencies,
        public Listing $listing = new Listing('', ''),
    ) {}

    /**
     * Without errors, and with --strict also without hints.
     */
    public function ok(bool $strict = false): bool
    {
        return [] === $this->errors && (!$strict || [] === $this->warnings);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $strict = false): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'license' => $this->license,
            'frootbox' => $this->frootbox,
            'ok' => $this->ok($strict),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'contents' => $this->contents->toArray(),
            'dependencies' => $this->dependencies,
            'listing' => $this->listing->toArray(),
        ];
    }
}
