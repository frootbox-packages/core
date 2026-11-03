<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Field\Field;

/**
 * A block or layout, with its variants and assets resolved through the
 * theme chain.
 */
final readonly class Component
{
    /**
     * @param array<string, Field>   $fields
     * @param array<string, Area>    $areas
     * @param array<string, Variant> $variants Default first
     * @param array<string, string>  $assets   Path within assets/ => absolute path
     * @param list<string>           $keywords Further words for the search in the picker
     * @param array<string, string>  $previews Variant => absolute path of its preview, "Default" for preview.svg (decision 0148)
     * @param list<array<string, mixed>> $examples Each with fields, areas, options and maybe a variant (decision 0206)
     */
    public function __construct(
        public ComponentKind $kind,
        public string $name,
        public string $path,
        public string $title,
        public string $description,
        public array $fields,
        public array $areas,
        public array $variants,
        public array $assets = [],
        /** Goes only into areas that allow it by name (decision 0124) */
        public bool $widget = false,
        /** The group in the block picker, null for "Other" (decision 0148) */
        public ?string $group = null,
        public array $keywords = [],
        public array $previews = [],
        /** Made or adapted by a theme of this website, not by the root theme (decision 0202) */
        public bool $tailored = false,
        /** Content for the design page, like blocks on a page (decisions 0205, 0206) */
        public array $examples = [],
        /** @var list<string> Query parameters the block reads (decision 0219) */
        public array $query = [],
        /** The class of its controller, a BlockController (decision 0219) */
        public ?string $controller = null,
        /** The file of the controller, loaded if Composer does not know the class */
        public ?string $controllerFile = null,
        /** A layout with plugin(), a block that is the view of a plugin (decision 0232) */
        public bool $plugin = false,
    ) {}

    /**
     * The same component with other variants, e.g. with the choices of
     * their options filled in.
     *
     * @param array<string, Variant> $variants
     */
    public function withVariants(array $variants): self
    {
        return new self($this->kind, $this->name, $this->path, $this->title, $this->description, $this->fields, $this->areas, $variants, $this->assets, $this->widget, $this->group, $this->keywords, $this->previews, $this->tailored, $this->examples, $this->query, $this->controller, $this->controllerFile, $this->plugin);
    }

    public function package(): string
    {
        return ComponentName::package($this->name);
    }

    /**
     * The variant by name. Unknown names fall back to Default, so content
     * survives a theme without that variant.
     */
    public function variant(?string $name): Variant
    {
        return $this->variants[$name ?? Variant::DEFAULT] ?? $this->variants[Variant::DEFAULT] ?? array_values($this->variants)[0];
    }
}
