<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * Blocks are placed by editors, layouts frame a whole page. Both are
 * folders with views, assets and a schema.
 */
enum ComponentKind: string
{
    case Block = 'block';
    case Layout = 'layout';

    /** The folder in an extension, e.g. "blocks". */
    public function folder(): string
    {
        return $this->value . 's';
    }

    /** The shared schema of several variants, e.g. "block.yaml". */
    public function schemaFile(): string
    {
        return $this->value . '.yaml';
    }
}
