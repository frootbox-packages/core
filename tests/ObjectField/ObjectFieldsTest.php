<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\ObjectField;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Component\Components;
use Frootbox\Core\Component\DefinitionErrors;
use Frootbox\Core\Component\DefinitionReader;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\ObjectField\ObjectField;
use Frootbox\Core\ObjectField\ObjectFields;
use Frootbox\Core\ObjectField\ObjectFieldSet;
use Frootbox\Core\ObjectField\ObjectOutput;
use Frootbox\Core\ObjectField\ObjectSchema;
use Frootbox\Core\ObjectField\ShowObjectFields;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Plugin\AssignPlugin;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Tests\Fixtures\Plugin\ItemPlugin;
use Frootbox\Core\Tests\Fixtures\Plugin\PublishItems;
use Frootbox\Core\Tests\Fixtures\Plugin\SaveItem;
use Frootbox\Core\Tests\Fixtures\Plugin\UnpublishItem;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Tests\Support\TemporaryProject;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Error\RuntimeError;

#[CoversClass(ObjectSchema::class)]
#[CoversClass(ObjectFields::class)]
#[CoversClass(ObjectOutput::class)]
#[CoversClass(ObjectField::class)]
#[CoversClass(ShowObjectFields::class)]
#[CoversClass(DefinitionReader::class)]
final class ObjectFieldsTest extends NotesTestCase
{
    private const string ITEM = '01JOBJECTITEM0000000000001';

    public function testGathersTheFieldsOfATypeFromAllViews(): void
    {
        $fields = $this->get(ObjectSchema::class)->fields(ItemPlugin::TYPE);
        self::assertSame(['subtitle', 'gallery', 'noContact', 'code'], array_keys($fields), 'From the override of the plugin view and from a block that names the type.');
        self::assertSame(['acme/plugin/Items, view Default (acme/objects)', 'acme/objects/Card, view Default'], $fields['subtitle']->sources, 'The same name in two views is one field.');
        self::assertTrue($fields['subtitle']->translatable, 'A text holds one per language.');
        self::assertFalse($fields['gallery']->translatable, 'An image one for all.');
        self::assertFalse($fields['code']->translatable, 'Unless the view says otherwise.');
        self::assertSame([], $this->get(ObjectSchema::class)->problems());

        $described = $this->runner()->ask(new ShowObjectFields(ItemPlugin::TYPE));
        self::assertSame(['subtitle', 'text', true, ['type' => 'string', 'maxLength' => 20, 'pattern' => '^[^\r\n]*$']], [$described[0]['name'], $described[0]['type'], $described[0]['translatable'], $described[0]['value']], 'The API learns the schema of a value.');
        self::assertSame([], $this->runner()->ask(new ShowObjectFields('acme/other/thing')));
    }

    public function testKeepsTheValuesInTheDraftAndPublishesThem(): void
    {
        $this->save(['subtitle' => ['de' => 'Frisch geröstet'], 'noContact' => true, 'gallery' => ['media' => '01JMEDXA000000000000000001', 'alt' => 'Bohnen']]);
        self::assertEquals(['subtitle' => ['de' => 'Frisch geröstet'], 'noContact' => true, 'gallery' => ['media' => '01JMEDXA000000000000000001', 'alt' => 'Bohnen']], $this->draft());
        $objects = $this->get(ObjectFields::class);
        self::assertSame([self::ITEM => []], $objects->of(ItemPlugin::TYPE, [self::ITEM]), 'Drafts keep them themselves.');

        $this->runner()->run(new PublishItems([self::ITEM]));
        self::assertSame(['de' => 'Frisch geröstet'], $this->get(ObjectFields::class)->of(ItemPlugin::TYPE, [self::ITEM])[self::ITEM]['subtitle'] ?? null);

        $this->save(['subtitle' => ['en' => 'Freshly roasted'], 'noContact' => null, 'gallery' => []]);
        self::assertSame(['subtitle' => ['de' => 'Frisch geröstet', 'en' => 'Freshly roasted']], $this->draft(), 'Languages add up, null and empty remove.');
        $this->save(['subtitle' => ['de' => ' ']]);
        self::assertSame(['subtitle' => ['en' => 'Freshly roasted']], $this->draft());
        $this->save([]);
        self::assertSame(['subtitle' => ['en' => 'Freshly roasted']], $this->draft(), 'What is left out stays.');

        $this->runner()->run(new UnpublishItem(self::ITEM));
        self::assertSame([self::ITEM => []], $this->get(ObjectFields::class)->of(ItemPlugin::TYPE, [self::ITEM]));
    }

    public function testRefusesWhatTheFieldsDoNotAllow(): void
    {
        $this->assertInvalid('/fields/price', ['price' => 12]);
        $this->assertInvalid('/fields/subtitle/de', ['subtitle' => ['de' => str_repeat('x', 21)]]);
        $this->assertInvalid('/fields/subtitle', ['subtitle' => 'Frisch'], 'A text per language.');
        $this->assertInvalid('/fields/subtitle/fr', ['subtitle' => ['fr' => 'Frais']]);
        $this->assertInvalid('/fields/noContact', ['noContact' => 'yes']);
        $this->assertInvalid('/fields/gallery/alt', ['gallery' => ['media' => '01JMEDXA000000000000000001', 'alt' => 3]]);

        // A value no view knows any more may still be removed.
        $this->save(['code' => 'A1']);
        self::assertSame(['code' => 'A1'], $this->draft());
        $this->save(['price' => null, 'code' => null]);
        self::assertSame([], $this->draft());
    }

    public function testShowsTheFieldsInTheViewOfTheObject(): void
    {
        $start = $this->page(null, 'Start');
        $news = $this->page($start, 'News');
        $this->runner()->run(new AssignPlugin($news, ItemPlugin::TYPE));
        $this->runner()->run(new PublishPages([$start, $news]));
        $this->save(['subtitle' => ['de' => 'Frisch & geröstet']]);
        $this->runner()->run(new PublishItems([self::ITEM]));

        $html = $this->html('/news/neue-sorten');
        self::assertStringContainsString('<p class="subtitle">Frisch &amp; geröstet</p>', $html, 'As the field type shows it, escaped.');
        self::assertStringContainsString('<p class="contact">Call us</p>', $html, 'A switch left out is no.');

        $this->save(['noContact' => true]);
        $this->runner()->run(new PublishItems([self::ITEM]));
        self::assertStringNotContainsString('Call us', $this->html('/news/neue-sorten'));

        $output = $this->get(ObjectOutput::class);
        self::assertTrue($output->value(ItemPlugin::TYPE, self::ITEM, 'noContact', ['de']));
        self::assertNull($output->value(ItemPlugin::TYPE, self::ITEM, 'gallery', ['de']));
        self::assertSame('Frisch & geröstet', $output->field(ItemPlugin::TYPE, self::ITEM, 'subtitle', ['en', 'de']), 'In the fallback language.');

        $this->save(['subtitle' => null]);
        $this->runner()->run(new PublishItems([self::ITEM]));
        self::assertStringNotContainsString('class="subtitle"', $this->html('/news/neue-sorten'), 'An empty field leaves out its element, as on pages.');
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('acme/plugin/item has no field "price"');
        $output->field(ItemPlugin::TYPE, self::ITEM, 'price', ['de']);
    }

    public function testANameHasOneTypeForAllSources(): void
    {
        $this->get(Registry::class)->add(ObjectFieldSet::class, new ObjectFieldSet(ItemPlugin::TYPE, ['subtitle' => ['type' => 'headline'], 'downloads' => ['type' => 'list', 'fields' => ['file' => ['type' => 'file']]]], 'acme/extra'));
        $schema = $this->get(ObjectSchema::class);
        self::assertSame(['subtitle', 'gallery', 'noContact', 'code', 'downloads'], array_keys($schema->fields(ItemPlugin::TYPE)), 'An extension adds fields, too.');
        self::assertSame('text', $schema->field(ItemPlugin::TYPE, 'subtitle')?->field->type, 'The first one counts.');
        self::assertCount(1, $schema->problems());
        self::assertStringContainsString('the field "subtitle" of acme/plugin/item is a headline per language there, but a text per language in acme/plugin/Items, view Default (acme/objects), acme/objects/Card, view Default', $schema->problems()[0]);

        $this->expectException(RuntimeError::class);
        $this->get(ObjectOutput::class)->field(ItemPlugin::TYPE, self::ITEM, 'subtitle', ['de']);
    }

    public function testTheFieldsSurviveTheCacheOfLiveWebsites(): void
    {
        $project = TemporaryProject::create();
        $extensions = $this->extensions();

        try {
            $first = FixtureSite::container($project, 'acme/objects', Environment::Production, $extensions)->get(Components::class);
            $again = FixtureSite::container($project, 'acme/objects', Environment::Production, $extensions)->get(Components::class);
            self::assertInstanceOf(Components::class, $again);
            self::assertEquals($first, $again);
            self::assertSame(['subtitle', 'gallery', 'noContact'], array_keys($again->block('acme/plugin/Items')?->variant(null)->object->fields ?? []), 'Read back from the cache.');
        } finally {
            $project->remove();
        }
    }

    public function testSwitchesAreForObjectsAlone(): void
    {
        $errors = new DefinitionErrors();
        $this->get(DefinitionReader::class)->read(['title' => 'Box', 'fields' => ['on' => ['type' => 'boolean']], 'object' => ['fields' => ['on' => ['type' => 'boolean', 'translatable' => true], 'list' => ['type' => 'list', 'fields' => ['text' => ['type' => 'text', 'translatable' => false]]]]]], DefinitionReader::FULL_KEYS, true, 'acme/box', 'Box', $errors);
        self::assertCount(2, $errors->all());
        self::assertStringContainsString('fields.on.type: "boolean" is for fields of objects under "object.fields"; a block uses an option for it', $errors->all()[0]);
        self::assertStringContainsString('object.fields.list.fields.text', $errors->all()[1], 'Only a field of the object holds values per language.');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'plugin', 'objects');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/objects', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English', 'fallback' => 'de']]];
    }

    /**
     * @param array<mixed> $fields
     */
    private function save(array $fields): void
    {
        $this->runner()->run(new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'neue-sorten'], fields: $fields));
    }

    /**
     * @return array<mixed>
     */
    private function draft(): array
    {
        $fields = $this->get(Revisions::class)->draft(ItemPlugin::TYPE, self::ITEM)?->data['fields'] ?? null;

        return \is_array($fields) ? $fields : [];
    }

    /**
     * @param array<mixed> $fields
     */
    private function assertInvalid(string $pointer, array $fields, string $message = ''): void
    {
        $action = new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'neue-sorten'], fields: $fields);

        try {
            $this->runner()->run($action);
            self::fail('' === $message ? 'Expected an error at ' . $pointer : $message);
        } catch (InvalidInputException $exception) {
            self::assertArrayHasKey($pointer, $exception->errors, $message . ' ' . json_encode($exception->errors));
        }
    }

    private function page(?string $parentId, string $title): string
    {
        $content = ['fields' => ['heading' => $title], 'areas' => ['main' => []]];

        return $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => $title, 'content' => $content]], $parentId))->id;
    }

    private function html(string $path): string
    {
        return (string) $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org' . $path))->getBody();
    }
}
