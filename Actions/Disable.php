<?php

namespace App\Vito\Plugins\Lifelessrasel\VarnishCachePlugin\Actions;

use App\Exceptions\SSHError;
use App\SiteFeatures\Action;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Disable Varnish Cache Action
 * 
 * This action disables Varnish for a site and restores direct web server access.
 * 
 * Flow:
 * 1. Check if Varnish is enabled
 * 2. Restore web server to listen on ports 80/443
 * 3. Remove VCL configuration for the site
 * 4. Restart services
 * 5. Update site metadata
 */
class Disable extends Action
{
    /**
     * Action name displayed in UI
     *
     * @return string
     */
    public function name(): string
    {
        return 'Disable';
    }

    /**
     * Check if the action should be active/available
     * Returns true only if Varnish is currently enabled
     *
     * @return bool
     */
    public function active(): bool
    {
        return data_get($this->site->type_data, 'varnish_enabled', false);
    }

    /**
     * No form needed for disabling
     *
     * @return null
     */
    public function form(): null
    {
        return null;
    }

    /**
     * Handle the disable action
     *
     * @param Request $request
     * @return void
     * @throws SSHError
     */
    public function handle(Request $request): void
    {
        // Get stored configuration
        $typeData = $this->site->type_data ?? [];
        $backendPort = data_get($typeData, 'varnish_backend_port', 8080);

        // Restore web server configuration
        $this->restoreWebServerConfig($backendPort);

        // Remove VCL configuration
        $this->removeVarnishConfig();

        // Update site metadata
        data_set($typeData, 'varnish_enabled', false);
        data_forget($typeData, 'varnish_backend_port');
        data_forget($typeData, 'varnish_cache_ttl');
        data_forget($typeData, 'varnish_memory');
        $this->site->type_data = $typeData;
        $this->site->save();

        // Check if any other sites are using Varnish
        $otherSitesUsingVarnish = $this->checkOtherSitesUsingVarnish();

        // If no other sites are using Varnish, we can optionally stop it
        if (!$otherSitesUsingVarnish) {
            try {
                $this->site->server->ssh()->exec('sudo systemctl stop varnish');
            } catch (\Exception $e) {
                // Silently fail if Varnish can't be stopped
            }
        }

        $request->session()->flash('success', 'Varnish cache has been disabled for this site.');
    }

    /**
     * Restore web server configuration to listen on standard ports
     *
     * @param int $backendPort
     * @return void
     */
    private function restoreWebServerConfig(int $backendPort): void
    {
        $webserver = $this->site->webserver();

        if ($webserver->id() === 'nginx') {
            $this->restoreNginxConfig($backendPort);
            return;
        }

        throw new RuntimeException('Unsupported webserver: ' . $webserver->id());
    }

    /**
     * Restore Nginx configuration to standard ports
     *
     * @param int $backendPort
     * @return void
     * @throws SSHError
     */
    private function restoreNginxConfig(int $backendPort): void
    {
        $ssh = $this->site->server->ssh();
        $domain = $this->site->domain;

        // Restore listen directives to standard ports
        $ssh->exec("sudo sed -i 's/listen $backendPort;/listen 80;/' /etc/nginx/sites-available/$domain");
        $ssh->exec("sudo sed -i 's/listen \\[\\:\\:\\]:$backendPort;/listen [::]:80;/' /etc/nginx/sites-available/$domain");
        
        // Restore SSL ports if they exist
        $sslBackendPort = $backendPort + 363;
        $ssh->exec("sudo sed -i 's/listen $sslBackendPort ssl;/listen 443 ssl;/' /etc/nginx/sites-available/$domain");
        $ssh->exec("sudo sed -i 's/listen \\[\\:\\:\\]:$sslBackendPort ssl;/listen [::]:443 ssl;/' /etc/nginx/sites-available/$domain");
        
        // Reload Nginx
        $ssh->exec('sudo systemctl reload nginx');
    }

    /**
     * Remove Varnish VCL configuration for the site
     *
     * @return void
     * @throws SSHError
     */
    private function removeVarnishConfig(): void
    {
        $ssh = $this->site->server->ssh();
        $domain = $this->site->domain;
        $vclPath = "/etc/varnish/sites/{$domain}.vcl";

        // Remove VCL file
        $ssh->exec("sudo rm -f $vclPath");

        // Remove include from main config
        $ssh->exec("sudo sed -i '\\|include \"$vclPath\"|d' /etc/varnish/default.vcl");

        // Reload Varnish if it's running
        try {
            $ssh->exec('sudo systemctl reload varnish 2>/dev/null || true');
        } catch (\Exception $e) {
            // Silently fail if Varnish is not running
        }
    }

    /**
     * Check if any other sites on the server are using Varnish
     *
     * @return bool
     */
    private function checkOtherSitesUsingVarnish(): bool
    {
        $sites = $this->site->server->sites()
            ->where('id', '!=', $this->site->id)
            ->get();

        foreach ($sites as $site) {
            $typeData = $site->type_data ?? [];
            if (data_get($typeData, 'varnish_enabled', false)) {
                return true;
            }
        }

        return false;
    }
}
