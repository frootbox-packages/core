<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Action;

use Frootbox\Core\Action\Schema\SchemaFactory;
use ReflectionClass;

/**
 * Everything known about an action or query without running it: name,
 * description, permission and input schema. REST, MCP and the AI are
 * built from this.
 */
final class Descriptor
{
    private const string NAME_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)+$/';

    /** @var array<string, mixed>|null */
    private ?array $schema = null;

    /**
     * @param class-string $class
     */
    private function __construct(
        public readonly Kind $kind,
        public readonly string $name,
        public readonly string $description,
        public readonly string $permission,
        public readonly bool $public,
        public readonly bool $undoable,
        public readonly string $class,
    ) {}

    /**
     * @param class-string $class
     */
    public static function of(string $class): self
    {
        $reflection = new ReflectionClass($class);
        $isAction = $reflection->implementsInterface(Action::class);

        if ($isAction === $reflection->implementsInterface(Query::class)) {
            throw new DefinitionException(\sprintf('%s must implement either %s or %s.', $class, Action::class, Query::class));
        }

        $attribute = $isAction ? $reflection->getAttributes(AsAction::class)[0] ?? null : $reflection->getAttributes(AsQuery::class)[0] ?? null;

        if (null === $attribute) {
            throw new DefinitionException(\sprintf('%s needs the attribute #[%s].', $class, $isAction ? 'AsAction' : 'AsQuery'));
        }

        $info = $attribute->newInstance();

        if (1 !== preg_match(self::NAME_PATTERN, $info->name)) {
            throw new DefinitionException(\sprintf('The name "%s" of %s must be lowercase with at least one dot, e.g. "page.create".', $info->name, $class));
        }

        if (!$reflection->hasMethod('handle') || !$reflection->getMethod('handle')->isPublic() || $reflection->getMethod('handle')->isStatic()) {
            throw new DefinitionException(\sprintf('%s needs a public method handle().', $class));
        }

        return new self(
            $isAction ? Kind::Action : Kind::Query,
            $info->name,
            $info->description,
            $info->permission ?? $info->name,
            $info->public,
            $info instanceof AsAction && $info->undoable,
            $class,
        );
    }

    public function isAction(): bool
    {
        return Kind::Action === $this->kind;
    }

    /**
     * The JSON schema of the input.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return $this->schema ??= SchemaFactory::forClass($this->class);
    }
}
