<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Component\DefinitionErrors;
use Frootbox\Core\Component\DefinitionReader;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Revision\Revision;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\Theme\ThemeException;
use Frootbox\Core\View\EditMarkup;
use Frootbox\Core\View\Scope;
use Psr\Container\ContainerInterface;

/**
 * Content that is the same on every page, e.g. the address in the footer
 * (decision 0128). The themes describe the fields under "theme.fields",
 * the values are kept per language in a revision of the type "site" per
 * theme. Visitors get the published ones, the editing mode the draft.
 *
 *     {{ site_field('address') }}   the field, ready for output
 *     {{ site_value('brand') }}     the stored value
 */
final class SiteFields
{
    public const string TYPE = 'site';

    /** @var array<string, Field>|null */
    private ?array $fields = null;

    /**
     * @param ContainerInterface $container The revisions are loaded on first use, so pages render before the installation
     */
    public function __construct(
        private readonly ThemeChain $themes,
        private readonly DefinitionReader $reader,
        private readonly ContainerInterface $container,
        private readonly EditMode $editMode,
        private readonly Languages $languages,
        private readonly FieldTypes $types,
        private readonly EditMarkup $markup,
        private readonly Config $config,
    ) {}

    /**
     * The fields the themes describe, by name.
     *
     * @return array<string, Field>
     *
     * @throws ThemeException if a description is broken
     */
    public function fields(): array
    {
        if (null !== $this->fields) {
            return $this->fields;
        }

        $theme = $this->themes->active();
        $errors = new DefinitionErrors();
        $definition = $this->reader->read(['fields' => $this->themes->fields()], ['fields'], false, $theme->name ?? '', '"theme.fields" of ' . ($theme->name ?? 'the theme'), $errors);

        if ($errors->count() > 0) {
            throw new ThemeException(implode("\n", $errors->all()));
        }

        return $this->fields = $definition->fields;
    }

    /**
     * The fields for a template in the language of the visitor, marked
     * for editing if the editing mode allows it.
     */
    public function scope(string $language): Scope
    {
        return new Scope(
            $this->fields(),
            $this->values($language, $this->editMode->active()),
            $this->types,
            match (true) {
                $this->editMode->markingSite() => $this->markup->forSite(),
                // Light marks in a preview, to comment on (decision 0156).
                $this->editMode->anchors() => $this->markup->forPreview()->forSite(),
                default => null,
            },
        );
    }

    /**
     * The values in a language, those of the first language where it has
     * none; with $draft those of the draft if there is one.
     *
     * @return array<string, mixed> By field name
     */
    public function values(string $language, bool $draft): array
    {
        $theme = $this->themes->active();
        $database = $this->config->get('database.url');

        // Before the installation there is nothing to read.
        if (null === $theme || !\is_string($database) || '' === $database) {
            return [];
        }

        $revisions = $this->container->get(Revisions::class);

        if (!$revisions instanceof Revisions) {
            return [];
        }

        $revision = ($draft ? $revisions->draft(self::TYPE, $theme->name) : null) ?? $revisions->published(self::TYPE, $theme->name);

        return $this->merged(self::languages($revision), $language);
    }

    /**
     * The values of a language over those of the first language.
     *
     * @param array<string, array<string, mixed>> $languages
     *
     * @return array<string, mixed>
     */
    public function merged(array $languages, string $language): array
    {
        $values = [...$languages[$this->languages->default()->code] ?? [], ...$languages[$language] ?? []];

        return array_filter($values, static fn(mixed $value): bool => null !== $value);
    }

    /**
     * The values stored in a revision, by language and field name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function languages(?Revision $revision): array
    {
        $stored = $revision?->data['languages'] ?? null;
        $languages = [];

        foreach (\is_array($stored) ? $stored : [] as $code => $values) {
            if (!\is_array($values)) {
                continue;
            }

            foreach ($values as $name => $value) {
                $languages[(string) $code][(string) $name] = $value;
            }
        }

        return $languages;
    }
}
