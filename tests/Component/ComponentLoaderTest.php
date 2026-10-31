<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Component;

use DI\Container;
use Frootbox\Core\Component\Area;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentException;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\ComponentLoader;
use Frootbox\Core\Component\ComponentName;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\ComponentsFactory;
use Frootbox\Core\Component\Definition;
use Frootbox\Core\Component\DefinitionErrors;
use Frootbox\Core\Component\DefinitionReader;
use Frootbox\Core\Component\Header;
use Frootbox\Core\Component\Option;
use Frootbox\Core\Component\Variant;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComponentLoader::class)]
#[CoversClass(ComponentsFactory::class)]
#[CoversClass(Components::class)]
#[CoversClass(Component::class)]
#[CoversClass(ComponentKind::class)]
#[CoversClass(ComponentName::class)]
#[CoversClass(ComponentException::class)]
#[CoversClass(DefinitionReader::class)]
#[CoversClass(DefinitionErrors::class)]
#[CoversClass(Definition::class)]
#[CoversClass(Header::class)]
#[CoversClass(Option::class)]
#[CoversClass(Area::class)]
#[CoversClass(Variant::class)]
#[CoversClass(Field::class)]
#[CoversClass(FieldTypes::class)]
final class ComponentLoaderTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testFindsBlocksOfModulesAndTheActiveThemeChain(): void
    {
        $components = $this->components();

        self::assertSame(['acme/base/Badge', 'acme/base/Columns', 'acme/base/Teaser', 'acme/site/Hero', 'acme/news/List'], array_keys($components->blocks));
        self::assertSame(['acme/base/Page'], array_keys($components->layouts));
        self::assertNull($components->block('acme/other/Promo'), 'Themes outside the chain bring no blocks.');
        self::assertSame('acme/base', $this->block($components, 'acme/base/Teaser')->package());
    }

    public function testWithoutThemeOnlyModulesCount(): void
    {
        self::assertSame(['acme/news/List'], array_keys($this->components(null)->blocks));
    }

    public function testReadsTheSchemaFromTheHeader(): void
    {
        $teaser = $this->block($this->components(), 'acme/base/Teaser');

        self::assertSame('Teaser', $teaser->title);
        self::assertSame('Heading, text and a button.', $teaser->description);
        self::assertSame(['title', 'text', 'button'], array_keys($teaser->fields));
        self::assertSame('headline', $teaser->fields['title']->type);
        self::assertSame('Title', $teaser->fields['title']->label);
        self::assertSame(['maxLength' => 80], $teaser->fields['title']->settings);
        self::assertTrue($teaser->fields['button']->optional);
    }

    public function testOverridesReplaceFilesAndAddVariants(): void
    {
        $teaser = $this->block($this->components(), 'acme/base/Teaser');

        self::assertSame(['Default', 'ImageLeft'], array_keys($teaser->variants));
        self::assertSame('acme/site', $teaser->variants['Default']->from);
        self::assertSame(FixtureSite::path('site') . '/overrides/acme/base/blocks/Teaser/views/Default.twig', $teaser->variants['Default']->template);
        self::assertSame('Site teaser', $teaser->variants['Default']->title);
        self::assertSame([], $teaser->variants['Default']->options, 'Options belong to the view that defines them.');
        self::assertSame(['title', 'text', 'button'], array_keys($teaser->fields), 'The schema stays with the original.');
        self::assertSame('Image left', $teaser->variants['ImageLeft']->title);
        self::assertSame(['rounded' => true], $teaser->variants['ImageLeft']->options([]));
        self::assertSame('ImageLeft', $teaser->variant('ImageLeft')->name);
        self::assertSame('Default', $teaser->variant('Gone')->name);

        self::assertSame([
            'extra.css' => FixtureSite::path('site') . '/overrides/acme/base/blocks/Teaser/assets/extra.css',
            'teaser.css' => FixtureSite::path('site') . '/overrides/acme/base/blocks/Teaser/assets/teaser.css',
            'teaser.js' => FixtureSite::path('base') . '/blocks/Teaser/assets/teaser.js',
        ], $teaser->assets);

        $list = $this->block($this->components(), 'acme/news/List');
        self::assertSame(['Default' => 'acme/news', 'Compact' => 'acme/site'], array_map(static fn(Variant $variant): string => $variant->from, $list->variants));
    }

    public function testReadsSharedSchemasAndAreas(): void
    {
        $columns = $this->block($this->components(), 'acme/base/Columns');

        self::assertSame('Columns', $columns->title);
        self::assertSame(['Default', 'Wide'], array_keys($columns->variants));
        self::assertSame('Columns', $columns->variants['Default']->title);
        self::assertSame('Left column twice as wide.', $columns->variants['Wide']->description);
        self::assertSame(['acme/base/Teaser', 'acme/news/List', 'acme/base/Badge'], $columns->areas['left']->allow);
        self::assertTrue($columns->areas['left']->allows('acme/news/List'));
        self::assertFalse($columns->areas['left']->allows('acme/site/Hero'));
        self::assertTrue($this->block($this->components(), 'acme/base/Badge')->widget);
        self::assertFalse($columns->widget);
        self::assertTrue($columns->areas['left']->allows('acme/base/Badge', true), 'A widget goes where an area names it.');
        self::assertFalse($columns->areas['right']->allows('acme/base/Badge', true), 'An area without rules takes no widgets.');
        self::assertTrue($columns->areas['right']->allows('acme/base/Teaser'));
        self::assertNull($columns->areas['right']->allow);
        self::assertSame('Left', $columns->areas['left']->label);
    }

    public function testOptionsFallBackToDefaults(): void
    {
        $default = $this->block($this->components('acme/base'), 'acme/base/Teaser')->variants['Default'];
        $option = $default->options['imageHeight'];

        self::assertSame(['imageHeight' => 550], $default->options(['imageHeight' => '550']));
        self::assertSame(['imageHeight' => 400], $default->options(['imageHeight' => 999]));
        self::assertSame(['imageHeight' => 400], $default->options([]));
        self::assertSame(['enum' => [400, 550]], $option->valueSchema());
        self::assertSame('Image height', $option->label);
    }

    public function testCollectsAllErrors(): void
    {
        $root = $this->project->root . '/broken';
        $this->write($root, [
            'frootbox.yaml' => 'title: Broken',
            'blocks/lowercase/views/Default.twig' => 'x',
            'blocks/NoDefault/views/Other.twig' => 'x',
            'blocks/BadVariant/views/Default.twig' => "{# frootbox\ntitle: B\n#}",
            'blocks/BadVariant/views/image-left.twig' => 'x',
            'blocks/NoTitle/views/Default.twig' => "{# frootbox\nfields:\n  title: { type: headline }\n#}",
            'blocks/BadYaml/views/Default.twig' => "{# frootbox\ntitle: [unclosed\n#}",
            'blocks/Fields/views/Default.twig' => <<<'TWIG'
                {# frootbox
                title: Fields
                colour: red
                options:
                  size: { type: choice }
                  wide: { type: boolean, default: maybe }
                  Bad: { type: text }
                fields:
                  title: { type: headlin }
                  text: { type: text, maxLength: 0, multiline: yes please }
                  slides: { type: list }
                  untyped: { label: Hello }
                  button: { type: link, target: _blank }
                areas:
                  main: { allow: [not a block], max: 0 }
                #}
                TWIG,
            'blocks/Shared/block.yaml' => "title: Shared\noptions: {}\n",
            'blocks/Shared/views/Default.twig' => "{# frootbox\nfields:\n  title: { type: headline }\n#}",
            'blocks/List/views/Default.twig' => "{# frootbox\ntitle: List\n#}",
        ]);

        $errors = $this->errors(new Extensions([ExtensionPackage::fromManifest('acme/broken', $root)]));

        self::assertSame([
            'acme/broken: blocks/BadVariant/views/image-left.twig: variant names are PascalCase, e.g. ImageLeft.twig',
            'acme/broken: blocks/BadYaml/views/Default.twig: the header is not valid YAML: Malformed inline YAML string at line 3.',
            'acme/broken: blocks/Fields/views/Default.twig → areas.main.allow: "not a block" is not a block name, e.g. Column or frootbox/base/Teaser',
            'acme/broken: blocks/Fields/views/Default.twig → areas.main.max: must be at least 1.',
            'acme/broken: blocks/Fields/views/Default.twig → colour: unknown key. Allowed: title, description, widget, options, fields, areas',
            'acme/broken: blocks/Fields/views/Default.twig → fields.button.target: unknown key. Allowed: type, label, description, optional',
            'acme/broken: blocks/Fields/views/Default.twig → fields.slides.fields: is missing: the fields of each entry',
            'acme/broken: blocks/Fields/views/Default.twig → fields.text.maxLength: must be at least 1.',
            'acme/broken: blocks/Fields/views/Default.twig → fields.text.multiline: must be true or false.',
            'acme/broken: blocks/Fields/views/Default.twig → fields.title.type: there is no field type "headlin". Known: headline, text, richtext, link, list',
            'acme/broken: blocks/Fields/views/Default.twig → fields.untyped.type: is missing, e.g. "type: headline"',
            'acme/broken: blocks/Fields/views/Default.twig → options.Bad: names are camelCase, e.g. "imageHeight"',
            'acme/broken: blocks/Fields/views/Default.twig → options.size.choices: must list the values with their labels, e.g. { 400: Standard, 550: High }',
            'acme/broken: blocks/Fields/views/Default.twig → options.wide.default: does not fit the option',
            'acme/broken: blocks/NoDefault: views/Default.twig is missing. Every block has a variant Default.',
            'acme/broken: blocks/NoTitle/views/Default.twig → title: is required.',
            'acme/broken: blocks/Shared/block.yaml → options: unknown key. Allowed: title, description, widget, fields, areas',
            'acme/broken: blocks/Shared/views/Default.twig → fields: unknown key. Allowed: title, description, options',
            'acme/broken: blocks/lowercase: folder names are PascalCase, e.g. "ImageGallery"',
        ], $errors);
    }

    public function testChecksOverrides(): void
    {
        $root = $this->project->root . '/child';
        $this->write($root, [
            'frootbox.yaml' => "title: Child\ntheme:\n  parent: acme/base\n",
            'overrides/acme/base/blocks/Teasr/views/Default.twig' => 'x',
            'overrides/acme/base/blocks/Teaser/views/Default.twig' => "{# frootbox\nfields:\n  more: { type: text }\n#}",
            'overrides/acme/base/layouts/Page/views/Big.twig' => "{# frootbox\ntitle: Big\n#}",
        ]);

        $extensions = new Extensions([...FixtureSite::extensions('base', 'news')->packages, ExtensionPackage::fromManifest('acme/child', $root)]);

        self::assertSame([
            'acme/child: overrides/acme/base/blocks/Teaser/views/Default.twig → fields: unknown key. Allowed: title, description, options',
            'acme/child: overrides/acme/base/blocks/Teasr: acme/base has no block "Teasr"',
        ], $this->errors($extensions, 'acme/child'));
    }

    public function testCachesInProduction(): void
    {
        $container = FixtureSite::container($this->project, 'acme/site', Environment::Production);
        $components = $container->get(Components::class);
        self::assertInstanceOf(Components::class, $components);
        self::assertFileExists($this->project->root . '/var/' . ComponentsFactory::CACHE_FILE);

        $again = FixtureSite::container($this->project, 'acme/site', Environment::Production)->get(Components::class);
        self::assertEquals($components, $again);

        // Another theme gives another fingerprint.
        $base = FixtureSite::container($this->project, 'acme/base', Environment::Production)->get(Components::class);
        self::assertInstanceOf(Components::class, $base);
        self::assertNull($base->block('acme/site/Hero'));
    }

    private function components(?string $theme = 'acme/site'): Components
    {
        $components = $this->container($theme)->get(Components::class);
        self::assertInstanceOf(Components::class, $components);

        return $components;
    }

    private function block(Components $components, string $name): Component
    {
        $block = $components->block($name);
        self::assertInstanceOf(Component::class, $block, $name);

        return $block;
    }

    /**
     * @return list<string>
     */
    private function errors(Extensions $extensions, ?string $theme = null): array
    {
        $loader = $this->container($theme, $extensions)->get(ComponentLoader::class);
        self::assertInstanceOf(ComponentLoader::class, $loader);

        try {
            $loader->load();
        } catch (ComponentException $exception) {
            $errors = $exception->errors;
            sort($errors);

            return $errors;
        }

        self::fail('No errors found.');
    }

    private function container(?string $theme, ?Extensions $extensions = null): Container
    {
        return FixtureSite::container($this->project, $theme, extensions: $extensions);
    }

    /**
     * @param array<string, string> $files
     */
    private function write(string $root, array $files): void
    {
        foreach ($files as $path => $content) {
            $file = $root . '/' . $path;

            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), 0o777, true);
            }

            file_put_contents($file, $content);
        }
    }
}
