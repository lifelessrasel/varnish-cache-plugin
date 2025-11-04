<?php

namespace App\Vito\Plugins\Lifelessrasel\VarnishCachePlugin\Actions;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Exceptions\SSHError;
use App\SiteFeatures\Action;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Purge Varnish Cache Action
 * 
 * This action allows purging (clearing) the Varnish cache for a site.
 * You can purge the entire cache or specific URLs.
 * 
 * Flow:
 * 1. Check if Varnish is enabled
 * 2. Send PURGE request to Varnish
 * 3. Clear cache for specified pattern or entire site
 */
class PurgeCache extends Action
{
    /**
     * Action name displayed in UI
     *
     * @return string
     */
    public function name(): string
    {
        return 'Purge Cache';
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
     * Define the form fields for purging cache
     *
     * @return DynamicForm|null
     */
    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('info')
                ->alert()
                ->options(['type' => 'info'])
                ->description('Purge the Varnish cache for this site. Leave URL pattern empty to purge all cached content.'),
            DynamicField::make('url_pattern')
                ->text()
                ->label('URL Pattern (Optional)')
                ->placeholder('/blog/.*')
                ->description('Regex pattern to match URLs to purge. Leave empty to purge everything for this site.'),
            DynamicField::make('purge_type')
                ->select()
                ->label('Purge Type')
                ->options([
                    'all' => 'All Content',
                    'pattern' => 'URL Pattern',
                    'single' => 'Single URL',
                ])
                ->default('all')
                ->description('Choose what to purge'),
            DynamicField::make('single_url')
                ->text()
                ->label('Single URL')
                ->placeholder('/specific-page')
                ->description('Specific URL to purge (only if purge type is "Single URL")'),
        ]);
    }

    /**
     * Handle the purge cache action
     *
     * @param Request $request
     * @return void
     * @throws SSHError
     */
    public function handle(Request $request): void
    {
        // Validate input
        Validator::make($request->all(), [
            'purge_type' => 'required|in:all,pattern,single',
            'url_pattern' => 'nullable|string',
            'single_url' => 'nullable|string',
        ])->validate();

        $purgeType = $request->input('purge_type', 'all');
        $urlPattern = $request->input('url_pattern', '');
        $singleUrl = $request->input('single_url', '');

        $domain = $this->site->domain;
        $ssh = $this->site->server->ssh();

        switch ($purgeType) {
            case 'all':
                $this->purgeAllCache($ssh, $domain);
                $message = 'All cached content has been purged for this site.';
                break;

            case 'pattern':
                if (empty($urlPattern)) {
                    $request->session()->flash('error', 'URL pattern is required for pattern purge.');
                    return;
                }
                $this->purgeByPattern($ssh, $domain, $urlPattern);
                $message = "Cache purged for pattern: {$urlPattern}";
                break;

            case 'single':
                if (empty($singleUrl)) {
                    $request->session()->flash('error', 'URL is required for single URL purge.');
                    return;
                }
                $this->purgeSingleUrl($ssh, $domain, $singleUrl);
                $message = "Cache purged for URL: {$singleUrl}";
                break;

            default:
                $request->session()->flash('error', 'Invalid purge type.');
                return;
        }

        $request->session()->flash('success', $message);
    }

    /**
     * Purge all cached content for the site
     *
     * @param $ssh
     * @param string $domain
     * @return void
     * @throws SSHError
     */
    private function purgeAllCache($ssh, string $domain): void
    {
        // Use varnishadm to ban all content for this domain
        $command = "sudo varnishadm 'ban req.http.host == {$domain}'";
        
        try {
            $ssh->exec($command, 'purge-all-varnish-cache');
        } catch (\Exception $e) {
            // If varnishadm fails, try using curl to send PURGE requests
            $this->purgeViaHttp($ssh, $domain, '.*');
        }
    }

    /**
     * Purge cache by URL pattern
     *
     * @param $ssh
     * @param string $domain
     * @param string $pattern
     * @return void
     * @throws SSHError
     */
    private function purgeByPattern($ssh, string $domain, string $pattern): void
    {
        // Escape special characters for shell
        $escapedPattern = addslashes($pattern);
        
        // Use varnishadm to ban matching URLs
        $command = "sudo varnishadm 'ban req.http.host == {$domain} && req.url ~ {$escapedPattern}'";
        
        try {
            $ssh->exec($command, 'purge-pattern-varnish-cache');
        } catch (\Exception $e) {
            // If varnishadm fails, try using curl
            $this->purgeViaHttp($ssh, $domain, $pattern);
        }
    }

    /**
     * Purge cache for a single URL
     *
     * @param $ssh
     * @param string $domain
     * @param string $url
     * @return void
     * @throws SSHError
     */
    private function purgeSingleUrl($ssh, string $domain, string $url): void
    {
        // Ensure URL starts with /
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }

        // Send PURGE request via curl
        $command = "curl -X PURGE -H 'Host: {$domain}' http://localhost{$url}";
        
        try {
            $ssh->exec($command, 'purge-single-url-varnish-cache');
        } catch (\Exception $e) {
            // Try alternative method using varnishadm
            $escapedUrl = addslashes($url);
            $altCommand = "sudo varnishadm 'ban req.http.host == {$domain} && req.url == {$escapedUrl}'";
            $ssh->exec($altCommand, 'purge-single-url-varnish-cache-alt');
        }
    }

    /**
     * Purge cache via HTTP PURGE method
     *
     * @param $ssh
     * @param string $domain
     * @param string $pattern
     * @return void
     * @throws SSHError
     */
    private function purgeViaHttp($ssh, string $domain, string $pattern): void
    {
        // Send PURGE request with X-Purge-Regex header
        $command = "curl -X PURGE -H 'Host: {$domain}' -H 'X-Purge-Regex: {$pattern}' http://localhost/";
        $ssh->exec($command, 'purge-varnish-via-http');
    }
}
