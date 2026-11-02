<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\Schema\Fields;
use ReflectionClass;

/**
 * The settings classes of the extensions, by package (decision 0221).
 */
final readonly class SettingsClasses
{
    public const array TYPES = ['string', 'int', 'float', 'bool'];

    /**
     * @param array<string, class-string> $classes by package
     */
    public function __construct(private array $classes = []) {}

    /**
     * @return array<string, class-string> by package, sorted
     */
    public function all(): array
    {
        $classes = $this->classes;
        ksort($classes);

        return $classes;
    }

    /**
     * @return class-string|null
     */
    public function of(string $package): ?string
    {
        return $this->classes[$package] ?? null;
    }

    public function packageOf(string $class): ?string
    {
        $package = array_search($class, $this->classes, true);

        return false === $package ? null : $package;
    }

    /**
     * Reads #[AsSettings] and checks the class.
     *
     * @param class-string $class
     */
    public static function attribute(string $class): AsSettings
    {
        $reflection = new ReflectionClass($class);
        $attribute = $reflection->getAttributes(AsSettings::class)[0] ?? null;

        if (null === $attribute) {
            throw new DefinitionException(\sprintf('%s needs the attribute #[%s].', $class, AsSettings::class));
        }

        if (!$reflection->isFinal() || !$reflection->isReadOnly()) {
            throw new DefinitionException(\sprintf('The settings %s must be a final readonly class.', $class));
        }

        foreach (Fields::of($class) as $field) {
            if (!$field->optional) {
                throw new DefinitionException(\sprintf('The setting %s::$%s needs a default value.', $class, $field->name));
            }

            if (!\in_array($field->type, self::TYPES, true)) {
                throw new DefinitionException(\sprintf('The setting %s::$%s must be a string, int, float or bool.', $class, $field->name));
            }
        }

        return $attribute->newInstance();
    }
}
