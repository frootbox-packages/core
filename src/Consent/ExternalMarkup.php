<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

use Closure;
use Frootbox\RichText\Html;

/**
 * The markup of external content and of the privacy settings (decision
 * 0224). The script assets/external.js makes them work: without it the
 * buttons stay hidden and only the link to the provider is left.
 */
final readonly class ExternalMarkup
{
    public const string SCRIPT = 'assets/external.js';

    public const string STYLE = 'assets/external.css';

    /**
     * @param Closure(string, array<string, string>): string $t A text for visitors in the language of the page
     */
    public function __construct(private Closure $t) {}

    /**
     * A placeholder that loads the content after a click, or at once when
     * the visitor always loads this service.
     *
     * @param string      $html  The content, e.g. an iframe
     * @param string|null $link  The content at the provider, for visitors without JavaScript
     * @param string|null $title What the content is, e.g. "Video: Our roastery"
     * @param string|null $ratio Keeps the place, e.g. "16/9"
     */
    public function external(ExternalService $service, string $html, ?string $link = null, ?string $title = null, ?string $ratio = null): string
    {
        $names = ['service' => $service->title, 'provider' => $service->provider];
        $notice = Html::element('p', Html::escape($this->text('This content comes from {service}. When it loads, your browser sends data to {provider}.', $names)) . ' ' . Html::element('a', Html::escape($this->text('Privacy policy', $names)), ['href' => $service->privacyUrl, 'rel' => 'noopener noreferrer', 'target' => '_blank']));
        $actions = Html::element('div', Html::element('button', Html::escape($this->text('Load content', $names)), ['type' => 'button', 'class' => 'external__button', 'data-external-load' => true])
            . Html::element('label', Html::open('input', ['type' => 'checkbox', 'data-external-always' => true]) . ' ' . Html::escape($this->text('Always load {service}', $names)), ['class' => 'external__always']), ['class' => 'external__actions', 'hidden' => true]);
        $fallback = null === $link ? '' : Html::element('p', Html::element('a', Html::escape($this->text('Open it at {service}', $names)), ['href' => $link, 'rel' => 'noopener noreferrer']), ['class' => 'external__link']);

        return Html::element(
            'fb-external',
            '<template>' . $html . '</template>' . Html::element('div', $notice . $actions . $fallback, ['class' => 'external__notice', 'role' => 'group', 'aria-label' => $title ?? $service->title]),
            ['class' => 'external', 'service' => $service->name, 'style' => null === $ratio ? null : '--external-ratio: ' . $ratio],
        );
    }

    /**
     * A button that opens a dialog with every service and whether it
     * loads by itself; nothing without services.
     *
     * @param array<string, ExternalService> $services
     */
    public function settings(array $services): string
    {
        if ([] === $services) {
            return '';
        }

        $items = '';

        foreach ($services as $service) {
            $label = Html::open('input', ['type' => 'checkbox', 'value' => $service->name]) . ' ' . Html::escape($service->title) . ' ' . Html::element('span', Html::escape('(' . $service->provider . ')'), ['class' => 'privacy-settings__provider']);
            $items .= Html::element('li', Html::element('label', $label) . ' ' . Html::element('a', Html::escape($this->text('Privacy policy', [])), ['href' => $service->privacyUrl, 'rel' => 'noopener noreferrer', 'target' => '_blank']));
        }

        $dialog = Html::element('dialog', Html::element('form', Html::element('p', Html::escape($this->text('Privacy settings', [])), ['class' => 'privacy-settings__title', 'id' => 'privacy-settings-title'])
            . Html::element('p', Html::escape($this->text('Content of these services loads only if you agree. Ticked services load by themselves. A change applies to the next page you open.', [])))
            . Html::element('ul', $items, ['class' => 'privacy-settings__services'])
            . Html::element('button', Html::escape($this->text('Done', [])), ['type' => 'submit', 'class' => 'privacy-settings__done']), ['method' => 'dialog']), ['class' => 'privacy-settings__dialog', 'aria-labelledby' => 'privacy-settings-title']);

        return Html::element('fb-privacy-settings', Html::element('button', Html::escape($this->text('Privacy settings', [])), ['type' => 'button', 'class' => 'privacy-settings__button', 'hidden' => true]) . $dialog, ['class' => 'privacy-settings']);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function text(string $text, array $parameters): string
    {
        return ($this->t)($text, $parameters);
    }
}
