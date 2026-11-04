<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

use Frootbox\Core\View\BuildingHead;
use Frootbox\RichText\Html;
use Twig\Environment;

/**
 * Everything visitors see about consent, from the partials of the core in
 * partials/consent/, which a theme overrides (decisions 0224, 0264): the
 * placeholder of content, the privacy settings and the banner. The script
 * assets/external.js makes them work; without it nothing loads.
 */
final readonly class ConsentMarkup
{
    public const string SCRIPT = 'assets/external.js';

    public const string STYLE = 'assets/external.css';

    public function __construct(
        private ExternalServices $services,
        private ConsentTools $tools,
        private ConsentSettings $settings,
        private Environment $twig,
    ) {}

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
        return $this->twig->render('@theme/consent/external.twig', [
            'service' => self::service($service),
            'content' => $html,
            'link' => $link,
            'title' => $title,
            'ratio' => $ratio,
        ]);
    }

    /**
     * The button "Privacy settings" with its dialog; nothing without
     * services.
     */
    public function settings(bool $floating = false): string
    {
        $services = $this->services->all();

        if ([] === $services) {
            return '';
        }

        $groups = [];

        foreach (Purpose::cases() as $purpose) {
            $members = array_filter($services, static fn(ExternalService $service): bool => $service->purpose === $purpose);

            if ([] !== $members) {
                $groups[] = ['purpose' => $purpose->value, 'title' => $purpose->title(), 'services' => array_values(array_map(self::service(...), $members))];
            }
        }

        return $this->twig->render('@theme/consent/settings.twig', ['groups' => $groups, 'version' => $this->version(), 'proof' => $this->settings->proof, 'floating' => $floating]);
    }

    /**
     * A script or other markup of a service on every page, inactive until
     * the visitor agrees, for the head:
     *
     *     $event->head->add($consent->deferred('acme/stats/matomo', '<script src="…"></script>'));
     *
     * @param string $html Escaped by the caller
     */
    public function deferred(string $service, string $html): string
    {
        $known = $this->services->get($service) ?? throw new ConsentException(\sprintf('The external service "%s" is not registered. Register it with $registry->add(ExternalService::class, …).', $service));

        if (Reach::Site !== $known->reach) {
            throw new ConsentException(\sprintf('The external service "%s" loads at its content. A script on every page needs a service with the reach Reach::Site.', $service));
        }

        return Html::element('template', $html, ['data-fb-consent' => $service]);
    }

    /**
     * What the core puts at the start of <body> (decision 0264): the
     * banner when a service loads on every page, the element of the
     * active consent tool instead, and the privacy settings when the
     * theme put none on the page but the visitor may need them.
     */
    public function page(string $body): string
    {
        $services = $this->services->all();

        if ([] === $services) {
            return '';
        }

        $tool = $this->tools->active();

        if (null !== $tool) {
            return Html::element('fb-consent-banner', '', ['class' => 'consent-banner', 'tool' => $tool->name, 'data-consent-version' => $this->version(), 'hidden' => true]);
        }

        $site = array_filter($services, static fn(ExternalService $service): bool => Reach::Site === $service->reach);
        $html = [] === $site ? '' : $this->twig->render('@theme/consent/banner.twig', [
            'services' => array_values(array_map(static fn(ExternalService $service): array => [...self::service($service), 'purpose' => $service->purpose->title()], $site)),
            'version' => $this->version(),
            'proof' => $this->settings->proof,
        ]);

        // Taking back a consent is as easy as giving it, also when the theme forgot the button.
        if (!str_contains($body, '<fb-privacy-settings') && ([] !== $site || str_contains($body, '<fb-external'))) {
            $html .= $this->settings(floating: true);
        }

        return $html;
    }

    /**
     * The tags of the active consent tool for the head, e.g. its script.
     */
    public function tool(BuildingHead $event): string
    {
        $tool = $this->tools->active();

        return null === $tool ? '' : $this->tools->provider($tool)->head($event);
    }

    /**
     * Changes when a service comes, goes or changes its provider, purpose
     * or reach; the browser keeps it with the choice.
     */
    public function version(): string
    {
        $parts = array_map(static fn(ExternalService $service): string => implode('|', [$service->name, $service->provider, $service->purpose->value, $service->reach->value]), $this->services->all());
        sort($parts);

        return substr(hash('sha256', implode("\n", $parts)), 0, 12);
    }

    /**
     * @return array{name: string, title: string, provider: string, privacyUrl: string, reach: string}
     */
    private static function service(ExternalService $service): array
    {
        return ['name' => $service->name, 'title' => $service->title, 'provider' => $service->provider, 'privacyUrl' => $service->privacyUrl, 'reach' => $service->reach->value];
    }
}
