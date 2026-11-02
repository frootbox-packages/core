<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Site;

use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Application;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Site\ShowSite;
use Frootbox\Core\Site\SiteProtection;
use Frootbox\Core\Site\UnprotectSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * SITE_PROTECT=true in .env keeps a copy of the live database out of the
 * index (decision 0154).
 */
#[CoversClass(SiteProtection::class)]
#[CoversClass(UnprotectSite::class)]
final class ForcedSiteProtectionTest extends NotesTestCase
{
    public function testTheEnvironmentForcesTheProtection(): void
    {
        $status = $this->runner()->ask(new ShowSite())->protection;
        self::assertNotNull($status);
        self::assertTrue($status->on);
        self::assertTrue($status->forced);
        self::assertFalse($status->password);

        $middleware = array_map($this->get(...), Application::MIDDLEWARE);
        $response = new MiddlewareDispatcher($middleware, $this->get(PageHandler::class))->handle(new ServerRequest('GET', 'https://example.org/'));
        self::assertSame(401, $response->getStatusCode());
        self::assertStringNotContainsString('fb-site-password', (string) $response->getBody(), 'Without a password only users and preview links get in.');

        $this->expectException(ConflictException::class);
        $this->runner()->run(new UnprotectSite());
    }

    protected function env(): array
    {
        return [SiteProtection::ENVIRONMENT => 'true'];
    }
}
