<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Ai;

use DateTimeImmutable;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Ai\AiSettings;
use Frootbox\Core\Ai\AiStatus;
use Frootbox\Core\Ai\Assistant;
use Frootbox\Core\Ai\Effort;
use Frootbox\Core\Ai\Prices;
use Frootbox\Core\Ai\Provider;
use Frootbox\Core\Ai\Providers;
use Frootbox\Core\Ai\ShowAiStatus;
use Frootbox\Core\Ai\ShowAiUsage;
use Frootbox\Core\Ai\TestAi;
use Frootbox\Core\Ai\Text;
use Frootbox\Core\Ai\Tools;
use Frootbox\Core\Ai\Usage;
use Frootbox\Core\Ai\UsageLog;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Settings\UpdateSettings;
use Frootbox\Core\Tests\Fixtures\Ai\FakeAi;
use Frootbox\Core\Tests\Fixtures\Ai\SummarizeNote;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The AI interface of the core with a provider that answers from here
 * (decision 0294).
 */
#[CoversClass(Assistant::class)]
#[CoversClass(Providers::class)]
#[CoversClass(UsageLog::class)]
#[CoversClass(Prices::class)]
#[CoversClass(ShowAiStatus::class)]
#[CoversClass(ShowAiUsage::class)]
#[CoversClass(TestAi::class)]
#[CoversClass(Tools::class)]
#[CoversClass(AiSettings::class)]
final class AiTest extends NotesTestCase
{
    protected function setUp(): void
    {
        FakeAi::reset();
        parent::setUp();
    }

    public function testTheTestNotesWhatItCost(): void
    {
        $this->signIn('admin');

        $result = $this->runner()->run(new TestAi());

        self::assertSame(['Fake', 'fake-large', 'Hallo Welt.', 100, 20], [$result->provider, $result->model, $result->text, $result->inputTokens, $result->outputTokens]);
        // 100 × 4 $ + 20 × 20 $ per million tokens.
        self::assertSame(0.0008, $result->cost);
        self::assertSame(Effort::Low, FakeAi::$requests[0]->effort);
        self::assertFalse(FakeAi::$requests[0]->cache);

        $row = $this->connection()->fetchAssociative('SELECT * FROM ai_usage');
        self::assertIsArray($row);
        self::assertSame(['test', 'web', 'Jane', 'fake-large'], [$row['feature'], $row['channel'], $row['actor'], $row['model']]);
        self::assertEquals(800, $row['cost']);
    }

    public function testCostsStandWhenTheFeatureFailsAfterTheAnswer(): void
    {
        $this->signIn('admin');

        try {
            $this->runner()->run(new SummarizeNote('Eine lange Notiz', fail: true));
            self::fail('The feature should fail.');
        } catch (InvalidInputException) {
        }

        self::assertSame(1, $this->number('SELECT COUNT(*) FROM ai_usage'), 'The provider was paid, the row stays.');
        $part = FakeAi::$requests[0]->messages[0]->parts[0];
        self::assertInstanceOf(Text::class, $part);
        self::assertSame('Eine lange Notiz', $part->text);
    }

    public function testAFallbackCountsEachModelWithItsPrices(): void
    {
        FakeAi::$usage = [new Usage('fake-small', 1000, 100), new Usage('unknown-model', 10, 10)];
        $this->signIn('admin');

        $this->runner()->run(new SummarizeNote('Text'));

        $costs = $this->connection()->fetchFirstColumn('SELECT cost FROM ai_usage ORDER BY model');
        // 1000 × 0.1 + 100 × 0.5, and the unknown model with the prices of the chosen one: 10 × 4 + 10 × 20.
        self::assertEquals([150, 240], $costs);
    }

    public function testTheLimitStopsTheAiUntilTheNextMonth(): void
    {
        $this->signIn('admin');
        $this->runner()->run(new UpdateSettings('frootbox/core.ai', ['monthlyLimit' => 0.001]));
        $this->runner()->run(new SummarizeNote('Erste'));
        $this->runner()->run(new SummarizeNote('Zweite'));

        self::assertSame(['limit', false], [$this->aiStatus()->reason, $this->aiStatus()->available]);
        $report = $this->runner()->ask(new ShowAiUsage());
        self::assertSame([0.001, 0.0016, 0.0], [$report->limit, $report->spent, $report->remaining], 'The last request may pass the limit by its own costs.');

        try {
            $this->runner()->run(new SummarizeNote('Dritte'));
            self::fail('The limit is reached.');
        } catch (ConflictException $error) {
            self::assertStringContainsString('monthly limit', $error->getMessage());
        }

        self::assertCount(2, FakeAi::$requests, 'Nothing reached the provider.');
        $log = $this->get(UsageLog::class);
        self::assertSame(0, $log->spent(new DateTimeImmutable('+1 month')));
    }

    public function testTheReportSumsUpByDayFeaturePersonAndModel(): void
    {
        $log = $this->get(UsageLog::class);
        $context = new Context(Actor::user(7, 'Jane'), Channel::Web);
        $log->record($context, 'acme/ai', 'field.rewrite', new Usage('fake-large', 10, 5, 2, 3), 2_500_000, new DateTimeImmutable('2026-03-01 00:00'));
        $log->record($context, 'acme/ai', 'field.rewrite', new Usage('fake-large', 1, 1), 500_000, new DateTimeImmutable('2026-03-31 23:59'));
        $log->record(new Context(Actor::user(8, 'Max'), Channel::Ai), 'acme/ai', 'test', new Usage('fake-small', 1, 1), 1_000_000, new DateTimeImmutable('2026-03-31 12:00'));
        $log->record($context, 'acme/ai', 'test', new Usage('fake-small', 1, 1), 9_000_000, new DateTimeImmutable('2026-04-01 00:00'));

        $report = $this->runner()->ask(new ShowAiUsage('2026-03'));

        self::assertSame(['2026-03', 4.0, 3, null, null], [$report->month, $report->spent, $report->calls, $report->limit, $report->remaining]);
        self::assertSame([['date' => '2026-03-01', 'calls' => 1, 'cost' => 2.5], ['date' => '2026-03-31', 'calls' => 2, 'cost' => 1.5]], $report->days);
        self::assertSame([['feature' => 'field.rewrite', 'calls' => 2, 'cost' => 3.0], ['feature' => 'test', 'calls' => 1, 'cost' => 1.0]], $report->features);
        self::assertSame(['Jane', 'Max'], array_column($report->people, 'name'));
        self::assertSame(['model' => 'fake-large', 'calls' => 2, 'inputTokens' => 16, 'outputTokens' => 6, 'cost' => 3.0], $report->models[0]);
        self::assertSame(['Fake', 'Fake Large'], [$report->provider, $report->model]);
    }

    public function testTheStatusTellsWhyTheAiIsNotThere(): void
    {
        $this->signIn('admin');
        self::assertSame([true, null, 'Fake', 'Fake Large'], [$this->aiStatus()->available, $this->aiStatus()->reason, $this->aiStatus()->provider, $this->aiStatus()->model]);

        FakeAi::$ready = false;
        self::assertSame('setup', $this->aiStatus()->reason);

        try {
            $this->runner()->run(new TestAi());
            self::fail('The provider is not set up.');
        } catch (ConflictException $error) {
            self::assertStringContainsString('not set up', $error->getMessage());
        }

        FakeAi::$ready = true;
        $this->signIn('editor');
        self::assertSame(AiStatus::NO_PERMISSION, $this->aiStatus()->reason, 'Editors get ai.use from an admin.');
    }

    public function testAnErrorOfTheProviderShowsAtTheTest(): void
    {
        FakeAi::$failure = 'The API key is wrong.';
        $this->signIn('admin');

        try {
            $this->runner()->run(new TestAi());
            self::fail('The provider failed.');
        } catch (InvalidInputException $error) {
            self::assertSame(['' => 'The API key is wrong.'], $error->errors);
        }

        self::assertSame(0, $this->number('SELECT COUNT(*) FROM ai_usage'));
    }

    public function testTheToolsLeaveOutTheAiItself(): void
    {
        $names = array_map(static fn($tool): string => $tool->name, $this->get(Tools::class)->of(Actor::system()));

        self::assertContains('page_create', $names);
        self::assertNotContains('ai_test', $names);
        self::assertNotContains('ai_status', $names);
        self::assertContains('acme__notes__note_summarize', $names, 'Only the core area ai.* is left out.');
    }

    protected function register(Registry $registry): void
    {
        $registry->forPackage('acme/fake-ai', static fn() => $registry->add(Provider::class, new Provider('acme/fake-ai', 'Fake', FakeAi::class)));
        $registry->forPackage('acme/notes', static fn() => $registry->action(SummarizeNote::class));
    }

    private function number(string $sql): int
    {
        $value = $this->connection()->fetchOne($sql);

        return is_numeric($value) ? (int) $value : -1;
    }

    private function aiStatus(): AiStatus
    {
        return $this->runner()->ask(new ShowAiStatus());
    }

    private function signIn(string $role): void
    {
        $this->get(CurrentContext::class)->set(Context::system(Channel::Console));
        $email = $role . '@example.org';
        $user = $this->runner()->run(new CreateUser($email, 'Jane', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($user->id, $role));
        $this->get(CurrentContext::class)->set(new Context(Actor::user($user->id, $user->name), Channel::Web));
    }
}
