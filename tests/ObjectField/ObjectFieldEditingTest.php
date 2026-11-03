<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\ObjectField;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\ObjectField\MarkedObjects;
use Frootbox\Core\ObjectField\ObjectFields;
use Frootbox\Core\ObjectField\ObjectOutput;
use Frootbox\Core\ObjectField\UpdateObjectField;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Tests\Fixtures\Plugin\ItemPlugin;
use Frootbox\Core\Tests\Fixtures\Plugin\PublishItems;
use Frootbox\Core\Tests\Fixtures\Plugin\SaveItem;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Markup;

#[CoversClass(UpdateObjectField::class)]
#[CoversClass(ObjectOutput::class)]
#[CoversClass(MarkedObjects::class)]
#[CoversClass(ObjectFields::class)]
final class ObjectFieldEditingTest extends NotesTestCase
{
    private const string ITEM = '01JOBJECTITEM0000000000001';

    private const string TARGET = ItemPlugin::TYPE . ':' . self::ITEM;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner()->run(new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'neue-sorten'], fields: ['subtitle' => ['de' => 'Frisch'], 'noContact' => true]));
        $this->runner()->run(new PublishItems([self::ITEM]));
    }

    public function testMarksTheFieldsWithTheDraftWhileEditing(): void
    {
        $this->runner()->run(new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'neue-sorten'], fields: ['subtitle' => ['de' => 'Im Entwurf'], 'noContact' => null]));
        $output = $this->get(ObjectOutput::class);
        self::assertSame('Frisch', $output->field(ItemPlugin::TYPE, self::ITEM, 'subtitle', ['de']), 'Visitors get the published value.');
        self::assertTrue($output->value(ItemPlugin::TYPE, self::ITEM, 'noContact', ['de']));

        $this->get(EditMode::class)->set(true);
        self::assertSame('Im Entwurf', $output->field(ItemPlugin::TYPE, self::ITEM, 'subtitle', ['de']), 'Drafts show the draft, without marks.');
        self::assertFalse($output->value(ItemPlugin::TYPE, self::ITEM, 'noContact', ['de']));

        $this->get(EditMode::class)->set(true, true);
        $marked = $this->marked($output->field(ItemPlugin::TYPE, self::ITEM, 'subtitle', ['en', 'de']));
        self::assertStringContainsString('<fb-field data-fb-field="subtitle" data-fb-type="text" data-fb-label="Subtitle"', $marked);
        self::assertStringContainsString('data-fb-object="' . self::TARGET . '" data-fb-language="en" data-fb-optional="" tabindex="0" data-fb-value="&quot;Im Entwurf&quot;">Im Entwurf</fb-field>', $marked, 'Saved in the language shown, the value from the fallback.');
        self::assertSame([['type' => ItemPlugin::TYPE, 'id' => self::ITEM, 'label' => 'Items', 'title' => 'Neue Sorten', 'draft' => true]], $this->get(MarkedObjects::class)->all(), 'The bar publishes it with the page.');
        self::assertStringContainsString('<fb-field data-fb-field="gallery" data-fb-type="image"', $this->marked($output->field(ItemPlugin::TYPE, self::ITEM, 'gallery', ['de'])), 'An empty image as well, to choose one.');
    }

    public function testMarksOnlyWhatTheUserMayChange(): void
    {
        $this->get(EditMode::class)->set(true, true);
        $user = $this->runner()->run(new CreateUser('jane@example.org', 'Jane', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($user->id, 'editor'));
        $this->get(CurrentContext::class)->set(new Context(Actor::user($user->id, $user->name), Channel::Web));

        self::assertSame('Frisch', $this->get(ObjectOutput::class)->field(ItemPlugin::TYPE, self::ITEM, 'subtitle', ['de']));
        self::assertSame([], $this->get(MarkedObjects::class)->all());
    }

    public function testSavesAFieldThroughTheActionOfTheModule(): void
    {
        $result = $this->runner()->run(new UpdateObjectField(self::TARGET, 'en', 'subtitle', 'Fresh <roast>', 'Frisch'));
        self::assertSame(['Fresh <roast>', 'Fresh &lt;roast&gt;'], [$result->value, $result->html]);
        self::assertSame(['de' => 'Frisch', 'en' => 'Fresh <roast>'], $this->get(ObjectFields::class)->draft(ItemPlugin::TYPE, self::ITEM)['subtitle'] ?? null, 'One language in the draft.');
        self::assertSame(['de' => 'Frisch'], $this->get(ObjectFields::class)->of(ItemPlugin::TYPE, [self::ITEM])[self::ITEM]['subtitle'] ?? null, 'Published stays.');

        $removed = $this->runner()->run(new UpdateObjectField(self::TARGET, 'en', 'subtitle', null, 'Fresh <roast>'));
        self::assertSame('Frisch', $removed->value, 'Without its own value the fallback shows.');

        $gallery = ['media' => '01JMEDXA000000000000000001', 'alt' => 'Bohnen'];
        $saved = $this->runner()->run(new UpdateObjectField(self::TARGET, 'de', 'gallery', $gallery));
        self::assertEquals($gallery, $this->get(ObjectFields::class)->draft(ItemPlugin::TYPE, self::ITEM)['gallery'] ?? null, 'One value for all languages.');
        self::assertNotNull($saved->entry);

        $this->runner()->run(new UndoAction($saved->entry));
        self::assertArrayNotHasKey('gallery', $this->get(ObjectFields::class)->draft(ItemPlugin::TYPE, self::ITEM) ?? []);
    }

    public function testRefusesConflictsUnknownFieldsAndOthers(): void
    {
        try {
            $this->runner()->run(new UpdateObjectField(self::TARGET, 'de', 'price', 3));
            self::fail('An unknown field.');
        } catch (InvalidInputException $exception) {
            self::assertArrayHasKey('/name', $exception->errors);
        }

        try {
            $this->runner()->run(new UpdateObjectField(self::TARGET, 'de', 'subtitle', str_repeat('y', 30), 'Frisch'));
            self::fail('Too long.');
        } catch (InvalidInputException $exception) {
            self::assertArrayHasKey('/fields/subtitle/de', $exception->errors);
        }

        $user = $this->runner()->run(new CreateUser('jane@example.org', 'Jane', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($user->id, 'editor'));

        try {
            $this->runner()->run(new UpdateObjectField(self::TARGET, 'de', 'subtitle', 'Fremd', 'Frisch'), new Context(Actor::user($user->id, $user->name), Channel::Web));
            self::fail('The action of the module checks the rights.');
        } catch (AccessDeniedException) {
        }

        $this->expectException(ConflictException::class);
        $this->runner()->run(new UpdateObjectField(self::TARGET, 'de', 'subtitle', 'Neu', 'Alt'));
    }

    private function marked(mixed $output): string
    {
        self::assertInstanceOf(Markup::class, $output);

        return (string) $output;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'plugin', 'objects');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/objects', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English', 'fallback' => 'de']]];
    }
}
