<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Action;

use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Action\Schema\Field;
use Frootbox\Core\Action\Schema\Fields;
use Frootbox\Core\Action\Schema\SchemaFactory;
use Frootbox\Core\Action\UnknownActionException;
use Frootbox\Core\Tests\Fixtures\Broken\BadName;
use Frootbox\Core\Tests\Fixtures\Broken\NotPromoted;
use Frootbox\Core\Tests\Fixtures\Broken\UnionTyped;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Fixtures\Notes\ListNotes;
use Frootbox\Core\Tests\Fixtures\Notes\Subscribe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaFactory::class)]
#[CoversClass(Fields::class)]
#[CoversClass(Field::class)]
#[CoversClass(Descriptor::class)]
#[CoversClass(Catalog::class)]
#[UsesClass(AsAction::class)]
final class SchemaFactoryTest extends TestCase
{
    public function testBuildsTheSchemaFromTheConstructor(): void
    {
        self::assertSame([
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Short title', 'minLength' => 1, 'maxLength' => 100],
                'body' => ['type' => ['string', 'null'], 'default' => null],
                'priority' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10, 'default' => 0],
                'status' => ['enum' => ['draft', 'published'], 'default' => 'draft'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
                'publishedAt' => ['type' => ['string', 'null'], 'format' => 'date-time', 'default' => null],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ], SchemaFactory::forClass(CreateNote::class));
    }

    public function testMarksSecretsAndFormats(): void
    {
        $properties = SchemaFactory::forClass(Subscribe::class)['properties'] ?? null;
        self::assertIsArray($properties);

        self::assertSame(['type' => 'string', 'format' => 'email'], $properties['email'] ?? null);
        self::assertSame(['type' => 'string', 'pattern' => '^.{8,}$', 'writeOnly' => true], $properties['password'] ?? null);
    }

    public function testNullableEnumsAllowNull(): void
    {
        $properties = SchemaFactory::forClass(ListNotes::class)['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertSame(['enum' => ['draft', 'published', null], 'default' => null], $properties['status'] ?? null);
    }

    public function testDescribesActions(): void
    {
        $descriptor = Descriptor::of(CreateNote::class);

        self::assertSame('note.create', $descriptor->name);
        self::assertSame('note.create', $descriptor->permission);
        self::assertTrue($descriptor->isAction());
        self::assertTrue($descriptor->undoable);
        self::assertFalse($descriptor->public);
    }

    public function testRejectsBadDefinitions(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('public promoted constructor property');

        SchemaFactory::forClass(NotPromoted::class);
    }

    public function testRejectsUnsupportedTypes(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('exactly one type');

        SchemaFactory::forClass(UnionTyped::class);
    }

    public function testRejectsBadNames(): void
    {
        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('lowercase with at least one dot');

        Descriptor::of(BadName::class);
    }

    public function testCatalogRejectsDuplicatesAndUnknownNames(): void
    {
        $catalog = new Catalog();
        $catalog->add(CreateNote::class);

        try {
            $catalog->get('note.nope');
            self::fail('Expected an unknown name.');
        } catch (UnknownActionException) {
        }

        $this->expectException(DefinitionException::class);
        $this->expectExceptionMessage('registered twice');
        $catalog->add(CreateNote::class);
    }
}
