<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Field;

use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Field\Blank;
use Frootbox\Core\Field\EntryKey;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Types\ListType;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Frootbox\Core\View\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Entries of lists carry ids the core gives them (decision 0344).
 */
#[CoversClass(ListType::class)]
#[CoversClass(EntryKey::class)]
#[CoversClass(Blank::class)]
#[CoversClass(Scope::class)]
final class EntryIdsTest extends TestCase
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
        $this->fields = $components->block('acme/site/Hero')->fields ?? [];
        $slides = $this->fields['slides'];
        // A list in a list, to see that its entries get ids as well.
        $this->fields['slides'] = new Field('slides', 'list', 'Slides', settings: $slides->settings, fields: [
            ...$slides->fields,
            'links' => new Field('links', 'list', 'Links', optional: true, fields: ['text' => new Field('text', 'text', 'Text')]),
        ]);
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testGivesIdsKeepsThemAndReplacesDoubles(): void
    {
        $values = $this->types->normalize($this->fields, ['intro' => 'Hallo', 'slides' => [
            ['id' => 'k3f9x2ab', 'title' => 'Eins'],
            ['title' => 'Zwei', 'links' => [['text' => 'a'], ['id' => 'k3f9x2ab', 'text' => 'b']]],
            ['id' => 'k3f9x2ab', 'title' => 'Doppelt'],
            ['id' => 'Not An Id', 'title' => 'Falsch'],
            new \stdClass(),
        ]]);

        self::assertSame('Hallo', $values['intro'], 'Other fields stay.');
        $ids = array_map(fn(int $index): string => self::id($values, 'slides', $index), range(0, 4));
        self::assertCount(5, array_unique($ids), 'Every entry has its own id.');
        self::assertSame('k3f9x2ab', $ids[0], 'An id stays.');
        self::assertSame(['id' => $ids[2], 'title' => 'Doppelt'], self::at($values, 'slides', 2), 'A double gets a new one.');
        self::assertNotSame('Not An Id', $ids[3], 'A broken one too.');
        self::assertSame(['id' => $ids[4]], self::at($values, 'slides', 4), 'An empty entry gets one.');
        self::assertSame('k3f9x2ab', self::id($values, 'slides', 1, 'links', 1), 'Ids count within their list.');
        self::assertNotSame('', self::id($values, 'slides', 1, 'links', 0), 'Lists in lists get ids.');
        self::assertSame($values, $this->types->normalize($this->fields, $values), 'Normalizing twice changes nothing.');
    }

    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * The id of the entry at the keys, checked for its format.
     */
    private static function id(mixed $value, string|int ...$keys): string
    {
        $id = self::at($value, ...[...$keys, 'id']);
        self::assertIsString($id);
        self::assertMatchesRegularExpression('/^[a-z0-9]{8}$/D', $id);

        return $id;
    }

    public function testTheSchemaTakesIdsAndBlankEntriesIgnoreThem(): void
    {
        $validator = new InputValidator();
        $values = ['slides' => [['id' => 'k3f9x2ab', 'title' => 'Eins']]];

        self::assertSame([], $validator->messages($this->types->schemaFor($this->fields), $values));
        self::assertArrayHasKey('/slides/0/id', $validator->messages($this->types->schemaFor($this->fields), ['slides' => [['id' => 'BAD', 'title' => 'Eins']]]));

        // A blank entry with only its id is left out of publishing (decision 0144).
        $publishing = $this->types->schemaForPublishing($this->fields, ['slides' => [['id' => 'k3f9x2ab', 'title' => 'Eins'], ['id' => 'p0q7m1zc']]]);
        self::assertSame([], $validator->messages($publishing, ['slides' => [['id' => 'k3f9x2ab', 'title' => 'Eins'], ['id' => 'p0q7m1zc']]]));
        self::assertTrue(Blank::entry(['id' => 'p0q7m1zc', 'links' => [['id' => 'a1b2c3d4']]]));
        self::assertFalse(Blank::entry(['id' => 'p0q7m1zc', 'title' => 'Eins']));
    }

    public function testFindsEntriesByPositionOrId(): void
    {
        $entries = [['id' => 'k3f9x2ab'], ['id' => 'p0q7m1zc'], ['title' => 'Alt']];

        self::assertSame(1, EntryKey::position($entries, '1'));
        self::assertSame(1, EntryKey::position($entries, 'p0q7m1zc'));
        self::assertNull(EntryKey::position($entries, '3'), 'No entry there.');
        self::assertNull(EntryKey::position($entries, 'zzzzzzzz'), 'No entry with the id any more.');
        self::assertNull(EntryKey::position($entries, '01'));
        self::assertNull(EntryKey::position(['not' => 'a list'], '0'));
        self::assertTrue(EntryKey::isId('12345678'), 'Eight digits are an id, positions have at most seven.');
        self::assertFalse(EntryKey::isId('1234567'));
    }

    public function testTemplatesGetTheId(): void
    {
        $list = $this->types->get('list')->output([['id' => 'k3f9x2ab', 'title' => 'Eins'], ['title' => 'Alt']], $this->fields['slides']);

        self::assertIsIterable($list);
        $scopes = [...$list];
        self::assertInstanceOf(Scope::class, $scopes[0]);
        self::assertSame('k3f9x2ab', $scopes[0]->id());
        self::assertInstanceOf(Scope::class, $scopes[1]);
        self::assertNull($scopes[1]->id(), 'Saved before ids.');
    }
}
