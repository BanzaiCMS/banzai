<?php
declare(strict_types=1);

namespace Banzai\Core;

use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader as TwigFileSystemLoader;
use Flux\Core\ApplicationInterface;
use Flux\Core\Core as FluxCoreApplication;
use Banzai\I18n\TranslatorService;
use Banzai\I18n\Locale\LocaleService;
use Banzai\Authentication\user;
use Banzai\Domain\Articles\ArticlesGateway;
use Banzai\Domain\Blocks\BlocksGateway;
use Banzai\Domain\Customers\CustomersGateway;
use Banzai\Domain\Files\FilesGateway;
use Banzai\Domain\Folders\FoldersGateway;
use Banzai\Domain\Pictures\PicturesGateway;
use Banzai\Domain\Products\ProductsGateway;
use Banzai\Domain\Users\Password;
use Banzai\Domain\Videos\VideosGateway;
use Banzai\Http\Filter\IPFilter;
use Banzai\Http\Routing\DatabaseRouter;
use Banzai\Http\Routing\DatabaseRouterGateway;
use Banzai\Http\Tracker\ClientTracker;
use Banzai\Navigation\NavigationGateway;
use Banzai\Renderers\RenderersGateway;
use Banzai\Renderers\LegacyWikiText;
use Banzai\Search\ElasticService;

class Application extends FluxCoreApplication implements ApplicationInterface
{
    protected string $frameworkversion = '6.0.0';

    public function getVersion(bool $parent = false): string
    {
        if ($parent)
            return parent::getVersion();
        else
            return $this->frameworkversion;

    }

    protected function registerBootstrapParams(): void
    {

        parent::registerBootstrapParams();

        $di = self::getContainer();
        $Config = $di->get('config');


        // twig init here
        if ($Config->has('path.twig.templates'))
            $twigtemplatepath = $Config->get('path.twig.templates');
        else {
            $twigtemplatepath = $Config->get('path.templates');
            $Config->set('path.twig.templates', $twigtemplatepath);
        }

        if ($Config->has('path.twig.cache'))
            $twigtemplatecachepath = $Config->get('path.twig.cache');
        else {
            $twigtemplatecachepath = $Config->get('path.cache') . 'twig';
            $Config->set('path.twig.cache', $twigtemplatecachepath);
        }

        $loader = new TwigFilesystemLoader($twigtemplatepath);
        $twig = new TwigEnvironment($loader, array('cache' => $twigtemplatecachepath));

        if ($this->isDevelopment())
            $twig->setCache(false);

        $di->setInstance('twig', $twig);

    }


    protected function registerCoreContainerServices(): void
    {
        parent::registerCoreContainerServices();

        $di = self::getContainer();

        $di->set('locale', function () use ($di) {
            return new LocaleService(
                $di->get('db'),
                $di->get('logger')
            );
        });

        $di->set('user', function () use ($di) {
            return new \Banzai\Domain\Users\User(
                $di->get('db'),
                $di->get('logger')
            );
        });

        $di->set(Password::class, function () use ($di) {
            return new Password(
                $di->get('db'),
                $di->get('logger')
            );
        });

    }

    protected function registerApplicationContainerServices(): void
    {

        $di = parent::getContainer();


        $di->set('filter', function () use ($di) {
            return new IPFilter(
                $di->get('db'),
                $di->get('logger')
            );
        });


        $di->set('tracker', function () use ($di) {
            return new ClientTracker(
                $di->get('db'),
                $di->get('logger'),
                $di->get('config'),
                $di->get('user')
            );
        });

        $di->set('auth', function () use ($di) {
            return new user(
                $di->get('db'),
                $di->get('logger'),
                $di->get('user'),
                $di->get('tracker'),
                $di->get(Password::class),
            );
        });

        // special case for lazy-Injection caused by circular dependencies
        $di->set(FoldersGateway::class, function () use ($di) {
            $proxy = new FoldersGateway();
            $di->setInstance(FoldersGateway::class, $proxy);
            $proxy->_inject(
                $di->get('db'),
                $di->get('logger'),
                $di->get(ArticlesGateway::class)
            );
            return $proxy;
        });

        // special case for lazy-Injection caused by circular dependencies
        $di->set(ArticlesGateway::class, function () use ($di) {
            $proxy = new ArticlesGateway();
            $di->setInstance(ArticlesGateway::class, $proxy);
            $proxy->_inject(
                $di->get('db'),
                $di->get('logger'),
                $di->get('config'),
                $di->get('twig'),
                $di->get('locale'),
                $di->get(FoldersGateway::class)
            );
            return $proxy;
        });

        $di->set(CustomersGateway::class, function () use ($di) {
            return new CustomersGateway(
                $di->get('db'),
                $di->get('logger')
            );
        });

        $di->set(ProductsGateway::class, function () use ($di) {
            return new ProductsGateway(
                $di->get('db'),
                $di->get('logger')
            );
        });

        $di->set(PicturesGateway::class, function () use ($di) {
            if (defined('INSCCMS_TWIG')) {
                return new PicturesGateway(
                    $di->get('db'),
                    $di->get('logger'),
                    $di->get('config'),
                    $di->get('twig')
                );
            } else {
                return new PicturesGateway(
                    $di->get('db'),
                    $di->get('logger'),
                    $di->get('config')
                );
            }
        });

        $di->set(FilesGateway::class, function () use ($di) {
            return new FilesGateway(
                $di->get('db'),
                $di->get('logger'),
                $di->get(PicturesGateway::class)
            );
        });

        $di->set(VideosGateway::class, function () use ($di) {
            return new VideosGateway(
                $di->get('db'),
                $di->get('logger'),
                $di->get(PicturesGateway::class)
            );
        });

        $di->set('router', function () use ($di) {
            return new DatabaseRouter(
                $di->get('db'),
                $di->get('logger')
            );
        });

        $di->set('routergateway', function () use ($di) {
            return new DatabaseRouterGateway(
                $di->get('db'),
                $di->get('logger'),
                $di->get('config'),
                $di->get('router'),
                $di->get(FoldersGateway::class)
            );
        });

        $di->set('navigation', function () use ($di) {
            return new NavigationGateway(
                $di->get('db'),
                $di->get('logger'),
                $di->get('user'),
                $di->get(ArticlesGateway::class),
                $di->get('router')
            );
        });

        $di->set(BlocksGateway::class, function () use ($di) {
            return new BlocksGateway(
                $di->get('event_dispatcher'),
                $di->get('db'),
                $di->get('logger'),
                $di->get('config'),
                $di->get('twig'),
                $di->get('user')
            );
        });

        $di->set(LegacyWikiText::class, function () use ($di) {
            return new LegacyWikiText(
                $di->get('db'),
                $di->get('logger'),
                $di->get(FoldersGateway::class),
                $di->get(ArticlesGateway::class),
                $di->get(ProductsGateway::class),
                $di->get(PicturesGateway::class),
                $di->get(FilesGateway::class)
            );
        });

        $di->set(RenderersGateway::class, function () use ($di) {
            return new RenderersGateway(
                $di->get('logger'),
                $di->get(LegacyWikiText::class)
            );
        });


        $di->set('translator', function () use ($di) {
            return new TranslatorService(
                $di->get('db'),
                $di->get('logger')
            );
        });

        $di->set('elastic', function () use ($di) {
            return new ElasticService(
                $di->get('logger'),
                $di->get('config'),
                $di->get(ArticlesGateway::class)
            );
        });


    }

}
