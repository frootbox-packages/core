<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Ai;

use DateTimeImmutable;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Ai\Assistant;
use Frootbox\Core\Ai\Message;
use Frootbox\Core\Ai\ModelPrices;
use Frootbox\Core\Ai\PriceList;
use Frootbox\Core\Ai\PricesOnSchedule;
use Frootbox\Core\Ai\Provider;
use Frootbox\Core\Ai\Providers;
use Frootbox\Core\Ai\Request;
use Frootbox\Core\Ai\ShowAiStatus;
use Frootbox\Core\Ai\Usage;
use Frootbox\Core\Ai\UsageLog;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Hub\HubClient;
use Frootbox\Core\Hub\HubConnections;
use Frootbox\Core\Hub\HubException;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Schedule\ScheduleRan;
use Frootbox\Core\Security\Secrets;
use Frootbox\Core\Security\StandardWebhooks;
use Frootbox\Core\Tests\Fixtures\Ai\FakeAi;
use Frootbox\Core\Tests\Hub\FakeHub;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The prices of the AI from the hub (decision 0297).
 */
#[CoversClass(PriceList::class)]
#[CoversClass(ModelPrices::class)]
#[CoversClass(PricesOnSchedule::class)]
#[CoversClass(HubClient::class)]
final class PriceListTest extends NotesTestCase
{
    private FakeHub $hub;

    protected function setUp(): void
    {
        FakeAi::reset();
        parent::setUp();
        $this->hub = new FakeHub();
        $this->container->set(HubClient::class, new HubClient($this->get(Config::class), $this->get(Secrets::class), $this->hub->client()));
        $this->hub->prices = ['updatedAt' => '2026-11-06', 'models' => [
            ['id' => 'fake-large', 'input' => 1, 'output' => 2, 'cacheRead' => 0.1, 'cacheWrite' => 1.25],
            ['id' => 'fake-new', 'input' => 3, 'output' => 4],
            ['id' => 'broken', 'input' => -1, 'output' => 2],
            ['id' => 'a/b', 'input' => 1, 'output' => 2],
        ]];
        $this->connect();
    }

    public function testAsksOnlyWhenTheAiWasUsedAndCountsWithTheList(): void
    {
        $list = $this->get(PriceList::class);

        self::assertFalse($list->due(), 'Without any use the website never asks.');
        $this->schedule();
        self::assertSame([], $this->hub->requests);

        $this->use();
        self::assertTrue($list->due());
        $this->schedule();

        self::assertSame(['provider' => 'acme/fake-ai'], $this->hub->last('POST', '/connect/v1/connections/con_0123456789/prices'), 'Only the provider, nothing about the use.');
        $stored = $list->stored();
        self::assertNotNull($stored);
        self::assertSame(['fake-large', 'fake-new'], array_keys($stored['models']), 'Broken entries are left out.');
        self::assertFalse($list->due(), 'A fresh list waits a day.');

        $status = $this->runner()->ask(new ShowAiStatus());
        self::assertSame(ModelPrices::HUB, $status->prices);
        self::assertNotNull($status->pricesSince);

        FakeAi::$usage = [new Usage('fake-large', 100, 20), new Usage('fake-small', 1000, 0), new Usage('fake-new', 10, 10), new Usage('unknown', 10, 0)];
        $this->get(Assistant::class)->ask(new Request('', [Message::user('Hi')]), 'test');
        $costs = $this->connection()->fetchFirstColumn('SELECT cost FROM ' . UsageLog::TABLE . " WHERE feature = 'test'");

        self::assertEqualsCanonicalizing([140, 100, 70, 10], array_map(static fn(mixed $cost): int => is_numeric($cost) ? (int) $cost : -1, $costs), 'Hub, module for a model the hub lacks, hub for a model the module lacks, and the chosen model for an unknown one.');

        $tomorrow = new DateTimeImmutable('+25 hours');
        self::assertTrue($list->due($tomorrow), 'Used since the list and the list is old.');
    }

    public function testAnAnswerWithoutTheSignatureOfTheHubIsNoList(): void
    {
        $this->hub->pricesSecret = StandardWebhooks::keyPair()['secret'];
        $this->use();
        $list = $this->get(PriceList::class);

        try {
            $list->refresh();
            self::fail('A forged answer was taken.');
        } catch (HubException $error) {
            self::assertStringContainsString('signature', $error->getMessage());
        }

        self::assertNull($list->stored());
        self::assertSame(ModelPrices::MODULE, $this->runner()->ask(new ShowAiStatus())->prices);
        self::assertFalse($list->due(), 'It waits an hour after a failure.');
        self::assertTrue($list->due(new DateTimeImmutable('+61 minutes')));

        // On schedule a failure is only logged.
        $this->hub->down = true;
        touch($this->get(ProjectPaths::class)->var('hub/ai-prices.attempt'), time() - 7200);
        $this->schedule();
        self::assertNull($list->stored());
    }

    public function testWithoutAConnectionTheWebsiteNeverAsks(): void
    {
        $this->connection()->executeStatement('DELETE FROM ' . HubConnections::TABLE);
        $this->use();

        self::assertFalse($this->get(PriceList::class)->due());
    }

    public function testOwnPricesFromTheConfigComeFirst(): void
    {
        $prices = $this->get(PriceList::class)->of('acme/fake-ai', new FakeAi(), 'fake-large');

        self::assertSame(ModelPrices::CONFIG, $prices->source);
        self::assertSame([0.5, 1.0, 0.5, 0.5], [$prices->prices->input, $prices->prices->output, $prices->prices->cacheRead, $prices->prices->cacheWrite], 'The cache costs the input without own prices.');
        self::assertSame(ModelPrices::MODULE, $this->get(PriceList::class)->of('acme/fake-ai', new FakeAi(), 'fake-small')->source);
    }

    protected function config(): array
    {
        return 'testOwnPricesFromTheConfigComeFirst' === $this->name() ? ['ai' => ['prices' => ['fake-large' => ['input' => 0.5, 'output' => 1]]]] : [];
    }

    protected function register(Registry $registry): void
    {
        $registry->forPackage('acme/fake-ai', static fn() => $registry->add(Provider::class, new Provider('acme/fake-ai', 'Fake', FakeAi::class)));
    }

    private function connect(): void
    {
        $pair = StandardWebhooks::keyPair();
        $this->hub->siteKey = $pair['public'];
        $this->connection()->insert(HubConnections::TABLE, [
            'hub_url' => FakeHub::URL,
            'public_key' => $pair['public'],
            'secret_key' => $this->get(HubClient::class)->encrypt($pair['secret']),
            'hub_keys' => json_encode([$this->hub->keys['public']], \JSON_THROW_ON_ERROR),
            'connection_id' => 'con_0123456789',
            'organization' => 'Herr und Frau Pixel',
            'status' => 1,
            'connected_at' => '2026-11-01 10:00:00',
            'created_at' => '2026-11-01 10:00:00',
        ]);
    }

    private function use(): void
    {
        $this->get(UsageLog::class)->record(new Context(Actor::user(1, 'Jane Doe'), Channel::Web), 'acme/fake-ai', 'field.rewrite', new Usage('fake-large', 10, 10), 240);
    }

    private function schedule(): void
    {
        $this->get(PricesOnSchedule::class)(new ScheduleRan(new DateTimeImmutable()));
        self::assertNotNull($this->get(Providers::class)->active());
    }
}
