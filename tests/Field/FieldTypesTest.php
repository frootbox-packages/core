<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Field;

use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldType;
use Frootbox\Core\Field\FieldTypeName;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Types\HeadlineType;
use Frootbox\Core\Field\Types\LinkType;
use Frootbox\Core\Field\Types\ListType;
use Frootbox\Core\Field\Types\RichTextType;
use Frootbox\Core\Field\Types\TextType;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldTypes::class)]
#[CoversClass(FieldTypeName::class)]
#[CoversClass(AsFieldType::class)]
#[CoversClass(Field::class)]
#[CoversClass(Registry::class)]
#[CoversClass(HeadlineType::class)]
#[CoversClass(TextType::class)]
#[CoversClass(RichTextType::class)]
#[CoversClass(LinkType::class)]
#[CoversClass(ListType::class)]
final class FieldTypesTest extends TestCase
{
    private TemporaryProject $project;

    private FieldTypes $types;

    /** @var array<string, Field> */
    private array $fields;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $container = FixtureSite::container($this->project);
        $types = $container->get(FieldTypes::class);
        $components = $container->get(Components::class);
        self::assertInstanceOf(FieldTypes::class, $types);
        self::assertInstanceOf(Components::class, $components);
        $this->types = $types;
        $this->fields = [
            ...$components->block('acme/base/Teaser')->fields ?? [],
            ...$components->block('acme/site/Hero')->fields ?? [],
        ];
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testBuildsTheSchemaOfContent(): void
    {
        $schema = $this->types->schemaFor($this->fields);

        self::assertSame(['title', 'slides'], $schema['required']);
        self::assertSame(['type' => 'string', 'maxLength' => 80, 'pattern' => '^[^\r\n]*$'], self::at($schema, 'properties', 'title'));
        self::assertSame(['type' => 'string', 'maxLength' => 5000], self::at($schema, 'properties', 'intro'));
        self::assertSame(5, self::at($schema, 'properties', 'slides', 'maxItems'));
        self::assertSame(['title'], self::at($schema, 'properties', 'slides', 'items', 'required'));
    }

    public function testChecksValues(): void
    {
        $values = [
            'title' => "Two\nlines",
            'text' => ['type' => 'doc', 'content' => [['type' => 'image']]],
            'button' => ['url' => 'javascript:alert(1)'],
            'slides' => [['title' => 'ok'], ['title' => 'ok', 'link' => ['url' => 'data:x']], ['link' => ['url' => '/x']]],
        ];

        self::assertSame([
            '/text/content/0/type' => 'unknown node "image"',
            '/button/url' => 'must use one of the protocols http, https, mailto, tel',
            '/slides/1/link/url' => 'must use one of the protocols http, https, mailto, tel',
        ], $this->types->validate($this->fields, $values));

        $schemaErrors = new InputValidator()->errors($this->types->schemaFor($this->fields), $values);
        self::assertArrayHasKey('/title', $schemaErrors);
        self::assertArrayHasKey('/slides/2/title', $schemaErrors, 'A missing field is reported at the field.');
    }

    public function testKnowsItsTypes(): void
    {
        self::assertSame(['headline', 'text', 'richtext', 'link', 'list', 'image'], $this->types->names());
        self::assertTrue($this->types->has('list'));
        self::assertFalse($this->types->has('video'));
        self::assertTrue(FieldTypeName::isValid('acme/shop/price'));
        self::assertFalse(FieldTypeName::isValid('Price'));

        $this->expectException(DefinitionException::class);
        $this->types->get('video');
    }

    public function testExtensionsNameTheirTypesAfterThePackage(): void
    {
        $registry = new Registry();
        $registry->forPackage('acme/shop', static fn() => $registry->fieldType(PriceType::class));
        self::assertSame(['acme/shop/price' => PriceType::class], $registry->fieldTypes());

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('The field type ' . PriceType::class . ' of acme/other must be named "acme/other/price".');
        $registry->forPackage('acme/other', static fn() => $registry->fieldType(PriceType::class));
    }

    public function testTheCoreUsesPlainNames(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('must not have a package in its name "acme/shop/price"');

        new Registry()->fieldType(PriceType::class);
    }

    public function testNamesAreRegisteredOnce(): void
    {
        $registry = new Registry();
        $registry->fieldType(HeadlineType::class);

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('The field type "headline" is registered twice');
        $registry->fieldType(HeadlineType::class);
    }

    public function testFieldTypesNeedTheAttribute(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('needs #[AsFieldType(\'name\')]');

        FieldTypes::nameOf(UnnamedType::class);
    }

    public function testFieldTypesImplementTheInterface(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('must implement ' . FieldType::class);

        FieldTypes::nameOf(self::class);
    }

    /**
     * @param array<mixed> $data
     */
    private static function at(array $data, string ...$keys): mixed
    {
        $current = $data;

        foreach ($keys as $key) {
            self::assertIsArray($current);
            self::assertArrayHasKey($key, $current);
            $current = $current[$key];
        }

        return $current;
    }
}
