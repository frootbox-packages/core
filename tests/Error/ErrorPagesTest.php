<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Error;

use DI\Container;
use Frootbox\Core\Error\ErrorPage;
use Frootbox\Core\Error\ErrorPages;
use Frootbox\Core\Tests\Fixtures\Error\FailingErrorPage;
use Frootbox\Core\Tests\Fixtures\Error\SilentErrorPage;
use Frootbox\Core\Tests\Fixtures\Error\StatusErrorPage;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ErrorPages::class)]
final class ErrorPagesTest extends TestCase
{
    private TestHandler $log;

    public function testTheFirstResponsiblePageWins(): void
    {
        $pages = $this->pages([SilentErrorPage::class, StatusErrorPage::class]);

        self::assertSame('Status 404: Gone', $pages->render(new ServerRequest('GET', '/'), 404, new RuntimeException('Gone'), true));
        self::assertSame('Status 404', $pages->render(new ServerRequest('GET', '/'), 404, new RuntimeException('Gone'), false));
    }

    public function testSkipsAndLogsAPageThatFails(): void
    {
        $pages = $this->pages([FailingErrorPage::class, StatusErrorPage::class]);

        self::assertSame('Status 500', $pages->render(new ServerRequest('GET', '/'), 500, new RuntimeException('Boom'), false));
        self::assertCount(1, $this->log->getRecords());
        self::assertSame('The page is broken.', $this->log->getRecords()[0]->context['message']);
    }

    public function testNullWithoutAResponsiblePage(): void
    {
        self::assertNull($this->pages([SilentErrorPage::class, FailingErrorPage::class])->render(new ServerRequest('GET', '/'), 404, new RuntimeException('Gone'), false));
        self::assertNull($this->pages([])->render(new ServerRequest('GET', '/'), 404, new RuntimeException('Gone'), false));
    }

    /**
     * @param list<class-string<ErrorPage>> $classes
     */
    private function pages(array $classes): ErrorPages
    {
        $this->log = new TestHandler();

        return new ErrorPages($classes, new Container(), new Logger('test', [$this->log]));
    }
}
