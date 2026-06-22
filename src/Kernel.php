<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @brief Register project bundles.
     * @param void No input parameter.
     * @return iterable<object>
     * @date 2026-04-22
     * @author Stephane H.
     */
    public function registerBundles(): iterable
    {
        $contents = require $this->getProjectDir().'/config/bundles.php';

        foreach ($contents as $class => $envs) {
            if (($envs[$this->environment] ?? $envs['all'] ?? false) === true) {
                yield new $class();
            }
        }
    }

    /**
     * @brief Configure dependency injection container.
     * @param ContainerBuilder $container Container builder instance.
     * @param LoaderInterface $loader Loader instance.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $configDir = $this->getProjectDir().'/config';
        $envPackagesDir = $configDir.'/packages/'.$this->environment;
        $envServicesFile = $configDir.'/services_'.$this->environment.'.yaml';
        $container->import($configDir.'/packages/*.yaml');
        if (is_dir($envPackagesDir)) {
            $container->import($envPackagesDir.'/*.yaml');
        }
        $container->import($configDir.'/services.yaml');
        if (is_file($envServicesFile)) {
            $container->import($envServicesFile);
        }
    }

    /**
     * @brief Configure application routes.
     * @param RoutingConfigurator $routes Route configurator.
     * @return void
     * @date 2026-04-22
     * @author Stephane H.
     */
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $projectDir = $this->getProjectDir();

        $routes->import($projectDir.'/config/routes.yaml');
        $routes->import($projectDir.'/config/routes/*.yaml');
    }
}
