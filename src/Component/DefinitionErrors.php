<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * Collects errors in definitions, so blocks:check shows all at once.
 */
final class DefinitionErrors
{
    /** @var list<string> */
    private array $errors = [];

    /**
     * @param string $where E.g. "acme/website: blocks/Teaser/views/Default.twig"
     * @param string $path  E.g. "fields.title.type", empty for the whole file
     */
    public function add(string $where, string $path, string $message): void
    {
        $this->errors[] = $where . ('' === $path ? '' : ' → ' . $path) . ': ' . $message;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->errors;
    }

    public function count(): int
    {
        return \count($this->errors);
    }
}
