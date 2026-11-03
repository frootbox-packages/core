<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Field;

/**
 * A field type only objects have, under "object.fields" (decision 0240),
 * e.g. a switch: a block uses an option for it.
 */
interface ObjectOnly extends FieldType {}
