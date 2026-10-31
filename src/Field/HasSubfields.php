<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

/**
 * A field type whose entries have fields of their own, under the setting
 * "fields", like a list.
 */
interface HasSubfields extends FieldType {}
