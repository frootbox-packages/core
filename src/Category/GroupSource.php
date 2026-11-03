<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Category;

/**
 * Where a group of categories comes from (decision 0233).
 */
enum GroupSource: string
{
    /** Registered by an extension, e.g. the categories of the news */
    case Module = 'module';

    /** Given by the agency in config/categories.yaml */
    case Config = 'config';

    /** Created by an admin in the backend */
    case Admin = 'admin';
}
