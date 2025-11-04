<?php

namespace App\Vito\Plugins\Lifelessrasel\VarnishCachePlugin;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterSiteFeature;
use App\Plugins\RegisterSiteFeatureAction;
use App\Vito\Plugins\Lifelessrasel\VarnishCachePlugin\Actions\Enable;
use App\Vito\Plugins\Lifelessrasel\VarnishCachePlugin\Actions\Disable;
use App\Vito\Plugins\Lifelessrasel\VarnishCachePlugin\Actions\PurgeCache;

/**
 * Varnish Cache Plugin for VitoDeploy
 * 
 * This plugin provides Varnish HTTP accelerator (caching reverse proxy) integration
 * for websites managed by VitoDeploy. It allows you to:
 * - Enable/disable Varnish cache per site
 * - Configure Varnish to cache your website content
 * - Purge cache when needed
 * - Automatically configure backend connections
 * 
 * @package App\Vito\Plugins\Lifelessrasel\VarnishCachePlugin
 * @author Lifeless Rasel
 * @version 2.0.0
 */
class Plugin extends AbstractPlugin
{
    /**
     * Plugin name displayed in VitoDeploy UI
     *
     * @var string
     */
    protected string $name = 'Varnish Cache Plugin';

    /**
     * Plugin description displayed in VitoDeploy UI
     *
     * @var string
     */
    protected string $description = 'HTTP accelerator and caching reverse proxy for blazing fast website performance';

    /**
     * Bootstrap the plugin and register features
     *
     * This method is called when the plugin is loaded. It registers:
     * - The Varnish cache feature for all site types
     * - Enable action to activate Varnish for a site
     * - Disable action to deactivate Varnish for a site
     * - Purge cache action to clear cached content
     *
     * @return void
     */
    public function boot(): void
    {
        // Register the Varnish cache feature for all site types
        // This makes the feature available in the site's features panel
        RegisterSiteFeature::make('*', 'varnish-cache')
            ->label('Varnish Cache')
            ->description('Enable Varnish HTTP accelerator for blazing fast performance')
            ->register();

        // Register the enable action
        // This action installs and configures Varnish for the selected site
        RegisterSiteFeatureAction::make('*', 'varnish-cache', 'enable')
            ->label('Enable')
            ->handler(Enable::class)
            ->register();

        // Register the disable action
        // This action disables Varnish and restores direct web server access
        RegisterSiteFeatureAction::make('*', 'varnish-cache', 'disable')
            ->label('Disable')
            ->handler(Disable::class)
            ->register();

        // Register the purge cache action
        // This action clears all cached content for the site
        RegisterSiteFeatureAction::make('*', 'varnish-cache', 'purge')
            ->label('Purge Cache')
            ->handler(PurgeCache::class)
            ->register();
    }
}
