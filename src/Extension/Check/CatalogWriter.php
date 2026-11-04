<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Check;

use DateTimeImmutable;
use DateTimeInterface;
use Frootbox\Core\Extension\ExtensionException;

/**
 * Writes the catalog for the directory (decision 0282): catalog.json
 * with one entry per package that passes extension:check, and its icon,
 * screenshots and previews below files/<vendor>/<package>/. The catalog
 * API of the service later answers in the same format.
 */
final readonly class CatalogWriter
{
    public const int VERSION = 1;

    public const string FILE = 'catalog.json';

    public function __construct(private ExtensionChecker $checker) {}

    /**
     * @param list<string> $folders package folders
     * @param string|null  $version for all packages, e.g. the shared version of a release
     *
     * @return array{written: list<string>, skipped: array<string, list<CheckFinding>>}
     */
    public function write(array $folders, string $out, ?string $version = null): array
    {
        if (!is_dir($out) && !mkdir($out, 0o777, true) && !is_dir($out)) {
            throw new ExtensionException(\sprintf('Could not create %s.', $out));
        }

        $entries = [];
        $skipped = [];

        foreach ($folders as $folder) {
            $report = $this->checker->folder($folder);

            if ([] !== $report->errors) {
                $skipped[$report->name] = $report->errors;

                continue;
            }

            $entries[$report->name] = $this->entry($report, (string) realpath($folder), $out, $version ?? $report->version);
        }

        ksort($entries);
        $catalog = [
            'version' => self::VERSION,
            'generated' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            'packages' => array_values($entries),
        ];
        file_put_contents($out . '/' . self::FILE, json_encode($catalog, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) . "\n");

        return ['written' => array_keys($entries), 'skipped' => $skipped];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(CheckReport $report, string $folder, string $out, ?string $version): array
    {
        $listing = $report->listing;
        $base = 'files/' . $report->name;
        self::clear($out . '/' . $base);

        foreach ($listing->files() as $file) {
            $target = $out . '/' . $base . '/' . $file;

            if (!is_dir(\dirname($target))) {
                mkdir(\dirname($target), 0o777, true);
            }

            copy($folder . '/' . $file, $target);
        }

        $path = static fn(string $file): string => $base . '/' . $file;

        return [
            'name' => $report->name,
            'version' => $version,
            'license' => $report->license,
            'frootbox' => $report->frootbox,
            'command' => 'composer require ' . $report->name,
            'theme' => $listing->theme,
            'title' => $listing->title,
            'description' => $listing->description,
            'readme' => $listing->readme,
            'keywords' => $listing->keywords,
            'homepage' => $listing->homepage,
            'support' => (object) $listing->support,
            'authors' => $listing->authors,
            'icon' => null === $listing->icon ? null : $path($listing->icon),
            'screenshots' => array_map(static fn(array $shot): array => ['file' => $path($shot['file']), 'caption' => $shot['caption']], $listing->screenshots),
            'previews' => array_map(static fn(array $preview): array => [...$preview, 'file' => $path($preview['file'])], $listing->previews),
            'contents' => $report->contents->toArray(),
            'dependencies' => $report->dependencies,
        ];
    }

    private static function clear(string $folder): void
    {
        if (!is_dir($folder)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($folder);
    }
}
