<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Component\DefinitionErrors;
use Frootbox\Core\Component\DefinitionReader;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\Theme\ThemeException;

/**
 * The navigations of the active theme and its parents, children
 * overriding their parents by name (decision 0106).
 */
final class Definitions
{
    /** @var array<string, Definition>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly ThemeChain $themes,
        private readonly DefinitionReader $reader,
    ) {}

    /**
     * @return array<string, Definition> by name, in the order the themes list them
     */
    public function all(): array
    {
        return $this->definitions ??= $this->read();
    }

    public function get(string $name): ?Definition
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return array<string, Definition>
     */
    private function read(): array
    {
        $definitions = [];

        foreach (array_reverse($this->themes->themes) as $theme) {
            $where = \sprintf('"theme.navigations" in %s of %s', ExtensionPackage::MANIFEST, $theme->name);

            foreach ($theme->navigations as $name => $raw) {
                $definitions[$name] = $this->definition($name, $raw, $theme->name, $where);
            }
        }

        return $definitions;
    }

    /**
     * @param array<mixed> $raw
     */
    private function definition(string $name, array $raw, string $package, string $where): Definition
    {
        $unknown = array_diff(array_map(strval(...), array_keys($raw)), ['title', 'depth', 'options']);
        $title = $raw['title'] ?? null;
        $depth = $raw['depth'] ?? 1;

        $fail = static fn(string $problem): ThemeException => new ThemeException(\sprintf('%s: "%s" %s.', $where, $name, $problem));

        if (1 !== preg_match('/' . Definition::NAME_PATTERN . '/', $name)) {
            throw $fail('must be lower case a-z, 0-9 and hyphens, e.g. "main"');
        }

        if ([] !== $unknown) {
            throw $fail(\sprintf('has unknown keys: %s. Allowed are title, depth and options', implode(', ', $unknown)));
        }

        if (!\is_string($title) || '' === $title) {
            throw $fail('needs a title, e.g. "main: Main navigation"');
        }

        if (!\is_int($depth) || $depth < 1 || $depth > Definition::MAX_DEPTH) {
            throw $fail(\sprintf('"depth" must be a whole number from 1 to %d', Definition::MAX_DEPTH));
        }

        $errors = new DefinitionErrors();
        $options = $this->reader->read(['options' => $raw['options'] ?? null], ['options'], false, $package, $where . ' ' . $name, $errors)->options;

        if ($errors->count() > 0) {
            throw new ThemeException(implode("\n", $errors->all()));
        }

        return new Definition($name, $title, $depth, $options);
    }
}
