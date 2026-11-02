<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

use Frootbox\Core\Language\Language;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\ProjectPaths;

/**
 * Keeps the table urls in line with the published pages (decision 0105).
 * Runs inside page.publish, page.delete and system.sync-urls, so every
 * change is recorded and can be undone.
 *
 * An address whose path changes becomes an earlier one and redirects to
 * the current one. A path taken again drops its earlier addresses.
 */
final readonly class AddressSync
{
    public function __construct(
        private Addresses $addresses,
        private PagePaths $paths,
        private Languages $languages,
        private ProjectPaths $project,
    ) {}

    /**
     * Brings the addresses of all pages up to date. Writes, so it runs
     * inside an action.
     *
     * @return int The number of addresses that changed
     */
    public function sync(): int
    {
        $changes = $this->changes();

        foreach ($changes as $change) {
            $change();
        }

        return \count($changes);
    }

    /**
     * How many addresses a sync would change, without writing.
     */
    public function pending(): int
    {
        return \count($this->changes());
    }

    /**
     * The writes that bring the addresses up to date.
     *
     * @return list<callable(): mixed>
     */
    private function changes(): array
    {
        $wanted = [];

        foreach ($this->paths->all() as $path) {
            $wanted[(string) Target::page($path->pageId) . '|' . $path->language] = $path;
        }

        $current = [];
        $earlier = [];

        foreach ($this->addresses->fetch(['where' => ['basePageId' => null], 'order' => 'id']) as $address) {
            if ($address->current) {
                $current[$address->target . '|' . $address->language] = $address;
            } else {
                $earlier[$address->language . '|' . $address->path][] = $address;
            }
        }

        $changes = [];

        // Moved or gone: the old address stays as an earlier one.
        foreach ($current as $key => $address) {
            $path = $wanted[$key] ?? null;

            if (null !== $path && $path->path === $address->path) {
                if ($path->isFallback() !== $address->fallback) {
                    $changes[] = fn() => $this->addresses->update($address->id, ['fallback' => $path->isFallback()]);
                }

                unset($wanted[$key]);

                continue;
            }

            $changes[] = fn() => $this->addresses->update($address->id, ['current' => false]);
            $earlier[$address->language . '|' . $address->path][] = $address;
        }

        foreach ($wanted as $path) {
            $target = (string) Target::page($path->pageId);
            $reused = null;

            foreach ($earlier[$path->language . '|' . $path->path] ?? [] as $old) {
                if (null === $reused && $old->target === $target) {
                    $reused = $old;
                } else {
                    $changes[] = fn() => $this->addresses->delete($old->id);
                }
            }

            unset($earlier[$path->language . '|' . $path->path]);

            $changes[] = null === $reused
                ? fn() => $this->addresses->insert(['target' => $target, 'language' => $path->language, 'basePageId' => null, 'path' => $path->path, 'current' => true, 'fallback' => $path->isFallback()])
                : fn() => $this->addresses->update($reused->id, ['current' => true, 'fallback' => $path->isFallback()]);
        }

        return $changes;
    }

    /**
     * Whether the language settings changed since the last sync, e.g. a
     * new language or another fallback. Prefixes and domains do not
     * count: paths are stored without them.
     */
    public function isStale(): bool
    {
        return !is_file($this->marker());
    }

    public function markFresh(): void
    {
        $marker = $this->marker();
        $directory = \dirname($marker);

        if (is_dir($directory) || @mkdir($directory, 0o775, true) || is_dir($directory)) {
            @touch($marker);
        }
    }

    private function marker(): string
    {
        $settings = array_map(fn(Language $language): array => $this->languages->contentLanguages($language->code), $this->languages->all());

        return $this->project->var('cache/urls/' . substr(hash('sha256', serialize($settings)), 0, 16));
    }
}
