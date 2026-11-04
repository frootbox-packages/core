<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Reference;

use Frootbox\Core\Component\BlockGroups;
use Frootbox\Core\Component\DefinitionReader;
use Frootbox\Core\Component\Option;
use LogicException;

/**
 * The page on the schema of blocks and layouts. The keys and their values
 * come from the meta schema of the DefinitionReader, the descriptions
 * from here; a key without a description fails the build, so the page
 * can't fall behind.
 */
final class BlockSchema
{
    /** Part of the meta schema => heading and text above its table. */
    private const array PARTS = [
        'top' => ['Keys of a schema', "Where a key may stand: in `block.yaml` or `layout.yaml`, in the header\nof `Default` when there is none, and in the header of every other view."],
        'option' => ['Options', "Settings an editor chooses per block, the same in every language,\nread in the view as `options.<name>`. Types: %types%.\n\n```yaml\noptions:\n  imageHeight: { type: choice, choices: { 400: Standard, 550: High }, default: 400 }\n  dark: { type: boolean, label: Dark background }\n  perPage: { type: number, default: 10, min: 1, max: 50 }\n```"],
        'field' => ['Fields', "Content, translated per language, printed with `field()`, `value()`\nand `heading()`. Each field type has settings of its own next to `type`,\nsee [field types](field-types.md).\n\n```yaml\nfields:\n  title: { type: headline, placeholder: \"What it is about\" }\n  items:\n    type: list\n    max: 8\n    fields:\n      text: { type: text, optional: true }\n```"],
        'area' => ['Areas', "Places for other blocks, printed with `area('<name>')`.\n\n```yaml\nareas:\n  main: { allow: [Text, Image, frootbox/forms/Form], min: 1 }\n  aside: { label: Sidebar, level: 2 }\n```"],
        'object' => ['Object fields', "Further fields of the objects a view shows, e.g. of an article on its\nown page, read with `object_field(object, name)` and `object_value()`.\nThey are edited in the backend with the object and in place in the\nedit mode.\n\n```yaml\nobject:\n  fields:\n    price: { type: number, label: Price }\n```"],
        'example' => ['Examples', "Content for the design page under `/_design`, a list of entries, each\nlike the block on a page. Images take `sample:` with a path within\n`assets/` instead of a medium.\n\n```yaml\nexamples:\n  - fields: { title: 'Our offers' }\n    options: { columns: 3 }\n  - variant: ImageLeft\n    fields: { title: 'With an image' }\n```"],
    ];

    /** "part.key" => description. */
    private const array DESCRIPTIONS = [
        'top.title' => 'The name in the block picker and the backend. Required for the schema; in another view the name of that variant.',
        'top.description' => 'One sentence for editors: what the block is for. In a variant: what sets it apart.',
        'top.group' => 'The group in the block picker, see [groups](#groups). Without one the block is under “Other”.',
        'top.keywords' => 'More words the search of the block picker finds, e.g. `[Promo, Lead]`.',
        'top.widget' => '`true`: the block goes only into areas that name it in `allow`, e.g. a sidebar. Only for blocks.',
        'top.plugin' => 'In a layout: it has a place for a plugin, `{{ plugin() }}`, so the page of a space can use it. In a block: it shows the objects of a plugin, e.g. a list of articles.',
        'top.options' => 'Settings per variant, see [options](#options).',
        'top.fields' => 'Content, see [fields](#fields).',
        'top.areas' => 'Places for other blocks, see [areas](#areas).',
        'top.examples' => 'Content for the design page, see [examples](#examples).',
        'top.query' => 'The query parameters the block reads, camelCase, e.g. `[page, category]`. The page cache keeps a copy per value; a block with results per input, such as a search, calls `Delivery::withoutCache()`.',
        'top.object' => 'Further fields of the objects the view shows, see [object fields](#object-fields).',
        'option.type' => 'What the editor chooses.',
        'option.label' => 'The label in the backend. Without one it comes from the name: `imageHeight` becomes “Image height”.',
        'option.description' => 'A hint below the option.',
        'option.default' => 'The value of a new block. Without one: the first choice, `false`, `min` or `0`, or an empty text.',
        'option.choices' => 'For `choice`: the values with their labels, e.g. `{ 400: Standard, 550: High }`.',
        'option.source' => 'For `choice`: a query that gives the choices instead, e.g. `frootbox/forms/form.choices`. It returns a list of `{value, label}`.',
        'option.min' => 'For `number`: the smallest value.',
        'option.max' => 'For `number`: the largest value.',
        'field.type' => 'The [field type](field-types.md), e.g. `headline`. Required.',
        'field.label' => 'The label in the backend. Without one it comes from the name.',
        'field.description' => 'A hint for editors.',
        'field.optional' => '`true`: a page can be published without a value. Visitors don’t see an empty optional field.',
        'field.placeholder' => 'Shown in the empty field while editing, e.g. “What it is about”.',
        'field.fields' => 'For types with entries such as `list`: the fields of each entry. Required there.',
        'field.translatable' => 'Only under `object`: whether the value differs per language. Texts do, other types don’t; this turns it around.',
        'field.labels' => 'Only under `object`: the label per language, e.g. `{ de: Preis, en: Price }`.',
        'area.label' => 'The label in the backend. Without one it comes from the name.',
        'area.description' => 'A hint for editors.',
        'area.allow' => 'The blocks it takes, e.g. `[Column]`; a short name is a block of the same extension. Without `allow` every block that is no widget.',
        'area.min' => 'The fewest blocks, checked when the page is published.',
        'area.max' => 'The most blocks.',
        'area.level' => 'The heading level the blocks inside start at, e.g. `2` for a sidebar that never holds the h1. Without one the core works it out.',
        'object.type' => 'The type of the objects, e.g. `frootbox/news/article`. A block with `plugin: true` takes the type of its plugin.',
        'object.fields' => 'The fields, as [fields](#fields). Required.',
        'example.variant' => 'The variant to show. Without one every variant shows the example.',
        'example.fields' => 'Values by field name, in the format of a seed file.',
        'example.areas' => 'Blocks by area name, each `{ block: Text, fields: { … } }`.',
        'example.options' => 'Values by option name.',
    ];

    public static function text(): string
    {
        $schema = DefinitionReader::schema();
        $described = [];

        $text = <<<'MD'
            Every block and layout describes its fields, options and areas in a
            schema in YAML. `bin/frootbox blocks:check` checks every schema and
            names each mistake with its file and key.

            ## Where the schema is

            ```
            blocks/Teaser/
              views/Default.twig       every block has a variant Default
              views/ImageLeft.twig     more variants, PascalCase
              block.yaml               the shared schema, when there are several variants
              assets/teaser.css        loaded only on pages with the block
              Teaser.php               optional controller, a BlockController
              preview.svg              for the block picker, also preview.ImageLeft.webp
            ```

            Layouts are the same below `layouts/`, with `layout.yaml`.

            With one variant the schema is the header of `views/Default.twig`:

            ```twig
            {# frootbox
            title: Teaser
            fields:
              title: { type: headline }
            #}
            <section class="teaser">{{ heading('title') }}</section>
            ```

            With a `block.yaml`, it holds what all variants share, and the header
            of each view, `Default` too, only what is its own: title,
            description, options and object fields. A theme that overrides a
            view gives only these as well.

            ```yaml
            # block.yaml
            title: Teaser
            group: teaser
            fields:
              title: { type: headline }
              image: { type: image, optional: true }
            ```

            ```twig
            {# frootbox
            title: Image on the left
            options:
              imageHeight: { type: choice, choices: { 400: Standard, 550: High }, default: 400 }
            #}
            ```

            Names of fields, options and areas are camelCase.

            MD;

        foreach (self::PARTS as $part => [$heading, $intro]) {
            $text .= "\n## " . $heading . "\n\n" . str_replace('%types%', '`' . implode('`, `', Option::TYPES) . '`', $intro) . "\n\n";

            if ('top' === $part) {
                $text .= "| Key | Value | `block.yaml` | `Default` alone | Other views | Description |\n|---|---|:-:|:-:|:-:|---|\n";
            } else {
                $text .= "| Key | Value | Description |\n|---|---|---|\n";
            }

            foreach ($schema[$part] ?? [] as $key => $definition) {
                $id = $part . '.' . $key;
                $description = self::DESCRIPTIONS[$id] ?? throw new LogicException(\sprintf('The block schema has no description for "%s".', $id));
                $described[] = $id;
                $text .= '| `' . $key . '` | ' . self::value($definition) . ' | ';

                if ('top' === $part) {
                    foreach ([DefinitionReader::SCHEMA_KEYS, DefinitionReader::FULL_KEYS, DefinitionReader::VARIANT_KEYS] as $keys) {
                        $text .= (\in_array($key, $keys, true) ? '✓' : '') . ' | ';
                    }
                }

                $text .= $description . " |\n";
            }
        }

        $unknown = array_diff(array_keys(self::DESCRIPTIONS), $described);

        if ([] !== $unknown) {
            throw new LogicException(\sprintf('The block schema describes keys it does not know: %s.', implode(', ', $unknown)));
        }

        $text .= "\n## Groups\n\nThe groups of the block picker in the core, in order. Extensions add\nmore.\n\n| Group | Title |\n|---|---|\n";

        foreach (BlockGroups::CORE as $name => $title) {
            $text .= '| `' . $name . '` | ' . $title . " |\n";
        }

        return $text;
    }

    /**
     * The value a key takes, in words, from its JSON schema.
     *
     * @param array<string, mixed> $definition
     */
    private static function value(array $definition): string
    {
        if (isset($definition['enum']) && \is_array($definition['enum'])) {
            return implode(', ', array_map(static fn(mixed $value): string => '`' . (\is_scalar($value) ? (string) $value : '') . '`', $definition['enum']));
        }

        $type = $definition['type'] ?? null;
        $types = \is_array($type) ? $type : [$type];

        return match (true) {
            \in_array('null', $types, true), ['object', 'array'] === $types => 'map by name',
            ['string', 'number', 'boolean'] === $types => 'text, number or `true`/`false`',
            'string' === $type => 'text',
            'boolean' === $type => '`true` or `false`',
            'number' === $type => 'number',
            'integer' === $type => self::range($definition),
            'object' === $type => 'map by language',
            'array' === $type => isset($definition['items']) ? 'list of texts' : 'list',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function range(array $definition): string
    {
        $minimum = $definition['minimum'] ?? null;
        $maximum = $definition['maximum'] ?? null;

        return match (true) {
            \is_int($minimum) && \is_int($maximum) => \sprintf('whole number, %d to %d', $minimum, $maximum),
            \is_int($minimum) => \sprintf('whole number from %d', $minimum),
            default => 'whole number',
        };
    }
}
