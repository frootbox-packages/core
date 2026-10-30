<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database\Schema;

use Doctrine\DBAL\Schema\Column;

/**
 * One column of a Blueprint. Modifiers can be chained:
 * $table->string('slug')->nullable()->unique().
 */
final class ColumnDefinition
{
    private bool $nullable = false;

    private bool $hasDefault = false;

    private mixed $default = null;

    private bool $unsigned = false;

    private bool $autoincrement = false;

    private bool $unique = false;

    private bool $index = false;

    /**
     * @param non-empty-string $name
     */
    public function __construct(
        public readonly string $name,
        private readonly string $type,
        private readonly ?int $length = null,
        private readonly ?int $precision = null,
        private readonly int $scale = 0,
    ) {}

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;

        return $this;
    }

    public function autoIncrement(): self
    {
        $this->autoincrement = true;

        return $this;
    }

    /**
     * Adds a unique index on this column.
     */
    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    /**
     * Adds an index on this column.
     */
    public function index(): self
    {
        $this->index = true;

        return $this;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function isIndexed(): bool
    {
        return $this->index;
    }

    public function toColumn(): Column
    {
        $editor = Column::editor()
            ->setUnquotedName($this->name)
            ->setTypeName($this->type)
            ->setLength($this->length)
            ->setPrecision($this->precision)
            ->setScale($this->scale)
            ->setNotNull(!$this->nullable)
            ->setUnsigned($this->unsigned)
            ->setAutoincrement($this->autoincrement);

        if ($this->hasDefault) {
            $editor->setDefaultValue($this->default);
        }

        return $editor->create();
    }
}
