<?php

namespace App\Vito\Plugins\Lifelessrasel\VarnishCachePlugin;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterSiteFeature;
use App\Plugins\RegisterSiteFeatureAction;
use App\Vito\Plugins\Vitodeploy\VarnishCachePlugin\Actions\Disable;
use App\Vito\Plugins\Vitodeploy\VarnishCachePlugin\Actions\Enable;

class Plugin extends AbstractPlugin
{
    protected string $name = 'Varnish Cache Plugin';

    protected string $description = 'Varnish Cache plugin for VitoDeploy';

    public function boot(): void
    {
        RegisterSiteFeature::make('nginx', 'varnish-cache')
            ->label('Varnish Cache')
            ->description('Enable Varnish Cache for this site')
            ->register();
        RegisterSiteFeatureAction::make('nginx', 'varnish-cache', 'enable')
            ->label('Enable')
            ->handler(Enable::class)
            ->register();
        RegisterSiteFeatureAction::make('nginx', 'varnish-cache', 'disable')
            ->label('Disable')
            ->handler(Disable::class)
            ->register();
    }
}
