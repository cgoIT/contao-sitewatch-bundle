<?php

declare(strict_types=1);

/*
 * This file is part of cgoit\contao-sitewatch-bundle for Contao Open Source CMS.
 *
 * @copyright  Copyright (c) cgoIT
 * @author     cgoIT <https://cgo-it.de>
 * @license    LGPL-3.0-or-later
 */

namespace Cgoit\SitewatchBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('cgoit_sitewatch');
        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('url')->defaultNull()->info('Basis-URL von Sitewatch, nur https (http nur für localhost).')->end()
                ->scalarNode('token')->defaultNull()->info('Token dieser Site in Sitewatch, am besten als %env(SITEWATCH_TOKEN)%.')->end()
                ->scalarNode('site_id')
                    ->defaultNull()
                    ->info('Kennung der Site in Sitewatch (a-z, 0-9, -).')
                    ->validate()
                        ->ifTrue(static fn ($v): bool => null !== $v && !preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', (string) $v))
                        ->thenInvalid('site_id darf nur a-z, 0-9 und - enthalten (max. 63 Zeichen).')
                    ->end()
                ->end()
                ->scalarNode('site_name')->defaultNull()->info('Anzeigename, Standard ist die site_id.')->end()
                ->scalarNode('site_url')->defaultNull()->info('Öffentliche URL der Seite, nur zur Anzeige.')->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
