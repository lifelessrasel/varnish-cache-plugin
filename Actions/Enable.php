<?php

namespace App\Vito\Plugins\Lifelessrasel\VarnishCachePlugin\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Exceptions\SSHError;
use App\SiteFeatures\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Enable Varnish Cache Action
 * 
 * This action installs Varnish (if not already installed), configures it as a
 * reverse proxy for the site, and updates the web server configuration to
 * work behind Varnish.
 * 
 * Flow:
 * 1. Check if Varnish is already enabled
 * 2. Install Varnish if not present
 * 3. Configure Varnish backend to point to the site's port
 * 4. Create VCL configuration for the site
 * 5. Update web server to listen on backend port
 * 6. Configure Varnish to listen on the public port
 * 7. Restart services
 */
class Enable extends Action
{
    /**
     * Action name displayed in UI
     *
     * @return string
     */
    public function name(): string
    {
        return 'Enable';
    }

    /**
     * Check if the action should be active/available
     * Returns false if Varnish is already enabled
     *
     * @return bool
     */
    public function active(): bool
    {
        return ! data_get($this->site->type_data, 'varnish_enabled', false);
    }

    /**
     * Define the form fields for enabling Varnish
     *
     * @return DynamicForm|null
     */
    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('info')
                ->alert()
                ->options(['type' => 'info'])
                ->description('Varnish will be installed as a reverse proxy cache. Your site will be moved to port 8080, and Varnish will listen on ports 80/443.'),
            DynamicField::make('backend_port')
                ->text()
                ->label('Backend Port')
                ->default(8080)
                ->description('Port where your web server will listen (Varnish will proxy to this port)'),
            DynamicField::make('cache_ttl')
                ->text()
                ->label('Cache TTL (seconds)')
                ->default(300)
                ->description('Default time-to-live for cached content (in seconds)'),
            DynamicField::make('memory')
                ->text()
                ->label('Cache Memory')
                ->default('256M')
                ->description('Amount of memory to allocate for Varnish cache (e.g., 256M, 1G)'),
        ]);
    }

    /**
     * Handle the enable action
     *
     * @param Request $request
     * @return void
     * @throws SSHError
     */
    public function handle(Request $request): void
    {
        // Validate input
        Validator::make($request->all(), [
            'backend_port' => 'required|integer|min:1024|max:65535',
            'cache_ttl' => 'required|integer|min:0',
            'memory' => 'required|string',
        ])->validate();

        $backendPort = $request->input('backend_port', 8080);
        $cacheTtl = $request->input('cache_ttl', 300);
        $memory = $request->input('memory', '256M');

        // Check if Varnish is installed, install if not
        $this->installVarnish();

        // Create Varnish VCL configuration
        $this->createVarnishConfig($backendPort, $cacheTtl);

        // Update web server configuration
        $this->updateWebServerConfig($backendPort);

        // Configure Varnish service
        $this->configureVarnishService($memory);

        // Update site metadata
        $typeData = $this->site->type_data ?? [];
        data_set($typeData, 'varnish_enabled', true);
        data_set($typeData, 'varnish_backend_port', $backendPort);
        data_set($typeData, 'varnish_cache_ttl', $cacheTtl);
        data_set($typeData, 'varnish_memory', $memory);
        $this->site->type_data = $typeData;
        $this->site->save();

        // Restart Varnish
        $this->site->server->ssh()->exec('sudo systemctl restart varnish');

        $request->session()->flash('success', 'Varnish cache has been enabled for this site.');
    }

    /**
     * Install Varnish if not already installed
     *
     * @return void
     * @throws SSHError
     */
    private function installVarnish(): void
    {
        $ssh = $this->site->server->ssh();

        // Check if Varnish is already installed
        try {
            $installed = $ssh->exec('which varnishd');
            if (!empty($installed)) {
                return; // Already installed
            }
        } catch (\Exception $e) {
            // Not installed, proceed with installation
        }

        // Install Varnish
        $ssh->exec('sudo apt-get update -y', 'update-packages');
        $ssh->exec('sudo apt-get install -y varnish', 'install-varnish');
        $ssh->exec('sudo systemctl enable varnish', 'enable-varnish');
    }

    /**
     * Create Varnish VCL configuration for the site
     *
     * @param int $backendPort
     * @param int $cacheTtl
     * @return void
     * @throws SSHError
     */
    private function createVarnishConfig(int $backendPort, int $cacheTtl): void
    {
        $domain = $this->site->domain;
        $aliases = $this->site->aliases ?? [];
        
        // Build allowed hosts list
        $allowedHosts = [$domain];
        if (!empty($aliases)) {
            $allowedHosts = array_merge($allowedHosts, explode(',', $aliases));
        }
        $allowedHosts = array_map('trim', $allowedHosts);
        $hostsCondition = implode(' || ', array_map(function($host) {
            return "req.http.host == \"$host\"";
        }, $allowedHosts));

        $vcl = <<<VCL
vcl 4.1;

import std;

# Backend configuration
backend default {
    .host = "127.0.0.1";
    .port = "$backendPort";
    .connect_timeout = 600s;
    .first_byte_timeout = 600s;
    .between_bytes_timeout = 600s;
}

# ACL for purge requests
acl purge {
    "localhost";
    "127.0.0.1";
}

sub vcl_recv {
    # Only handle requests for this site
    if (!($hostsCondition)) {
        return (synth(404, "Unknown host"));
    }

    # Allow purging from localhost
    if (req.method == "PURGE") {
        if (!client.ip ~ purge) {
            return (synth(405, "Not allowed"));
        }
        return (purge);
    }

    # Don't cache POST, PUT, DELETE requests
    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }

    # Don't cache authenticated requests
    if (req.http.Authorization || req.http.Cookie ~ "wordpress_logged_in|wp-postpass|comment_author") {
        return (pass);
    }

    # Remove cookies for static files
    if (req.url ~ "\\.(css|js|jpg|jpeg|png|gif|ico|woff|woff2|ttf|svg|webp)\$") {
        unset req.http.Cookie;
    }

    # Normalize Accept-Encoding header
    if (req.http.Accept-Encoding) {
        if (req.url ~ "\\.(jpg|jpeg|png|gif|gz|tgz|bz2|tbz|mp3|ogg|swf|woff|woff2)\$") {
            unset req.http.Accept-Encoding;
        } elsif (req.http.Accept-Encoding ~ "gzip") {
            set req.http.Accept-Encoding = "gzip";
        } elsif (req.http.Accept-Encoding ~ "deflate") {
            set req.http.Accept-Encoding = "deflate";
        } else {
            unset req.http.Accept-Encoding;
        }
    }

    return (hash);
}

sub vcl_backend_response {
    # Set cache TTL
    set beresp.ttl = {$cacheTtl}s;
    
    # Don't cache errors
    if (beresp.status >= 400) {
        set beresp.ttl = 0s;
        return (deliver);
    }

    # Cache static files for longer
    if (bereq.url ~ "\\.(css|js|jpg|jpeg|png|gif|ico|woff|woff2|ttf|svg|webp)\$") {
        set beresp.ttl = 1h;
    }

    # Remove Set-Cookie header for cacheable responses
    if (beresp.ttl > 0s) {
        unset beresp.http.Set-Cookie;
    }

    return (deliver);
}

sub vcl_deliver {
    # Add cache status header
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
        set resp.http.X-Cache-Hits = obj.hits;
    } else {
        set resp.http.X-Cache = "MISS";
    }

    # Remove backend headers (security)
    unset resp.http.X-Powered-By;
    unset resp.http.Server;
    unset resp.http.X-Varnish;
    unset resp.http.Via;
    
    return (deliver);
}

VCL;

        // Write VCL to server
        $vclPath = "/etc/varnish/sites/{$domain}.vcl";
        $ssh = $this->site->server->ssh();
        
        // Create sites directory if it doesn't exist
        $ssh->exec("sudo mkdir -p /etc/varnish/sites");
        
        // Write VCL file
        $tempFile = tempnam(sys_get_temp_dir(), 'vcl');
        file_put_contents($tempFile, $vcl);
        $ssh->upload($tempFile, "/tmp/{$domain}.vcl");
        $ssh->exec("sudo mv /tmp/{$domain}.vcl $vclPath");
        $ssh->exec("sudo chmod 644 $vclPath");
        unlink($tempFile);

        // Update main Varnish config to include this site
        $this->updateMainVarnishConfig($vclPath);
    }

    /**
     * Update main Varnish configuration
     *
     * @param string $vclPath
     * @return void
     * @throws SSHError
     */
    private function updateMainVarnishConfig(string $vclPath): void
    {
        $ssh = $this->site->server->ssh();
        
        // Check if include exists
        $checkInclude = $ssh->exec("sudo grep -q '$vclPath' /etc/varnish/default.vcl && echo 'exists' || echo 'not-exists'");
        
        if (trim($checkInclude) === 'not-exists') {
            // Add include statement
            $ssh->exec("sudo sed -i '1i include \"$vclPath\";' /etc/varnish/default.vcl");
        }
    }

    /**
     * Update web server configuration to listen on backend port
     *
     * @param int $backendPort
     * @return void
     */
    private function updateWebServerConfig(int $backendPort): void
    {
        $webserver = $this->site->webserver();

        if ($webserver->id() === 'nginx') {
            // Update Nginx to listen on backend port
            $this->updateNginxConfig($backendPort);
            return;
        }

        throw new RuntimeException('Unsupported webserver: ' . $webserver->id());
    }

    /**
     * Update Nginx configuration
     *
     * @param int $backendPort
     * @return void
     * @throws SSHError
     */
    private function updateNginxConfig(int $backendPort): void
    {
        $ssh = $this->site->server->ssh();
        $domain = $this->site->domain;
        
        // Update listen directives
        $ssh->exec("sudo sed -i 's/listen 80;/listen $backendPort;/' /etc/nginx/sites-available/$domain");
        $ssh->exec("sudo sed -i 's/listen \\[\\:\\:\\]:80;/listen [::]:$backendPort;/' /etc/nginx/sites-available/$domain");
        
        // If SSL is enabled, update those too
        $ssh->exec("sudo sed -i 's/listen 443 ssl;/listen " . ($backendPort + 363) . " ssl;/' /etc/nginx/sites-available/$domain");
        $ssh->exec("sudo sed -i 's/listen \\[\\:\\:\\]:443 ssl;/listen [::]:". ($backendPort + 363) . " ssl;/' /etc/nginx/sites-available/$domain");
        
        // Reload Nginx
        $ssh->exec('sudo systemctl reload nginx');
    }

    /**
     * Configure Varnish service
     *
     * @param string $memory
     * @return void
     * @throws SSHError
     */
    private function configureVarnishService(string $memory): void
    {
        $ssh = $this->site->server->ssh();

        // Configure Varnish to listen on ports 80 and 443
        $varnishConfig = <<<CONFIG
VARNISH_LISTEN_PORT=80
VARNISH_ADMIN_LISTEN_ADDRESS=127.0.0.1
VARNISH_ADMIN_LISTEN_PORT=6082
VARNISH_SECRET_FILE=/etc/varnish/secret
VARNISH_STORAGE="malloc,$memory"
VARNISH_TTL=300
CONFIG;

        // Write config
        $tempFile = tempnam(sys_get_temp_dir(), 'varnish');
        file_put_contents($tempFile, $varnishConfig);
        $ssh->upload($tempFile, '/tmp/varnish.conf');
        $ssh->exec('sudo mv /tmp/varnish.conf /etc/varnish/varnish.params');
        $ssh->exec('sudo chmod 644 /etc/varnish/varnish.params');
        unlink($tempFile);

        // Update systemd service file
        $serviceConfig = <<<SERVICE
[Unit]
Description=Varnish Cache
After=network.target

[Service]
Type=forking
ExecStart=/usr/sbin/varnishd -a :80 -a :443,PROXY -T 127.0.0.1:6082 -f /etc/varnish/default.vcl -s malloc,$memory
ExecReload=/usr/sbin/varnishreload
PIDFile=/run/varnish.pid

[Install]
WantedBy=multi-user.target
SERVICE;

        $tempFile = tempnam(sys_get_temp_dir(), 'service');
        file_put_contents($tempFile, $serviceConfig);
        $ssh->upload($tempFile, '/tmp/varnish.service');
        $ssh->exec('sudo mv /tmp/varnish.service /etc/systemd/system/varnish.service');
        $ssh->exec('sudo chmod 644 /etc/systemd/system/varnish.service');
        unlink($tempFile);

        // Reload systemd
        $ssh->exec('sudo systemctl daemon-reload');
    }
}
