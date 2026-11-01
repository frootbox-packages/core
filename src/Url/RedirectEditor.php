<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Support\Message;
use Frootbox\Database\EntityNotFoundException;

/**
 * Checks and saves manual redirects (decision 0105). No chains and no
 * loops: a redirect may not lead to a path that is redirected itself,
 * and redirects that led to the new source take over its destination.
 */
final readonly class RedirectEditor
{
    private const int MAX_LOCATION_LENGTH = 2000;

    public function __construct(
        private Redirects $redirects,
        private Addresses $addresses,
        private Languages $languages,
        private PageReader $reader,
        private NotFoundLog $notFound,
    ) {}

    /**
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function save(string $action, ?int $id, string $path, ?string $target, ?string $location, bool $permanent): Redirect
    {
        // Readable or percent-encoded, stored as text in NFC like request paths (decision 0158).
        $path = UrlPath::decode($path) ?? $path;
        $existing = null === $id ? null : $this->redirects->getById($id);
        $errors = [...$this->checkPath($path, $existing), ...$this->checkDestination($path, $target, $location)];

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $values = ['path' => $path, 'target' => $target, 'location' => $location, 'permanent' => $permanent];
        $saved = null === $existing ? $this->redirects->insert($values) : $this->redirects->update($existing->id, $values);

        // Redirects that led here go straight to the destination.
        foreach ($this->redirects->fetch(['where' => ['location' => $path]]) as $before) {
            $this->redirects->update($before->id, ['target' => $target, 'location' => $location]);
        }

        $this->notFound->forget($path);

        return $saved;
    }

    /**
     * @return array<string, string|Message>
     */
    private function checkPath(string $path, ?Redirect $existing): array
    {
        if (!self::isLocalPath($path) || str_contains($path, '?') || '/' === $path || str_ends_with($path, '/') || \strlen($path) > NotFoundLog::MAX_PATH_LENGTH) {
            return ['/path' => new Message('must be a path like "/old-page", without query, without slash at the end, at most {max} characters.', ['max' => NotFoundLog::MAX_PATH_LENGTH])];
        }

        $other = $this->redirects->at($path);

        if (null !== $other && $other->id !== $existing?->id) {
            return ['/path' => new Message('already has a redirect (#{id}).', ['id' => $other->id])];
        }

        foreach ($this->languages->all() as $language) {
            $rest = self::pagePath($language->prefix, $path);
            $address = null === $rest ? null : $this->addresses->at($language->code, $rest);

            if (null !== $address) {
                return ['/path' => new Message('is the address of {target}. A page always wins over a redirect.', ['target' => $address->target])];
            }
        }

        return [];
    }

    /**
     * @return array<string, string|Message>
     */
    private function checkDestination(string $path, ?string $target, ?string $location): array
    {
        if ((null === $target) === (null === $location)) {
            return ['/' => 'needs either "target" (e.g. "page:01J…") or "location" (a URL).'];
        }

        if (null !== $target) {
            $parsed = Target::tryParse($target);

            if (null === $parsed || !$parsed->isPage()) {
                return ['/target' => 'must be a page, e.g. "page:01J…".'];
            }

            return isset($this->reader->nodes()[$parsed->id]) ? [] : ['/target' => new Message('There is no page {id}.', ['id' => $parsed->id])];
        }

        $location = (string) $location;

        if (\strlen($location) > self::MAX_LOCATION_LENGTH || (!self::isLocalPath($location) && 1 !== preg_match('#^https?://[^\s/?\#]+[^\s]*$#i', $location))) {
            return ['/location' => new Message('must be a path like "/new-page" or a URL with http or https, at most {max} characters.', ['max' => self::MAX_LOCATION_LENGTH])];
        }

        if (!self::isLocalPath($location)) {
            return [];
        }

        $localPath = explode('?', $location, 2)[0];

        if ($localPath === $path) {
            return ['/location' => 'must not be the path itself.'];
        }

        $next = $this->redirects->at($localPath);

        if (null !== $next) {
            return ['/location' => new Message('is redirected itself (#{id}). Point to its destination {destination} directly.', ['id' => $next->id, 'destination' => $next->location ?? $next->target])];
        }

        return [];
    }

    /**
     * The page path of a request path in a language with this prefix, or
     * null if the path is not in it.
     */
    private static function pagePath(string $prefix, string $path): ?string
    {
        $path = trim($path, '/');

        if ('' === $prefix) {
            return $path;
        }

        if ($path === $prefix) {
            return '';
        }

        return str_starts_with($path, $prefix . '/') ? substr($path, \strlen($prefix) + 1) : null;
    }

    private static function isLocalPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && 1 !== preg_match('/[\s#]/', $path);
    }
}
