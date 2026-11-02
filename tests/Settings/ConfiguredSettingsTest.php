<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Settings;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Settings\SetSecret;
use Frootbox\Core\Settings\Settings;
use Frootbox\Core\Settings\ShowSettings;
use Frootbox\Core\Settings\UpdateSettings;
use Frootbox\Core\Tests\Fixtures\Notes\NotesSettings;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Values in config/extensions/… win and are locked (decision 0221).
 */
#[CoversClass(Settings::class)]
final class ConfiguredSettingsTest extends NotesTestCase
{
    public function testTheConfigurationWinsAndLocksTheValue(): void
    {
        $settings = $this->get(NotesSettings::class);
        self::assertSame('team@example.test', $settings->sender);
        self::assertSame('from-env', $settings->apiKey);
        self::assertSame(3.0, $settings->ratio);

        $details = $this->runner()->ask(new ShowSettings('acme/notes'));
        self::assertTrue($details->fields[0]->locked);
        self::assertFalse($details->fields[1]->locked);
        self::assertTrue($details->fields[5]->set);

        try {
            $this->runner()->run(new UpdateSettings('acme/notes', ['sender' => 'me@example.test']));
            self::fail('A configured value cannot be changed.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/values/sender', $error->errors);
        }

        $this->expectException(InvalidInputException::class);
        $this->runner()->run(new SetSecret('acme/notes', 'apiKey', 'other'));
    }

    protected function config(): array
    {
        return ['extensions' => ['acme/notes' => ['sender' => 'team@example.test', 'apiKey' => 'from-env', 'ratio' => 3]]];
    }
}
