<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\ObjectField;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\Types\ChoiceType;
use Frootbox\Core\Field\Types\DateType;
use Frootbox\Core\Field\Types\NumberType;
use Frootbox\Core\ObjectField\ObjectOutput;
use Frootbox\Core\ObjectField\ObjectSchema;
use Frootbox\Core\ObjectField\ShowObjectFields;
use Frootbox\Core\ObjectField\SpaceFields;
use Frootbox\Core\ObjectField\UpdateObjectField;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Plugin\AssignPlugin;
use Frootbox\Core\Plugin\Space;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Tests\Fixtures\Plugin\ItemFields;
use Frootbox\Core\Tests\Fixtures\Plugin\ItemPlugin;
use Frootbox\Core\Tests\Fixtures\Plugin\PublishItems;
use Frootbox\Core\Tests\Fixtures\Plugin\SaveItem;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SpaceFields::class)]
#[CoversClass(ObjectSchema::class)]
#[CoversClass(ObjectOutput::class)]
#[CoversClass(NumberType::class)]
#[CoversClass(DateType::class)]
#[CoversClass(ChoiceType::class)]
final class SpaceFieldsTest extends NotesTestCase
{
    private const string ITEM = '01JOBJECTITEM0000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->get(Registry::class)->add(SpaceFields::class, new SpaceFields(ItemPlugin::TYPE, ItemFields::class));
        $this->get(ItemFields::class)->spaces[Space::DEFAULT] = [
            'altitude' => ['type' => 'number', 'label' => 'Höhe', 'labels' => ['de' => 'Höhe', 'en' => 'Altitude'], 'unit' => 'm', 'min' => 0],
            'harvest' => ['type' => 'date', 'label' => 'Ernte'],
            'variety' => ['type' => 'choice', 'label' => 'Sorte', 'options' => ['arabica' => 'Arabica', 'mixed' => ['de' => 'Gemischt', 'en' => 'Mixed']]],
            'organic' => ['type' => 'boolean', 'label' => 'Bio'],
            'origin' => ['type' => 'text', 'label' => 'Herkunft'],
            'subtitle' => ['type' => 'headline', 'label' => 'Taken by the view'],
        ];
    }

    public function testAddsTheFieldsOfTheSpace(): void
    {
        $schema = $this->get(ObjectSchema::class);
        self::assertSame(['subtitle', 'gallery', 'noContact', 'code'], array_keys($schema->fields(ItemPlugin::TYPE)), 'Without a space only those of the views.');
        self::assertSame(['subtitle', 'gallery', 'noContact', 'code', 'altitude', 'harvest', 'variety', 'organic', 'origin'], array_keys($schema->fields(ItemPlugin::TYPE, Space::DEFAULT)), 'The views keep their names.');
        self::assertSame(['altitude', 'harvest', 'variety', 'organic', 'origin'], array_keys($schema->spaceFields(ItemPlugin::TYPE, Space::DEFAULT)));
        self::assertSame([], $schema->spaceFields(ItemPlugin::TYPE, '01JSPACE000000000000000001'));
        self::assertTrue($schema->field(ItemPlugin::TYPE, 'origin', Space::DEFAULT)?->translatable);
        self::assertSame('Altitude', $schema->field(ItemPlugin::TYPE, 'altitude', Space::DEFAULT)?->label(['en', 'de']));
        self::assertSame('Ernte', $schema->field(ItemPlugin::TYPE, 'harvest', Space::DEFAULT)?->label(['en', 'de']), 'Without labels the label of the field.');
        self::assertSame([], $schema->problems());

        $described = $this->runner()->ask(new ShowObjectFields(ItemPlugin::TYPE, Space::DEFAULT));
        self::assertSame(['altitude', ['de' => 'Höhe', 'en' => 'Altitude'], ['unit' => 'm', 'min' => 0], ['type' => 'integer', 'minimum' => 0]], [$described[4]['name'], $described[4]['labels'], $described[4]['settings'], $described[4]['value']]);
        self::assertSame(['type' => 'string', 'enum' => ['arabica', 'mixed']], $described[6]['value']);
    }

    public function testChecksTheValuesAgainstTheFieldsOfTheSpace(): void
    {
        $this->save(['altitude' => 1650, 'harvest' => '2026-05-01', 'variety' => 'mixed', 'organic' => false, 'origin' => ['de' => 'Huila']]);
        self::assertSame(['altitude' => 1650, 'harvest' => '2026-05-01', 'variety' => 'mixed', 'organic' => false, 'origin' => ['de' => 'Huila']], $this->draft());

        $this->assertInvalid('/fields/altitude', ['altitude' => -1]);
        $this->assertInvalid('/fields/altitude', ['altitude' => 1.5], 'Without decimals a whole number.');
        $this->assertInvalid('/fields/harvest', ['harvest' => '2026-02-30']);
        $this->assertInvalid('/fields/variety', ['variety' => 'robusta']);
        $this->assertInvalid('/fields/altitude', ['altitude' => 1], 'Another space has other fields.', '01JSPACE000000000000000001');
    }

    public function testShowsTheFactSheet(): void
    {
        $start = $this->page(null, 'Start');
        $news = $this->page($start, 'News');
        $this->runner()->run(new AssignPlugin($news, ItemPlugin::TYPE));
        $this->runner()->run(new PublishPages([$start, $news]));
        $this->save(['altitude' => 1650, 'harvest' => '2026-05-01', 'variety' => 'mixed', 'organic' => true, 'origin' => ['de' => 'Huila & Cauca']]);
        $this->runner()->run(new PublishItems([self::ITEM]));

        $html = $this->html('/news/neue-sorten');
        self::assertStringContainsString("<dt>Höhe</dt><dd>1.650\u{00A0}m</dd>", $html);
        self::assertStringContainsString('<dt>Ernte</dt><dd><time datetime="2026-05-01">1. Mai 2026</time></dd>', $html);
        self::assertStringContainsString('<dt>Sorte</dt><dd>Gemischt</dd>', $html);
        self::assertStringContainsString('<dt>Herkunft</dt><dd>Huila &amp; Cauca</dd>', $html);

        $output = $this->get(ObjectOutput::class);
        self::assertSame(1650, $output->value(ItemPlugin::TYPE, self::ITEM, 'altitude', ['de']));
        self::assertSame('mixed', $output->value(ItemPlugin::TYPE, self::ITEM, 'variety', ['de']));
        $output->field(ItemPlugin::TYPE, self::ITEM, 'altitude', ['de']);
        self::assertSame(['harvest', 'variety', 'organic'], array_column($output->facts(ItemPlugin::TYPE, self::ITEM, ['de'], ['origin']), 'name'), 'What the view showed or leaves out is not a fact.');
        self::assertSame("1.650\u{00A0}m", NumberType::format(1650, $this->get(ObjectSchema::class)->field(ItemPlugin::TYPE, 'altitude', Space::DEFAULT)->field ?? throw new LogicException(), 'de'));
        self::assertSame('1,650', NumberType::format(1650, new Field('n', 'number', 'N'), 'en'));
    }

    public function testChangesAFieldOfTheSpaceOnTheWebsite(): void
    {
        $this->save([]);
        $this->runner()->run(new UpdateObjectField(ItemPlugin::TYPE . ':' . self::ITEM, 'de', 'origin', 'Huila'));
        self::assertSame(['origin' => ['de' => 'Huila']], $this->draft());
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
    private function save(array $fields, string $space = Space::DEFAULT): void
    {
        $this->runner()->run(new SaveItem(self::ITEM, ['de' => 'Neue Sorten'], ['de' => 'neue-sorten'], space: $space, fields: $fields));
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
    private function assertInvalid(string $pointer, array $fields, string $message = '', string $space = Space::DEFAULT): void
    {
        try {
            $this->save($fields, $space);
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
