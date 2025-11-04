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

        try {
            // Check if Varnish is installed, install if not
            $this->installVarnish();
        } catch (\Exception $e) {
            throw new \Exception('Failed to install Varnish: ' . $e->getMessage());
        }

        try {
            // Configure Varnish service to run on port 6081
            $this->configureVarnishService($memory);
        } catch (\Exception $e) {
            throw new \Exception('Failed to configure Varnish service: ' . $e->getMessage());
        }

        try {
            // Create Varnish VCL configuration
            $this->createVarnishConfig($cacheTtl);
        } catch (\Exception $e) {
            throw new \Exception('Failed to create Varnish VCL config: ' . $e->getMessage());
        }

        try {
            // Update Nginx to proxy through Varnish
            $this->updateNginxForVarnish();
        } catch (\Exception $e) {
            throw new \Exception('Failed to update Nginx configuration: ' . $e->getMessage());
        }

        // Update site metadata
        $typeData = $this->site->type_data ?? [];
        data_set($typeData, 'varnish_enabled', true);
        data_set($typeData, 'varnish_cache_ttl', $cacheTtl);
        data_set($typeData, 'varnish_memory', $memory);
        $this->site->type_data = $typeData;
        $this->site->save();

        try {
            // Restart services
            $this->site->server->ssh()->exec('sudo systemctl restart varnish');
            $this->site->server->ssh()->exec('sudo systemctl reload nginx');
        } catch (\Exception $e) {
            throw new \Exception('Varnish configured but failed to restart services: ' . $e->getMessage() . '. Please restart manually.');
        }

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
            $result = $ssh->exec('varnishd -V 2>&1 | head -n1');
            if (!empty($result) && strpos($result, 'varnish') !== false) {
                return; // Already installed
            }
        } catch (\Exception $e) {
            // Not installed, proceed with installation
        }

        try {
            // Try simple installation first
            $ssh->exec('sudo DEBIAN_FRONTEND=noninteractive apt-get install -y varnish 2>&1');
            $ssh->exec('sudo systemctl enable varnish 2>&1');
            return;
        } catch (\Exception $e) {
            // If that fails, update repos and try again
        }

        try {
            // Update package lists (ignore errors from bad repos)
            $ssh->exec('sudo apt-get update -o Acquire::AllowInsecureRepositories=true -o Acquire::AllowDowngradeToInsecureRepositories=true 2>&1 || true');
            
            // Try installing again
            $ssh->exec('sudo DEBIAN_FRONTEND=noninteractive apt-get install -y varnish 2>&1');
            $ssh->exec('sudo systemctl enable varnish 2>&1');
        } catch (\Exception $e) {
            // Last resort: install from Varnish official repos
            $this->installVarnishFromOfficialRepo($ssh);
        }
    }
    
    /**
     * Install Varnish from official repository as fallback
     *
     * @param $ssh
     * @return void
     * @throws SSHError
     */
    private function installVarnishFromOfficialRepo($ssh): void
    {
        // Install prerequisites
        $ssh->exec('sudo apt-get install -y curl gnupg apt-transport-https 2>&1 || true');
        
        // Add Varnish official repository
        $commands = [
            'curl -fsSL https://packagecloud.io/varnishcache/varnish70/gpgkey | sudo gpg --dearmor -o /etc/apt/trusted.gpg.d/varnish.gpg',
            'echo "deb https://packagecloud.io/varnishcache/varnish70/ubuntu/ $(lsb_release -cs) main" | sudo tee /etc/apt/sources.list.d/varnish.list',
            'sudo apt-get update -y 2>&1 || true',
            'sudo apt-get install -y varnish 2>&1'
        ];
        
        foreach ($commands as $command) {
            try {
                $ssh->exec($command);
            } catch (\Exception $e) {
                // Continue with next command
            }
        }
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
        $ssh = $this->site->server->ssh();
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

        // Write VCL to server using cat with heredoc
        $vclPath = "/etc/varnish/sites/{$domain}.vcl";
        $ssh->exec("sudo mkdir -p /etc/varnish/sites");
        
        // Escape single quotes and backslashes in VCL content
        $vclEscaped = str_replace("'", "'\\''", $vcl);
        
        // Write VCL file using cat with heredoc (single quotes prevent variable expansion)
        $ssh->exec("sudo bash -c 'cat > $vclPath <<'\"'\"'VCLEOF'\"'\"'
$vclEscaped
VCLEOF'");
        $ssh->exec("sudo chmod 644 $vclPath");

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
     * This creates a separate config file that Nginx includes
     * Uses echo instead of file upload for better reliability
     *
     * @return void
     * @throws SSHError
     */
    private function updateNginxForVarnish(): void
    {
        $ssh = $this->site->server->ssh();
        $domain = $this->site->domain;
        $varnishConfigPath = "/etc/nginx/varnish.d/{$domain}.conf";
        
        // Create varnish.d directory if it doesn't exist
        $ssh->exec("sudo mkdir -p /etc/nginx/varnish.d");
        
        // Create Varnish proxy configuration
        // This proxies all requests to Varnish on port 6081
        // Use cat with heredoc for reliable writing
        $ssh->exec("sudo bash -c 'cat > $varnishConfigPath <<\"EOF\"
# Varnish Cache Proxy Configuration
# All requests are proxied to Varnish on port 6081

proxy_pass http://127.0.0.1:6081;
proxy_set_header Host \$host;
proxy_set_header X-Real-IP \$remote_addr;
proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto \$scheme;
proxy_set_header X-Forwarded-Port \$server_port;
proxy_redirect off;

# Buffer settings
proxy_buffering on;
proxy_buffer_size 4k;
proxy_buffers 24 4k;
proxy_busy_buffers_size 8k;
proxy_max_temp_file_size 2048m;
proxy_temp_file_write_size 32k;
EOF'");
        $ssh->exec("sudo chmod 644 $varnishConfigPath");

        // Now update the main Nginx config to include this file in location /
        $this->includeVarnishInNginx($ssh, $domain, $varnishConfigPath);
    }
    
    /**
     * Include Varnish configuration in the main Nginx site config
     * Uses SSH commands instead of file download/upload for better reliability
     *
     * @param $ssh
     * @param string $domain
     * @param string $varnishConfigPath
     * @return void
     * @throws SSHError
     */
    private function includeVarnishInNginx($ssh, string $domain, string $varnishConfigPath): void
    {
        $configPath = "/etc/nginx/sites-available/$domain";
        
        // Check if config file exists
        try {
            $ssh->exec("test -f $configPath");
        } catch (\Exception $e) {
            throw new \Exception("Nginx configuration file not found at $configPath");
        }
        
        // Check if already included
        try {
            $checkInclude = $ssh->exec("grep -q '# VARNISH_INCLUDE_START' $configPath && echo 'exists' || echo 'not-exists'");
            if (trim($checkInclude) === 'exists') {
                return; // Already configured
            }
        } catch (\Exception $e) {
            // Continue with installation
        }
        
        // Backup original config
        try {
            $ssh->exec("sudo cp $configPath {$configPath}.varnish-backup");
        } catch (\Exception $e) {
            throw new \Exception("Failed to backup Nginx configuration");
        }
        
        // Use sed to insert the include directive after "location / {"
        // This is more reliable than downloading/uploading files
        $includeBlock = "# VARNISH_INCLUDE_START\\n        include $varnishConfigPath;\\n        # VARNISH_INCLUDE_END";
        
        try {
            // Use sed to insert after the first "location / {" line
            $sedCommand = "sudo sed -i '0,/location[[:space:]]\+\/[[:space:]]*{/s|location[[:space:]]\+\/[[:space:]]*{|&\\n        $includeBlock|' $configPath";
            $ssh->exec($sedCommand);
            
            // Test nginx configuration
            $testResult = $ssh->exec("sudo nginx -t 2>&1");
            if (strpos($testResult, 'test failed') !== false || strpos(strtolower($testResult), 'emerg') !== false) {
                // Restore backup if test fails
                $ssh->exec("sudo cp {$configPath}.varnish-backup $configPath");
                throw new \Exception("Nginx configuration test failed: $testResult");
            }
            
        } catch (\Exception $e) {
            // Restore backup on any error
            try {
                $ssh->exec("sudo cp {$configPath}.varnish-backup $configPath 2>&1 || true");
            } catch (\Exception $restoreError) {
                // Ignore restore errors
            }
            throw new \Exception("Failed to update Nginx config: " . $e->getMessage());
        }
    }

    /**
     * Configure Varnish service to run on port 6081
     * Uses cat with heredoc for reliable multi-line file writing
     *
     * @param string $memory
     * @return void
     * @throws SSHError
     */
    private function configureVarnishService(string $memory): void
    {
        $ssh = $this->site->server->ssh();

        // Configure Varnish to listen on port 6081 (not 80/443)
        // Use cat with heredoc for reliable writing
        $ssh->exec("sudo bash -c 'cat > /etc/varnish/varnish.params <<\"EOF\"
VARNISH_LISTEN_PORT=6081
VARNISH_ADMIN_LISTEN_ADDRESS=127.0.0.1
VARNISH_ADMIN_LISTEN_PORT=6082
VARNISH_SECRET_FILE=/etc/varnish/secret
VARNISH_STORAGE=\"malloc,$memory\"
VARNISH_TTL=300
EOF'");
        $ssh->exec('sudo chmod 644 /etc/varnish/varnish.params');

        // Update systemd service file - Varnish listens on 6081
        // Use cat with heredoc for reliable writing
        $ssh->exec("sudo bash -c 'cat > /etc/systemd/system/varnish.service <<\"EOF\"
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
EOF'");
        $ssh->exec('sudo chmod 644 /etc/systemd/system/varnish.service');

        // Reload systemd
        $ssh->exec('sudo systemctl daemon-reload');
    }
}
