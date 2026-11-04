<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Consent;

use DateTimeImmutable;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Consent\ConsentException;
use Frootbox\Core\Consent\ConsentMarkup;
use Frootbox\Core\Consent\ConsentSettings;
use Frootbox\Core\Consent\ConsentTool;
use Frootbox\Core\Consent\ConsentTools;
use Frootbox\Core\Consent\ExternalServices;
use Frootbox\Core\Consent\ListServices;
use Frootbox\Core\Consent\Proof\ListProofs;
use Frootbox\Core\Consent\Proof\Proof;
use Frootbox\Core\Consent\Proof\ProofHandler;
use Frootbox\Core\Consent\Proof\ProofLog;
use Frootbox\Core\Consent\Proof\Proofs;
use Frootbox\Core\Consent\Purpose;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Settings\UpdateSettings;
use Frootbox\Core\Tests\Fixtures\Consent\AcmeTool;
use Frootbox\Core\Tests\Fixtures\Consent\ConsentExtension;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\View\Document;
use Frootbox\Core\View\FileLoader;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Environment as Twig;

/**
 * The banner, the privacy settings and consent tools (decision 0264).
 */
#[CoversClass(ConsentMarkup::class)]
#[CoversClass(ConsentTools::class)]
#[CoversClass(ListServices::class)]
#[CoversClass(ListProofs::class)]
#[CoversClass(Proof::class)]
#[CoversClass(ProofHandler::class)]
#[CoversClass(ProofLog::class)]
#[CoversClass(Proofs::class)]
#[CoversClass(Purpose::class)]
#[CoversClass(Document::class)]
#[CoversClass(FileLoader::class)]
final class ConsentTest extends NotesTestCase
{
    private string $theme = 'acme/site';

    protected function setUp(): void
    {
        AcmeTool::$site = 'site-1';
        parent::setUp();
    }

    public function testAScriptOnEveryPageWaitsForTheBanner(): void
    {
        $html = $this->page([['block' => 'acme/external/Map', 'fields' => ['place' => 'Münster']]]);

        self::assertStringContainsString('<template data-fb-consent="acme/consent/stats"><script>window.acmeStats = (window.acmeStats ?? 0) + 1;</script></template>', $html, 'The script stays inactive in the head.');
        self::assertSame(1, substr_count($html, '<script>window.acmeStats'));
        self::assertStringContainsString("<body>\n<fb-consent-banner class=\"consent-banner\" data-consent-version=\"", $html, 'The banner comes first in the body, so the keyboard reaches it first.');
        self::assertStringContainsString('<li data-consent-service="acme/consent/stats" hidden><span class="consent-banner__service">Acme Stats</span> <span class="consent-banner__provider">(Acme Analytics Ltd, Statistics)</span>', $html);
        self::assertStringContainsString('<button type="button" class="consent-banner__button" data-consent-reject>Reject all</button><button type="button" class="consent-banner__button" data-consent-settings>Settings</button><button type="button" class="consent-banner__button" data-consent-accept>Accept all</button>', $html, 'Three choices of equal weight.');
        self::assertStringContainsString('<fb-privacy-settings class="privacy-settings privacy-settings--floating"', $html, 'The theme has no button, so the core adds one to take consent back.');
        self::assertStringContainsString('<fieldset class="privacy-settings__group" data-consent-group="statistics"><legend class="privacy-settings__purpose">Statistics</legend>', $html, 'The settings group the services by purpose.');
        self::assertSame(1, substr_count($html, '/assets/frootbox/core/assets/external.js'));
    }

    public function testTheButtonOfTheThemeIsEnough(): void
    {
        $html = $this->page([['block' => 'acme/external/Privacy']]);

        self::assertStringContainsString('<fb-consent-banner', $html);
        self::assertStringNotContainsString('privacy-settings--floating', $html);
        self::assertSame(1, substr_count($html, '<fb-privacy-settings'));
    }

    public function testAThemeDesignsTheBanner(): void
    {
        $this->theme = 'acme/designed';
        $this->setUp();

        $html = $this->page([]);

        self::assertStringContainsString('<fb-consent-banner class="designed-banner" data-consent-version="', $html);
        self::assertStringContainsString('<p data-consent-service="acme/consent/stats">Acme Stats</p><button type="button" data-consent-reject>Reject all</button>', $html);
        self::assertStringContainsString('<fb-external class="external"', $this->get(ConsentMarkup::class)->external($this->get(ExternalServices::class)->get('youtube') ?? self::fail(), '<iframe></iframe>'), 'What the theme leaves comes from the core.');
    }

    public function testAConsentToolTakesOver(): void
    {
        $this->get(Registry::class)->add(ConsentTool::class, new ConsentTool('acme/consent/tool', 'Acme Consent', AcmeTool::class));
        $html = $this->page([['block' => 'acme/external/Map', 'fields' => ['place' => 'Münster']]]);

        self::assertStringContainsString("<body>\n<fb-consent-banner class=\"consent-banner\" tool=\"acme/consent/tool\" data-consent-version=\"", $html);
        self::assertStringNotContainsString('data-consent-accept', $html, 'The banner of the core stays away.');
        self::assertStringContainsString('<script src="https://consent.example/site-1.js"></script>', $html, 'Its script loads before any consent.');
        self::assertStringContainsString('<template data-fb-consent="acme/consent/stats">', $html, 'The scripts still wait, now for the tool.');
        self::assertSame('acme/consent/tool', $this->runner()->ask(new ListServices())->tool);
    }

    public function testOnlyAReadyOrChosenToolTakesOver(): void
    {
        $registry = $this->get(Registry::class);
        $registry->add(ConsentTool::class, new ConsentTool('acme/consent/tool', 'Acme Consent', AcmeTool::class));
        $registry->add(ConsentTool::class, new ConsentTool('acme/consent/other', 'Other Consent', AcmeTool::class));
        $tools = fn(): ConsentTools => new ConsentTools($registry, $this->container, $this->get(ConsentSettings::class));

        self::assertNull($tools()->active(), 'Of two ready ones, none without a choice.');

        $this->runner()->run(new UpdateSettings('frootbox/core', ['tool' => 'acme/consent/other']));
        self::assertSame('acme/consent/other', new ConsentTools($registry, $this->container, new ConsentSettings(tool: 'acme/consent/other'))->active()?->name);
        self::assertNull(new ConsentTools($registry, $this->container, new ConsentSettings(tool: ConsentTools::NONE))->active());

        AcmeTool::$site = '';
        self::assertNull(new ConsentTools($registry, $this->container, new ConsentSettings(tool: 'acme/consent/other'))->active(), 'Not set up, it does not take over.');
    }

    public function testListsTheServices(): void
    {
        $list = $this->runner()->ask(new ListServices());
        $services = array_column($list->services, null, 'name');

        self::assertNull($list->tool);
        self::assertSame(['name' => 'acme/consent/stats', 'title' => 'Acme Stats', 'provider' => 'Acme Analytics Ltd', 'privacyUrl' => 'https://stats.example/privacy', 'purpose' => 'statistics', 'reach' => 'site'], $services[ConsentExtension::SERVICE]);
        self::assertSame(['media', 'content'], [$services['youtube']['purpose'], $services['youtube']['reach']]);
    }

    public function testAScriptNeedsAServiceOnEveryPage(): void
    {
        $consent = $this->get(ConsentMarkup::class);

        foreach (['acme/consent/unknown' => 'is not registered', 'youtube' => 'loads at its content'] as $service => $message) {
            try {
                $consent->deferred($service, '<script></script>');
                self::fail($service . ' was accepted.');
            } catch (ConsentException $error) {
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
    }

    public function testWithoutServicesThereIsNothing(): void
    {
        $registry = new Registry();
        $settings = new ConsentSettings();
        $consent = new ConsentMarkup(new ExternalServices($registry), new ConsentTools($registry, $this->container, $settings), $settings, $this->get(Twig::class));

        self::assertSame('', $consent->settings());
        self::assertSame('', $consent->page('<main><fb-external></fb-external></main>'));
    }

    public function testTheVersionFollowsTheServices(): void
    {
        $version = $this->get(ConsentMarkup::class)->version();

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $version);
        self::assertSame($version, $this->get(ConsentMarkup::class)->version());

        $registry = new Registry();
        $settings = new ConsentSettings();
        self::assertNotSame($version, new ConsentMarkup(new ExternalServices($registry), new ConsentTools($registry, $this->container, $settings), $settings, $this->get(Twig::class))->version());
    }

    public function testKeepsAProofOnlyWhenAsked(): void
    {
        $id = '0f8c3a52-6b1e-4c4d-9a3f-2d6e8b7c1a90';
        $body = (string) json_encode(['id' => $id, 'version' => 'a2fe0f35172d', 'services' => ['acme/consent/stats' => true, 'youtube' => false]]);
        $send = fn(string $origin = 'https://example.org'): int => $this->get(ProofHandler::class)->handle(new ServerRequest('POST', 'https://example.org' . ProofHandler::ROUTE, ['Origin' => $origin], $body))->getStatusCode();

        self::assertSame(204, $send());
        $this->get(Proofs::class)->run();
        self::assertSame([], $this->runner()->ask(new ListProofs($id))->proofs, 'Off by standard, nothing is kept.');
        self::assertStringNotContainsString('data-consent-proof', $this->page([]));
    }

    public function testAProofKeepsTheChoiceWithoutTheVisitor(): void
    {
        $this->runner()->run(new UpdateSettings('frootbox/core', ['proof' => true]));
        $id = '0f8c3a52-6b1e-4c4d-9a3f-2d6e8b7c1a90';
        $send = fn(mixed $data, string $origin = 'https://example.org'): int => $this->get(ProofHandler::class)->handle(new ServerRequest('POST', 'https://example.org' . ProofHandler::ROUTE, ['Origin' => $origin, 'User-Agent' => 'Mozilla/5.0'], (string) json_encode($data)))->getStatusCode();

        $send(['id' => $id, 'version' => 'a2fe0f35172d', 'services' => ['acme/consent/stats' => false]]);
        $send(['id' => strtoupper($id), 'version' => 'a2fe0f35172d', 'services' => ['youtube' => true, 'acme/consent/stats' => true]]);
        $send(['id' => $id, 'version' => 'a2fe0f35172d', 'services' => ['acme/consent/stats' => true]], 'https://other.example');
        $send(['id' => 'not-an-id', 'version' => 'a2fe0f35172d', 'services' => []]);
        $send(['id' => $id, 'version' => 'a2fe0f35172d', 'services' => ['acme/consent/stats' => 'yes']]);

        self::assertCount(0, $this->connection()->fetchAllAssociative('SELECT * FROM ' . Proofs::TABLE), 'Until the scheduler runs, the proofs wait in var/.');

        $proofs = $this->runner()->ask(new ListProofs(' ' . strtoupper($id) . ' '))->proofs;

        self::assertEqualsCanonicalizing([['acme/consent/stats' => true, 'youtube' => true], ['acme/consent/stats' => false]], array_column($proofs, 'choices'), 'Made-up and foreign ones left out.');
        $row = $this->connection()->fetchAssociative('SELECT * FROM ' . Proofs::TABLE);
        self::assertIsArray($row);
        self::assertSame(['id', 'consent_id', 'version', 'choices', 'created_at'], array_keys($row), 'No address, no browser.');
        self::assertStringContainsString('data-consent-proof', $this->page([]));

        $this->get(Proofs::class)->run(new DateTimeImmutable('+1096 days'));
        self::assertSame([], $this->runner()->ask(new ListProofs($id))->proofs, 'Gone after three years.');
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return new Extensions([...FixtureSite::extensions('base', 'site', 'designed', 'external', 'consent')->packages], 'fixtures');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => $this->theme, 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function page(array $blocks): string
    {
        $id = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Start'], 'areas' => ['main' => $blocks]]]]))->id;
        $this->runner()->run(new PublishPages([$id]));

        return (string) $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org/'))->getBody();
    }
}
