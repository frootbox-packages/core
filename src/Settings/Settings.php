<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Field;
use Frootbox\Core\Action\Schema\Fields;
use Frootbox\Core\Action\Schema\InputMapper;
use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Action\Schema\SchemaFactory;
use Frootbox\Core\Action\Schema\Title;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Security\Secrets;
use Frootbox\Database\EntityNotFoundException;

/**
 * Reads and writes the settings of extensions (decision 0221). A value
 * comes from config/extensions/<vendor>/<package>.yaml, else from the
 * database, else the default of the class. Secrets are stored encrypted.
 */
final class Settings
{
    public const string PURPOSE = 'settings';

    /** @var array<class-string, object> */
    private array $objects = [];

    public function __construct(
        private readonly SettingsClasses $classes,
        private readonly StoredSettings $stored,
        private readonly Config $config,
        private readonly Secrets $secrets,
        private readonly InputValidator $validator,
    ) {}

    /**
     * The settings object of an extension with the values in use.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function get(string $class): object
    {
        $object = $this->objects[$class] ?? null;

        if ($object instanceof $class) {
            return $object;
        }

        $package = $this->classes->packageOf($class)
            ?? throw new \LogicException(\sprintf('%s is not registered with $registry->settings().', $class));

        $values = [];

        foreach ($this->read($package, $class) as $name => [$value]) {
            $values[$name] = $value;
        }

        return $this->objects[$class] = InputMapper::toObject($class, $values);
    }

    /**
     * @return list<SettingsDetails>
     */
    public function all(): array
    {
        return array_map($this->details(...), array_keys($this->classes->all()));
    }

    public function details(string $package): SettingsDetails
    {
        $class = $this->classOf($package);
        $attribute = SettingsClasses::attribute($class);
        $schema = SchemaFactory::forClass($class);
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $read = $this->read($package, $class);
        $fields = [];

        foreach (Fields::of($class) as $field) {
            [$value, $locked] = $read[$field->name];
            $secret = $field->isSensitive();
            $property = $properties[$field->name] ?? [];

            $fields[] = new SettingDetails(
                name: $field->name,
                title: $field->attribute(Title::class)->text ?? $field->name,
                description: $field->attribute(Description::class)->text ?? '',
                type: $field->type,
                nullable: $field->nullable,
                default: self::scalar($field->default),
                value: $secret ? null : self::scalar($value),
                secret: $secret,
                set: $secret && null !== $value && '' !== $value,
                locked: $locked,
                schema: self::map($property),
            );
        }

        return new SettingsDetails($package, $attribute->title, $attribute->description, $fields);
    }

    /**
     * Changes values that are not secrets. A value equal to the default
     * removes the stored one. Only inside an action.
     *
     * @param array<string, mixed> $values by name
     */
    public function update(string $action, string $package, array $values): void
    {
        $class = $this->classOf($package);
        $fields = self::fieldsByName($class);
        $locked = $this->locked($package);
        $errors = [];

        // An empty text in a setting that may be null is null, e.g. from a form.
        foreach ($values as $name => $value) {
            if ('' === $value && true === ($fields[$name] ?? null)?->nullable) {
                $values[$name] = null;
            }
        }

        foreach ($values as $name => $value) {
            $field = $fields[$name] ?? null;

            if (null === $field) {
                $errors['/values/' . $name] = 'is not a setting.';
            } elseif ($field->isSensitive()) {
                $errors['/values/' . $name] = 'is a secret, set it with settings.set-secret.';
            } elseif (\array_key_exists($name, $locked)) {
                $errors['/values/' . $name] = 'is set in the configuration and cannot be changed here.';
            }
        }

        if ([] === $errors) {
            $schema = SchemaFactory::forClass($class);
            unset($schema['required']);

            foreach ($this->validator->messages($schema, $values) as $pointer => $message) {
                $errors['/values' . $pointer] = $message;
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $stored = $this->stored->ofPackage($package);

        foreach ($values as $name => $value) {
            $field = $fields[$name];
            $value = 'float' === $field->type && \is_int($value) ? (float) $value : $value;
            $row = $stored[$name] ?? null;

            if ($value === $field->default) {
                if (null !== $row) {
                    $this->stored->delete($row->id);
                }

                continue;
            }

            $json = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);

            if (null === $row) {
                $this->stored->insert(['package' => $package, 'name' => $name, 'value' => $json]);
            } elseif ($row->value !== $json) {
                $this->stored->update($row->id, ['value' => $json]);
            }
        }

        unset($this->objects[$class]);
    }

    /**
     * Sets or, with null, removes a secret. Only inside an action.
     */
    public function setSecret(string $action, string $package, string $name, ?string $value): void
    {
        $class = $this->classOf($package);
        $field = self::fieldsByName($class)[$name] ?? null;

        if (null === $field || !$field->isSensitive()) {
            throw new InvalidInputException($action, ['/name' => 'is not a secret of this extension.']);
        }

        if (\array_key_exists($name, $this->locked($package))) {
            throw new InvalidInputException($action, ['/name' => 'is set in the configuration and cannot be changed here.']);
        }

        if (null !== $value && !$this->secrets->available()) {
            throw new InvalidInputException($action, ['/value' => 'cannot be stored: APP_SECRET in .env is missing or shorter than 16 characters.']);
        }

        if (null !== $value) {
            $errors = $this->validator->messages(SchemaFactory::forClass($class), [$name => $value]);

            if ([] !== $errors) {
                throw new InvalidInputException($action, array_combine(array_map(static fn(string $pointer): string => '/value', array_keys($errors)), $errors));
            }
        }

        $row = $this->stored->ofPackage($package)[$name] ?? null;

        if (null === $value || '' === $value) {
            if (null !== $row) {
                $this->stored->delete($row->id);
            }
        } else {
            $secret = $this->secrets->encrypt(self::PURPOSE, $value);

            if (null === $row) {
                $this->stored->insert(['package' => $package, 'name' => $name, 'secret' => $secret]);
            } else {
                $this->stored->update($row->id, ['value' => null, 'secret' => $secret]);
            }
        }

        unset($this->objects[$class]);
    }

    /**
     * Forgets the objects read so far, e.g. after undo.
     */
    public function reset(): void
    {
        $this->objects = [];
    }

    /**
     * @return class-string
     */
    private function classOf(string $package): string
    {
        return $this->classes->of($package)
            ?? throw new EntityNotFoundException(\sprintf('The extension "%s" has no settings.', $package));
    }

    /**
     * The value of every setting and whether the configuration sets it.
     *
     * @param class-string $class
     *
     * @return array<string, array{mixed, bool}> by name
     */
    private function read(string $package, string $class): array
    {
        $locked = $this->locked($package);
        $stored = $this->stored->ofPackage($package);
        $values = [];

        foreach (Fields::of($class) as $field) {
            if (\array_key_exists($field->name, $locked)) {
                $value = $locked[$field->name];

                if (!self::fits($field, $value)) {
                    throw new ConfigException(\sprintf('The setting "%s" in config/extensions/%s.yaml must be of type %s.', $field->name, $package, ($field->nullable ? '?' : '') . $field->type));
                }

                $values[$field->name] = ['float' === $field->type && \is_int($value) ? (float) $value : $value, true];

                continue;
            }

            $values[$field->name] = [$this->storedValue($field, $stored[$field->name] ?? null), false];
        }

        return $values;
    }

    private function storedValue(Field $field, ?StoredSetting $row): mixed
    {
        if (null === $row) {
            return $field->default;
        }

        if ($field->isSensitive()) {
            return null === $row->secret ? $field->default : ($this->secrets->decrypt(self::PURPOSE, $row->secret) ?? $field->default);
        }

        if (null === $row->value) {
            return $field->default;
        }

        try {
            $value = json_decode($row->value, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $field->default;
        }

        // A stored value of another type, e.g. after an update of the extension.
        if (!self::fits($field, $value)) {
            return $field->default;
        }

        return 'float' === $field->type && \is_int($value) ? (float) $value : $value;
    }

    /**
     * Values from config/extensions/<vendor>/<package>.yaml.
     *
     * @return array<string, mixed> by name
     */
    private function locked(string $package): array
    {
        $extensions = $this->config->get('extensions');
        $values = \is_array($extensions) ? ($extensions[$package] ?? []) : [];
        $locked = [];

        if (\is_array($values)) {
            foreach ($values as $name => $value) {
                $locked[(string) $name] = $value;
            }
        }

        return $locked;
    }

    private static function fits(Field $field, mixed $value): bool
    {
        if (null === $value) {
            return $field->nullable;
        }

        return match ($field->type) {
            'string' => \is_string($value),
            'int' => \is_int($value),
            'float' => \is_int($value) || \is_float($value),
            'bool' => \is_bool($value),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        $map = [];

        foreach (\is_array($value) ? $value : [] as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        return \is_scalar($value) ? $value : null;
    }

    /**
     * @param class-string $class
     *
     * @return array<string, Field>
     */
    private static function fieldsByName(string $class): array
    {
        $fields = [];

        foreach (Fields::of($class) as $field) {
            $fields[$field->name] = $field;
        }

        return $fields;
    }
}
