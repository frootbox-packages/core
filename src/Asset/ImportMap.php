<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Asset;

use Frootbox\Core\Extension\Registry;
use Frootbox\RichText\Html;

/**
 * The import map from what extensions register with
 * $registry->import() (decision 0111), so modules can import shared
 * libraries by name:
 *
 *     <script type="importmap">{"imports": {"lit": "/assets/…/lit.js?v=…"}}</script>
 *
 * It has to come before the first module of a page.
 */
final readonly class ImportMap
{
    public function __construct(
        private Registry $registry,
        private PublicAssets $public,
    ) {}

    /**
     * @return array<string, string> name => URL
     */
    public function imports(): array
    {
        return array_map($this->public->versioned(...), $this->registry->imports());
    }

    /**
     * The <script type="importmap"> tag, empty without imports.
     */
    public function tag(): string
    {
        $imports = $this->imports();

        if ([] === $imports) {
            return '';
        }

        ksort($imports);
        $json = json_encode(['imports' => $imports], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG);

        return '<script type="importmap">' . $json . '</script>';
    }

    /**
     * A module script that loads a file of a package, e.g. the entry of
     * the backend.
     */
    public function module(string $file): string
    {
        return \sprintf('<script type="module" src="%s"></script>', Html::escape($this->public->versioned($file)));
    }
}
