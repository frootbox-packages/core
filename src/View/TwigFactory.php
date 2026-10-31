<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Config\Environment;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Theme\ThemeChain;
use Twig\Environment as Twig;

/**
 * Twig for blocks and layouts. In development templates are checked on
 * every request and unknown variables are errors; in production they are
 * compiled to var/cache/twig.
 */
final class TwigFactory
{
    public static function create(Environment $environment, ProjectPaths $paths, ViewExtension $extension, ThemeChain $themes): Twig
    {
        $development = $environment->isDevelopment();

        $twig = new Twig(new FileLoader($themes), [
            'cache' => $development ? false : $paths->var('cache/twig'),
            'auto_reload' => $development,
            'strict_variables' => $development,
            'autoescape' => 'html',
        ]);
        $twig->addExtension($extension);

        return $twig;
    }
}
