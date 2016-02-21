<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sylius\Bundle\SettingsBundle\Manager;

use Doctrine\Common\Persistence\ObjectManager;
use Sylius\Bundle\SettingsBundle\Event\SettingsEvent;
use Sylius\Bundle\SettingsBundle\Model\SettingsInterface;
use Sylius\Bundle\SettingsBundle\Model\Settings;
use Sylius\Bundle\SettingsBundle\Resolver\SettingsResolverInterface;
use Sylius\Bundle\SettingsBundle\Schema\SchemaRegistryInterface;
use Sylius\Bundle\SettingsBundle\Schema\SettingsBuilder;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @author Paweł Jędrzejewski <pawel@sylius.org>
 */
class SettingsManager implements SettingsManagerInterface
{
    /**
     * @var SchemaRegistryInterface
     */
    protected $schemaRegistry;

    /**
     * @var ServiceRegistryInterface
     */
    protected $resolverRegistry;

    /**
     * @var ObjectManager
     */
    protected $settingsManager;

    /**
     * @var FactoryInterface
     */
    protected $settingsFactory;

    /**
     * @var SettingsResolverInterface
     */
    protected $defaultResolver;

    /**
     * Runtime cache for resolved parameters.
     *
     * @var Settings[]
     */
    protected $resolvedSettings = [];

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function __construct(
        SchemaRegistryInterface $schemaRegistry,
        ServiceRegistryInterface $resolverRegistry,
        ObjectManager $settingsManager,
        FactoryInterface $settingsFactory,
        SettingsResolverInterface $defaultResolver,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->schemaRegistry = $schemaRegistry;
        $this->resolverRegistry = $resolverRegistry;
        $this->settingsManager = $settingsManager;
        $this->settingsFactory = $settingsFactory;
        $this->defaultResolver = $defaultResolver;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * {@inheritdoc}
     */
    public function load($schemaAlias, $ignoreUnknown = true)
    {
        $schema = $this->schemaRegistry->getSchema($schemaAlias);

        $resolver = $this->defaultResolver;

        if ($this->resolverRegistry->has($schemaAlias)) {
            $resolver = $this->resolverRegistry->get($schemaAlias);
        }

        $settings = $resolver->resolve($schemaAlias);

        if (!$settings) {
            $settings = $this->settingsFactory->createNew();
            $settings->setSchema($schemaAlias);
        }

        $parameters = $settings->getParameters();

        $settingsBuilder = new SettingsBuilder();
        $schema->buildSettings($settingsBuilder);

        // Remove unknown settings' parameters (e.g. From a previous version of the settings schema)
        if (true === $ignoreUnknown) {
            foreach ($parameters as $name => $value) {
                if (!$settingsBuilder->isDefined($name)) {
                    unset($parameters[$name]);
                }
            }
        }

        $parameters = $settingsBuilder->resolve($parameters);
        $settings->setParameters($parameters);

        return $settings;
    }

    /**
     * {@inheritdoc}
     */
    public function save(SettingsInterface $settings)
    {
        $schema = $this->schemaRegistry->getSchema($settings->getSchema());

        $settingsBuilder = new SettingsBuilder();
        $schema->buildSettings($settingsBuilder);

        $settingsBuilder->resolve($settings->getParameters());

        $this->settingsManager->persist($settings);

        $this->eventDispatcher->dispatch(SettingsEvent::PRE_SAVE, new SettingsEvent($settings));
        $this->settingsManager->flush();
        $this->eventDispatcher->dispatch(SettingsEvent::POST_SAVE, new SettingsEvent($settings));
    }
}
