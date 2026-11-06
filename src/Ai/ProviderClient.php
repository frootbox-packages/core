<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * What an AI provider does (decision 0294). Only the Assistant calls it,
 * features go through the Assistant.
 */
interface ProviderClient
{
    /**
     * Whether it can answer, e.g. has its API key.
     */
    public function ready(): bool;

    /**
     * The models it offers, with their prices.
     *
     * @return list<Model>
     */
    public function models(): array;

    /**
     * The model chosen in its settings.
     */
    public function model(): Model;

    /**
     * Sends the request to the chosen model.
     *
     * @throws AiException when the provider cannot be reached or refuses the request, with a message for people
     */
    public function send(Request $request): Response;
}
