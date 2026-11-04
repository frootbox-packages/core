<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent\Proof;

use DateTimeImmutable;
use Frootbox\Core\Consent\ConsentSettings;
use Frootbox\Database\Values;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Takes the proof of a choice the browser sends (decision 0264) and logs
 * it, only while the setting "proof" is on. Always answers 204.
 */
final readonly class ProofHandler implements RequestHandlerInterface
{
    public const string ROUTE = '/_consent/proof';

    private const int SIZE = 8000;

    public function __construct(
        private ProofLog $log,
        private ConsentSettings $settings,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $proof = $this->settings->proof && $this->accepts($request)
            ? Proof::fromBrowser(json_decode(substr((string) $request->getBody(), 0, self::SIZE + 1), true), new DateTimeImmutable()->format(Values::DATE_FORMAT))
            : null;

        if (null !== $proof) {
            $this->log->append($proof);
        }

        return $this->responses->createResponse(204)->withHeader('Cache-Control', 'no-store');
    }

    private function accepts(ServerRequestInterface $request): bool
    {
        if ($request->getBody()->getSize() > self::SIZE) {
            return false;
        }

        // Browsers send the origin with every POST; another website must not write here.
        $origin = $request->getHeaderLine('Origin');

        return '' === $origin || parse_url($origin, \PHP_URL_HOST) === $request->getUri()->getHost();
    }
}
