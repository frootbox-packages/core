<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Simulates installed Composer packages in a TemporaryProject:
 * package folders plus vendor/composer/installed.json.
 */
final class FakeComposer
{
    /** @var list<array<string, mixed>> */
    private array $packages = [];

    public function __construct(private readonly TemporaryProject $project) {}

    /**
     * @param array<string, mixed>|null    $manifest  Contents of frootbox.yaml, null for a package without one
     * @param list<string>                 $requires
     * @param array<string, string>        $psr4
     * @param string|null                  $directory Relative to the project, default vendor/<name>
     * @param array<string, string>|null    $dist      Where Composer took it from, e.g. ['type' => 'path', 'url' => 'extensions/site']
     */
    public function add(string $name, ?array $manifest, array $requires = [], array $psr4 = [], ?string $directory = null, ?array $dist = null): string
    {
        $path = $this->project->root . '/' . ($directory ?? 'vendor/' . $name);

        if (!is_dir($path)) {
            mkdir($path, 0o777, true);
        }

        if (null !== $manifest) {
            file_put_contents($path . '/frootbox.yaml', Yaml::dump($manifest));
        }

        $this->packages[] = [
            'name' => $name,
            'type' => 'library',
            'install-path' => $path,
            'require' => array_fill_keys($requires, '*'),
            'autoload' => ['psr-4' => $psr4],
            ...(null === $dist ? [] : ['dist' => $dist]),
        ];
        $this->write();

        return $path;
    }

    private function write(): void
    {
        $directory = $this->project->root . '/vendor/composer';

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        file_put_contents($directory . '/installed.json', json_encode(['packages' => $this->packages], \JSON_THROW_ON_ERROR));
    }
}
