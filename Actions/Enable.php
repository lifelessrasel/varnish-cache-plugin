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
 * This action installs Varnish (if not already installed) and configures Nginx
 * to proxy requests through Varnish. Nginx remains on ports 80/443, and Varnish
 * runs on port 6081.
 * 
 * Flow:
 * 1. Check if Varnish is already enabled
 * 2. Install Varnish if not present on port 6081
 * 3. Create VCL configuration for the site
 * 4. Update Nginx to proxy_pass through Varnish
 * 5. Restart services
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
                ->description('Varnish will be installed on port 6081. Nginx will proxy requests through Varnish for caching.'),
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
            'cache_ttl' => 'required|integer|min:0',
            'memory' => 'required|string',
        ])->validate();

        $cacheTtl = $request->input('cache_ttl', 300);
        $memory = $request->input('memory', '256M');

        // Check if Varnish is installed, install if not
        $this->installVarnish();

        // Configure Varnish service to run on port 6081
        $this->configureVarnishService($memory);

        // Create Varnish VCL configuration
        $this->createVarnishConfig($cacheTtl);

        // Update Nginx to proxy through Varnish
        $this->updateNginxForVarnish();

        // Update site metadata
        $typeData = $this->site->type_data ?? [];
        data_set($typeData, 'varnish_enabled', true);
        data_set($typeData, 'varnish_cache_ttl', $cacheTtl);
        data_set($typeData, 'varnish_memory', $memory);
        $this->site->type_data = $typeData;
        $this->site->save();

        // Restart services
        $this->site->server->ssh()->exec('sudo systemctl restart varnish');
        $this->site->server->ssh()->exec('sudo systemctl reload nginx');

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
     * @param int $cacheTtl
     * @return void
     * @throws SSHError
     */
    private function createVarnishConfig(int $cacheTtl): void
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

        // Varnish will listen on 6081 and proxy to Nginx on 80
        $vcl = <<<VCL
vcl 4.1;

import std;

# Backend configuration - points to localhost:80 where Nginx is running
backend default {
    .host = "127.0.0.1";
    .port = "80";
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
     * Update main Varnish configuration to include site VCL
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
     * Update Nginx configuration to proxy through Varnish
     *
     * @return void
     * @throws SSHError
     */
    private function updateNginxForVarnish(): void
    {
        $ssh = $this->site->server->ssh();
        $domain = $this->site->domain;
        $configPath = "/etc/nginx/sites-available/$domain";
        
        // Create a location block that will be prepended to the existing config
        // This will catch all requests and proxy them to Varnish on port 6081
        $varnishLocation = <<<'NGINX'

    # Varnish Cache Proxy
    location / {
        proxy_pass http://127.0.0.1:6081;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
        
        # Cache status headers
        add_header X-Cache-Status $upstream_cache_status;
        
        # Try files is handled by the original location block after Varnish
        try_files $uri $uri/ @varnish;
    }
    
    location @varnish {
        proxy_pass http://127.0.0.1:6081;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

NGINX;

        // Add marker to the nginx config so we can find and remove it later
        $markerStart = "# VARNISH_CACHE_START";
        $markerEnd = "# VARNISH_CACHE_END";
        
        // Check if Varnish config already exists
        $checkMarker = $ssh->exec("sudo grep -q '$markerStart' $configPath && echo 'exists' || echo 'not-exists'");
        
        if (trim($checkMarker) === 'not-exists') {
            // Backup original config
            $ssh->exec("sudo cp $configPath {$configPath}.backup");
            
            // Insert Varnish proxy config before the first location block
            $insertCommand = <<<BASH
sudo sed -i '/location \//i\\
$markerStart\\
$varnishLocation\\
$markerEnd' $configPath
BASH;
            
            try {
                $ssh->exec($insertCommand);
            } catch (\Exception $e) {
                // Alternative method: read, modify, write
                $this->insertVarnishConfigAlternative($ssh, $domain, $markerStart, $varnishLocation, $markerEnd);
            }
        }
    }
    
    /**
     * Alternative method to insert Varnish config into Nginx
     *
     * @param $ssh
     * @param string $domain
     * @param string $markerStart
     * @param string $varnishLocation
     * @param string $markerEnd
     * @return void
     * @throws SSHError
     */
    private function insertVarnishConfigAlternative($ssh, string $domain, string $markerStart, string $varnishLocation, string $markerEnd): void
    {
        $configPath = "/etc/nginx/sites-available/$domain";
        
        // Download config
        $tempLocal = tempnam(sys_get_temp_dir(), 'nginx');
        $ssh->download($configPath, $tempLocal);
        
        // Read and modify
        $content = file_get_contents($tempLocal);
        
        // Find first location block and insert before it
        $pattern = '/(location\s+\/\s+{)/';
        $replacement = "$markerStart\n$varnishLocation\n$markerEnd\n$1";
        $content = preg_replace($pattern, $replacement, $content, 1);
        
        // Write back
        file_put_contents($tempLocal, $content);
        $ssh->upload($tempLocal, "/tmp/nginx-$domain.conf");
        $ssh->exec("sudo mv /tmp/nginx-$domain.conf $configPath");
        $ssh->exec("sudo chmod 644 $configPath");
        
        unlink($tempLocal);
    }

    /**
     * Configure Varnish service to run on port 6081
     *
     * @param string $memory
     * @return void
     * @throws SSHError
     */
    private function configureVarnishService(string $memory): void
    {
        $ssh = $this->site->server->ssh();

        // Configure Varnish to listen on port 6081 (not 80/443)
        $varnishConfig = <<<CONFIG
VARNISH_LISTEN_PORT=6081
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

        // Update systemd service file - Varnish listens on 6081
        $serviceConfig = <<<SERVICE
[Unit]
Description=Varnish Cache
After=network.target

[Service]
Type=forking
ExecStart=/usr/sbin/varnishd -a :6081 -T 127.0.0.1:6082 -f /etc/varnish/default.vcl -s malloc,$memory
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
