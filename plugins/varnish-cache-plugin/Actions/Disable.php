<?php

namespace App\Vito\Plugins\Vitodeploy\VarnishCachePlugin\Actions;

use App\Actions\Worker\DeleteWorker;
use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Models\Worker;
use App\SiteFeatures\Action;
use Illuminate\Http\Request;

class Disable extends Action
{
    public function name(): string
    {
        return 'Disable';
    }

    public function active(): bool
    {
        return data_get($this->site->type_data, 'varnish', false);
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('confirm')
                ->alert()
                ->description('Are you sure you want to disable Varnish Cache for this site?')
                ->options(['type' => 'warning']),
        ]);
    }

    public function handle(Request $request): void
    {
        $typeData = $this->site->type_data ?? [];

        /** @var ?Worker $worker */
        $worker = $this->site->workers()->where('name', 'varnish-cache')->first();
        if ($worker) {
            app(DeleteWorker::class)->delete($worker);
        }

        data_set($typeData, 'varnish', false);
        $this->site->type_data = $typeData;
        $this->site->save();

        $webserver = $this->site->webserver()->id();

        if ($webserver === 'nginx') {
            $this->site->webserver()->updateVHost(
                $this->site,
                replace: [
                    'varnish' => '',
                ],
            );
        }

        $request->session()->flash('success', 'Varnish Cache has been disabled for this site.');
    }
}
