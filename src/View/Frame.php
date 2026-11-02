<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Asset\AssetList;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\Heading\Headings;

/**
 * What field(), heading(), value() and area() need while one block or layout is rendered.
 *
 * @internal
 */
final readonly class Frame
{
    public const string VARIABLE = '_frootbox';

    public function __construct(
        public Component $component,
        public Instance $instance,
        public Scope $scope,
        public int $depth,
        public ?AssetList $assets = null,
        public Headings $headings = new Headings(),
    ) {}
}
