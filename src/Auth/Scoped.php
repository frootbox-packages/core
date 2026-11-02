<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth;

/**
 * An action or query that names what it works on, so roles given only
 * within a scope count for it (decision 0118). Others need a role that
 * applies everywhere.
 */
interface Scoped
{
    /**
     * The targets; each must lie within a scope of the actor. Null: only
     * roles everywhere count. An empty list: a role with the permission
     * anywhere is enough, the query shows only what lies within.
     *
     * @return list<Scope>|null
     */
    public function scopes(): ?array;
}
