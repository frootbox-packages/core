<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Ai;

use Frootbox\Core\Ai\AiException;
use Frootbox\Core\Ai\Model;
use Frootbox\Core\Ai\Prices;
use Frootbox\Core\Ai\ProviderClient;
use Frootbox\Core\Ai\Request;
use Frootbox\Core\Ai\Response;
use Frootbox\Core\Ai\StopReason;
use Frootbox\Core\Ai\Text;
use Frootbox\Core\Ai\Usage;

/**
 * A provider that answers from here, for the tests of the AI interface.
 */
final class FakeAi implements ProviderClient
{
    public static bool $ready = true;

    /** @var list<Usage>|null The usage of the next answer, null for 100 in and 20 out on the chosen model */
    public static ?array $usage = null;

    public static ?string $failure = null;

    /** @var list<Request> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$ready = true;
        self::$usage = null;
        self::$failure = null;
        self::$requests = [];
    }

    public function ready(): bool
    {
        return self::$ready;
    }

    public function models(): array
    {
        return [
            new Model('fake-large', 'Fake Large', new Prices(4.0, 20.0, 0.2, 5.0)),
            new Model('fake-small', 'Fake Small', new Prices(0.1, 0.5)),
        ];
    }

    public function model(): Model
    {
        return $this->models()[0];
    }

    public function send(Request $request): Response
    {
        self::$requests[] = $request;

        if (null !== self::$failure) {
            throw new AiException(self::$failure);
        }

        $usage = self::$usage ?? [new Usage('fake-large', 100, 20)];

        $last = array_last($usage);

        return new Response([new Text('Hallo '), new Text('Welt.')], StopReason::Done, $usage, null === $last ? 'fake-large' : $last->model);
    }
}
