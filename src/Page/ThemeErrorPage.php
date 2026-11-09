<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use Frootbox\Core\Asset\AssetList;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Error\ErrorDetails;
use Frootbox\Core\Error\ErrorPage;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\I18n\Formality;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\I18n\VisitorFormality;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Theme\Theme;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\View\Delivery;
use Frootbox\Core\View\Document;
use Frootbox\Core\View\Head;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Twig\Environment as Twig;
use Twig\Markup;

/**
 * The error page of the website in the frame of the theme (decision
 * 0189): the partial @theme/error.twig with head and foot, the texts of
 * the core translated through the themes, a link to the start page in the
 * language of the address. It comes after the error pages of extensions,
 * so the backend keeps its own, and before the plain page of the core.
 *
 * Not responsible before the installation, for the access to the website
 * (password, preview links: nothing of the site may show there) and when
 * no theme in the chain brings error.twig.
 */
final readonly class ThemeErrorPage implements ErrorPage
{
    public const string TEMPLATE = '@theme/error.twig';

    /** Cases of the access to the website, see HttpException. */
    private const array ACCESS = ['site-protected', 'site-protected-closed', 'preview-expired', 'page-protected'];

    /** Title and text by status, in English; the themes translate them. */
    private const array TEXTS = [
        403 => ['No access', 'You may not see this page.'],
        404 => ['Page not found', 'This page does not exist (any more). Maybe its address has changed.'],
        405 => ['Not possible this way', 'This address does not answer this kind of request.'],
        410 => ['Page removed', 'This page is no longer here.'],
        500 => ['Something went wrong', 'That is our fault, not yours. Please try again in a moment.'],
        503 => ['Temporarily unavailable', 'This page is being worked on. Please try again later.'],
    ];

    /**
     * @param ContainerInterface $container The services are loaded once the page is responsible, so an error before the installation needs no database
     */
    public function __construct(
        private Config $config,
        private ContainerInterface $container,
    ) {}

    public function render(ServerRequestInterface $request, int $status, Throwable $throwable, bool $showDetails): ?string
    {
        if (!$this->responsible($status, $throwable)) {
            return null;
        }

        $uri = $request->getUri();
        $languages = $this->service(Languages::class);
        $language = ($languages->match($uri->getHost(), $uri->getPath()) ?? [$languages->default()])[0];
        // Never in the editing mode: no page to change, no marks (decision 0189).
        $this->service(EditMode::class)->set(false);
        $this->service(Delivery::class)->set($language);

        [$title, $text] = self::TEXTS[$status] ?? self::TEXTS[$status >= 500 ? 500 : 404];
        $themes = array_map(static fn(Theme $theme): string => $theme->name, $this->service(ThemeChain::class)->themes);
        $translator = $this->service(Translator::class);
        $formality = $this->formality();
        $translate = static fn(string $english): string => $translator->in($language->code, $english, $themes, formality: $formality);
        $title = $translate($title);

        $body = $this->service(Twig::class)->render(self::TEMPLATE, [
            'status' => $status,
            'title' => $title,
            'text' => $translate($text),
            'start' => '/' . $language->prefix,
            'details' => $showDetails ? new Markup(ErrorDetails::html($throwable, $translate('Technical details')), 'UTF-8') : null,
        ]);

        $head = new Head($language->code, $title . ' – ' . $this->config->string('app.name', 'Frootbox'), direction: $language->direction->value);
        $head->add('<meta name="robots" content="noindex">');

        return $this->service(Document::class)->render($head, $body, new AssetList(), $request);
    }

    /**
     * How the visitor is addressed (decision 0316); informal when the
     * settings cannot be read, e.g. because the database is the error.
     */
    private function formality(): Formality
    {
        try {
            return $this->service(VisitorFormality::class)->current();
        } catch (Throwable) {
            return Formality::Informal;
        }
    }

    private function responsible(int $status, Throwable $throwable): bool
    {
        $database = $this->config->get('database.url');

        return \is_string($database) && '' !== $database
            // The access to the website stays with the page of the core (decisions 0152, 0153).
            && 401 !== $status
            && !($throwable instanceof HttpException && \in_array($throwable->reason, self::ACCESS, true))
            && $this->service(Twig::class)->getLoader()->exists(self::TEMPLATE);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = $this->container->get($class);

        return $service instanceof $class ? $service : throw new LogicException(\sprintf('%s is not available.', $class));
    }
}
