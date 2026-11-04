<?php

/*
 * SPDX-License-Identifier: MIT
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
        public readonly bool $signedIn,
        public readonly bool $undoable,
        public readonly string $class,
        public readonly bool $preview = false,
        public readonly bool $transactional = true,
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

        if (!ActionName::isValid($info->name)) {
            throw new DefinitionException(\sprintf('The name "%s" of %s must be lowercase with at least one dot, e.g. "page.create", or "acme/shop/order.create" in an extension.', $info->name, $class));
        }

        if ($info->public && $info->signedIn) {
            throw new DefinitionException(\sprintf('%s cannot be public and signedIn at once.', $class));
        }

        if ($info->preview && ($info->public || $info->signedIn)) {
            throw new DefinitionException(\sprintf('%s cannot be for preview links and public or signedIn at once.', $class));
        }

        if ($info instanceof AsAction && !$info->transactional && (!str_starts_with($info->name, 'system.') || $info->undoable)) {
            throw new DefinitionException(\sprintf('%s may only run without a transaction as a system.* action with "undoable: false" (decision 0277).', $class));
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
            $info->signedIn,
            $info instanceof AsAction && $info->undoable,
            $class,
            $info->preview,
            !$info instanceof AsAction || $info->transactional,
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
