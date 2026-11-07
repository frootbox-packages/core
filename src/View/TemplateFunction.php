<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use InvalidArgumentException;

/**
 * A function of an extension for the templates of blocks and layouts,
 * e.g. for the cart in the head of every page (decision 0313):
 *
 *     $registry->add(TemplateFunction::class, new TemplateFunction('shop_cart', ShopTemplates::class, 'cart'));
 *
 *     {% set cart = shop_cart() %}
 *
 * The service is loaded on the first call. The name starts with a prefix
 * of the extension and an underscore and cannot replace a function of
 * the core. With $html the result is printed unescaped, so the method
 * escapes it itself.
 */
final readonly class TemplateFunction
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $method = '__invoke',
        public bool $html = false,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9]*_[a-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException(\sprintf('The template function "%s" needs a prefix and an underscore, e.g. "acme_cart".', $name));
        }
    }
}
