<?php

namespace App\Vito\Plugins\Lifelessrasel\VarnishCachePlugin\Actions;

use App\Actions\Worker\CreateWorker;
use App\Actions\Worker\ManageWorker;
use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Exceptions\SSHError;
use App\Models\Worker;
use App\SiteFeatures\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class Enable extends Action
{
    public function name(): string
    {
        return 'Enable';
    }

    public function active(): bool
    {
        return ! data_get($this->site->type_data, 'varnish', false);
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('port')
                ->text()
                ->label('Varnish Port')
                ->default(6081)
                ->description('The port on which Varnish Cache will run. Ensure no other apps are using this port.'),
        ]);
    }

    /**
     * @throws SSHError
     */
    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'port' => 'required|integer|min:1|max:65535',
        ])->validate();

        $command = __('varnishd -a :port -f /etc/varnish/sites/:site_id.vcl', [
            'port' => $request->input('port'),
            'site_id' => $this->site->id,
        ]);

        /** @var ?Worker $worker */
        $worker = $this->site->workers()->where('name', 'varnish-cache')->first();
        if ($worker) {
            app(ManageWorker::class)->restart($worker);
        } else {
            app(CreateWorker::class)->create(
                $this->site->server,
                [
                    'name' => 'varnish-cache',
                    'command' => $command,
                    'user' => $this->site->user ?? $this->site->server->getSshUser(),
                    'auto_start' => true,
                    'auto_restart' => true,
                    'numprocs' => 1,
                ],
                $this->site,
            );
        }

        $typeData = $this->site->type_data ?? [];
        data_set($typeData, 'varnish', true);
        data_set($typeData, 'varnish_port', $request->input('port'));
        $this->site->type_data = $typeData;
        $this->site->save();

        $this->updateVHost();

        $request->session()->flash('success', 'Varnish Cache has been enabled for this site.');
    }

    private function updateVHost(): void
    {
        $webserver = $this->site->webserver();

        if ($webserver->id() === 'nginx') {
            $this->site->webserver()->updateVHost(
                $this->site,
                replace: [
                    'php' => view('ssh.services.webserver.nginx.vhost-blocks.varnish', ['site' => $this->site]),
                ]
            );

            return;
        }

        throw new RuntimeException('Unsupported webserver: '.$webserver->id());
    }
}
