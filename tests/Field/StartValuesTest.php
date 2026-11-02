<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Field;

use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\StartValues;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StartValues::class)]
final class StartValuesTest extends TestCase
{
    public function testGivesListsTheirStartEntries(): void
    {
        $values = StartValues::of([
            'title' => new Field('title', 'headline', 'Title'),
            'items' => $this->list(['start' => 3]),
            'none' => $this->list([]),
            'least' => $this->list(['min' => 2]),
            'atLeastMin' => $this->list(['min' => 2, 'start' => 1]),
            'atMostMax' => $this->list(['max' => 2, 'start' => 5]),
            'nested' => new Field('nested', 'list', 'Nested', fields: ['inner' => $this->list(['start' => 1])], settings: ['start' => 1]),
        ]);

        self::assertSame(['items', 'least', 'atLeastMin', 'atMostMax', 'nested'], array_keys($values), 'Fields without lists or start get nothing.');
        self::assertSame('[{},{},{}]', json_encode($values['items']), 'Empty entries stay objects.');
        self::assertCount(2, $values['least'], 'Without start, as many as min asks.');
        self::assertCount(2, $values['atLeastMin']);
        self::assertCount(2, $values['atMostMax']);
        self::assertSame('[{"inner":[{}]}]', json_encode($values['nested']), 'Lists in entries begin the same way.');
    }

    /**
     * @param array<string, int> $settings
     */
    private function list(array $settings): Field
    {
        return new Field('items', 'list', 'Items', settings: $settings, fields: ['title' => new Field('title', 'headline', 'Title')]);
    }
}
