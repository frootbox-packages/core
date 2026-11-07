<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\I18n;

/**
 * How texts for visitors address them, e.g. German "Du" or "Sie"
 * (decision 0316). Formal texts lie in translations/<language>.formal.yaml.
 */
enum Formality: string
{
    case Informal = 'informal';
    case Formal = 'formal';
}
