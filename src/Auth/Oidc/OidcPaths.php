<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Auth\Oidc;

use Frootbox\Core\Config\Config;

/**
 * The addresses of the website for a provider (decision 0322): the way
 * back below backend.path, the start from the provider and the back
 * channel, which runs past the password of the website.
 */
final readonly class OidcPaths
{
    public const string LOGOUT = '/_oidc/{provider:[a-z][a-z0-9-]{0,49}}/logout';

    public function __construct(private Config $config) {}

    public function redirect(string $provider): ?string
    {
        $site = $this->siteUrl();

        return null === $site ? null : $site . $this->backendPath() . '/login/oidc/' . $provider;
    }

    public function initiate(string $provider): ?string
    {
        $redirect = $this->redirect($provider);

        return null === $redirect ? null : $redirect . '/start';
    }

    public function logout(string $provider): ?string
    {
        $site = $this->siteUrl();

        return null === $site ? null : $site . '/_oidc/' . $provider . '/logout';
    }

    /**
     * The address of the website from app.url, without a trailing slash.
     */
    public function siteUrl(): ?string
    {
        $url = $this->config->get('app.url');

        return \is_string($url) && 1 === preg_match('#^https?://[^/?\#]+(/[^?\#]*)?$#', $url) ? rtrim($url, '/') : null;
    }

    private function backendPath(): string
    {
        return '/' . trim($this->config->string('backend.path', '/cms'), '/');
    }
}
