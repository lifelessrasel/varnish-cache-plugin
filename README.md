# Varnish Cache Plugin for VitoDeploy

[![Latest Version](https://img.shields.io/github/v/release/lifelessrasel/varnish-cache-plugin)](https://github.com/lifelessrasel/varnish-cache-plugin/releases)
[![License](https://img.shields.io/github/license/lifelessrasel/varnish-cache-plugin)](LICENSE)

A powerful Varnish HTTP accelerator plugin for VitoDeploy that enables blazing-fast website performance through intelligent reverse proxy caching.

## 🚀 Features

- **🔥 High-Performance Caching**: Leverage Varnish as a reverse proxy to dramatically improve website load times
- **🎯 Per-Site Configuration**: Enable/disable Varnish cache on a per-website basis
- **🌐 Multi-Site & Multi-Domain Support**: 
  - Works with unlimited websites on the same server
  - Each site has independent Varnish configuration
  - Domain aliases supported for each site
  - All sites share one Varnish instance (efficient resource usage)
- **⚙️ Flexible Configuration**: Customize cache TTL, memory allocation, and backend ports
- **🧹 Cache Management**: Purge entire cache, specific URL patterns, or individual pages
- **🔒 Smart Caching Rules**: 
  - Automatic bypass for authenticated users
  - Static file optimization
  - Intelligent cookie handling
  - HTTP method awareness (GET/HEAD cached, POST/PUT/DELETE bypassed)
- **📊 Cache Status Headers**: Monitor cache hits and misses via response headers
- **🌐 Multi-Domain Support**: Works with domain aliases
- **🔧 Automatic Installation**: Installs and configures Varnish automatically
- **♻️ Seamless Integration**: Works with Nginx web server out of the box

## 📋 Requirements

- VitoDeploy 3.x
- Ubuntu/Debian-based server
- Nginx web server
- Root/sudo access on the server

## 📦 Installation

### Via VitoDeploy UI (Recommended)

1. Navigate to **Admin → Plugins** in your VitoDeploy dashboard
2. Click on **Install Plugin**
3. Enter the plugin URL:
   ```
   https://github.com/lifelessrasel/varnish-cache-plugin
   ```
4. Click **Install**
5. Enable the plugin once installed

### Manual Installation

1. SSH into your VitoDeploy server
2. Navigate to the plugins directory:
   ```bash
   cd /path/to/vitodeploy/app/Vito/Plugins
   ```
3. Clone the repository:
   ```bash
   git clone https://github.com/lifelessrasel/varnish-cache-plugin.git Lifelessrasel/VarnishCachePlugin
   ```
4. Go to **Admin → Plugins** in VitoDeploy UI
5. Click **Discover Plugins**
6. Install and enable the plugin

## 🎯 Usage

### Enabling Varnish for a Site

1. Navigate to your site in VitoDeploy (e.g., `/servers/1/sites/1`)
2. Go to the **Features** tab
3. Find **Varnish Cache** in the available features
4. Click **Enable**
5. Configure the settings:
   - **Cache TTL**: Default cache time-to-live in seconds (default: 300)
   - **Cache Memory**: Memory allocation for Varnish (e.g., 256M, 1G)
6. Click **Save**

**How it works**:
- Varnish runs on port **6081** (doesn't conflict with your existing services)
- Nginx remains on ports **80** and **443**
- Nginx proxies requests through Varnish for caching
- All traffic flows: **Client → Nginx (80/443) → Varnish (6081) → Nginx → PHP/App**
- No port conflicts with Redis (6379) or other services

### Disabling Varnish for a Site

1. Navigate to your site
2. Go to **Features** → **Varnish Cache**
3. Click **Disable**
4. Confirm the action

Your web server will automatically be restored to listen on ports 80 and 443.

### Using Varnish with Multiple Sites

**Yes! The plugin supports multiple websites on the same server.**

#### How It Works:

1. **One Varnish instance** runs on port 6081 for the entire server
2. **Each site** can enable/disable Varnish independently
3. **Each site** has its own VCL configuration file in `/etc/varnish/sites/`
4. **Varnish routes** requests based on the `Host` header

#### Example Setup:

- **Site 1** (example.com): Varnish enabled, 5-minute cache
- **Site 2** (myapp.com): Varnish enabled, 10-minute cache  
- **Site 3** (test.com): Varnish disabled (direct Nginx)

All work together on the same server without conflicts!

#### Benefits:

- ✅ Shared Varnish memory pool (efficient)
- ✅ Independent cache rules per domain
- ✅ Easy to enable/disable per site
- ✅ No port conflicts between sites

### Purging Cache

You have three options to purge cache:

#### 1. Purge All Content
Clears all cached content for the site.

1. Navigate to your site
2. Go to **Features** → **Varnish Cache**
3. Click **Purge Cache**
4. Select **All Content**
5. Click **Purge**

#### 2. Purge URL Pattern
Clears cache for URLs matching a specific pattern.

1. Click **Purge Cache**
2. Select **URL Pattern**
3. Enter a regex pattern (e.g., `/blog/.*` for all blog pages)
4. Click **Purge**

#### 3. Purge Single URL
Clears cache for a specific URL.

1. Click **Purge Cache**
2. Select **Single URL**
3. Enter the URL path (e.g., `/about`)
4. Click **Purge**

## ⚙️ Configuration

### Port Configuration

- **Varnish**: Runs on port 6081 (internal only)
- **Nginx**: Remains on ports 80/443 (public access)
- **No conflicts**: Works alongside Redis (6379), MySQL, and other services

### How Varnish Works in This Setup

```
Internet → Nginx (80/443) → Varnish (6081) → Back to Nginx → PHP/Application
```

1. Request arrives at Nginx on port 80/443
2. Nginx proxies to Varnish on port 6081
3. Varnish checks cache:
   - **Cache HIT**: Returns cached content immediately
   - **Cache MISS**: Varnish requests from Nginx (without proxy)
4. Nginx processes PHP/static files
5. Response cached by Varnish and sent to client

### Default Varnish Behavior

The plugin configures Varnish with intelligent defaults:

**Cached:**
- GET and HEAD requests
- Static files (CSS, JS, images, fonts)
- Unauthenticated requests
- Responses without Set-Cookie headers

**Not Cached:**
- POST, PUT, DELETE requests
- Requests with Authorization header
- WordPress logged-in users
- Requests with authentication cookies
- Responses with error status codes (4xx, 5xx)

### Cache Headers

The plugin adds helpful headers to responses:

- `X-Cache: HIT` - Content served from cache
- `X-Cache: MISS` - Content fetched from backend
- `X-Cache-Hits` - Number of times this object was served from cache

### VCL Configuration

The plugin generates a custom VCL (Varnish Configuration Language) file for each site at:
```
/etc/varnish/sites/{domain}.vcl
```

You can manually edit this file if you need custom caching rules.

## 🔧 Advanced Configuration

### Custom Cache Rules

To add custom caching rules, you can edit the VCL file:

```bash
sudo nano /etc/varnish/sites/yourdomain.com.vcl
```

After editing, reload Varnish:
```bash
sudo systemctl reload varnish
```

### WordPress Optimization

For WordPress sites, the plugin automatically:
- Bypasses cache for logged-in users
- Excludes admin areas
- Handles comment cookies correctly
- Caches static assets aggressively

### Adjusting Cache TTL

You can set different TTLs for different content types by editing the VCL file:

```vcl
sub vcl_backend_response {
    # Cache HTML for 5 minutes
    if (bereq.url ~ "\.(html)$") {
        set beresp.ttl = 300s;
    }
    
    # Cache images for 1 day
    if (bereq.url ~ "\.(jpg|jpeg|png|gif|webp)$") {
        set beresp.ttl = 86400s;
    }
}
```

## 🐛 Troubleshooting

### APT Repository Errors During Installation

If you see errors like `GPG error` or `The repository is not signed` when enabling Varnish:

```bash
# Fix expired MySQL GPG key
sudo apt-key adv --keyserver keyserver.ubuntu.com --recv-keys B7B3B788A8D3785C

# Or remove problematic repo temporarily
sudo mv /etc/apt/sources.list.d/mysql.list /etc/apt/sources.list.d/mysql.list.disabled

# Update and retry
sudo apt-get update --allow-releaseinfo-change -y
```

The plugin will automatically try to install Varnish from official repos if the default installation fails.

### Varnish Not Starting

Check Varnish status:
```bash
sudo systemctl status varnish
```

View Varnish logs:
```bash
sudo journalctl -u varnish -f
```

### Cache Not Working

1. Check if Varnish is listening on port 80:
   ```bash
   sudo netstat -tlnp | grep :80
   ```

2. Test cache headers:
   ```bash
   curl -I https://yourdomain.com
   ```
   Look for `X-Cache` header

3. Check VCL syntax:
   ```bash
   sudo varnishd -C -f /etc/varnish/default.vcl
   ```

### Site Not Accessible After Enabling Varnish

1. Check if backend port is open:
   ```bash
   sudo netstat -tlnp | grep :8080
   ```

2. Verify Nginx configuration:
   ```bash
   sudo nginx -t
   ```

3. Check Nginx error logs:
   ```bash
   sudo tail -f /var/log/nginx/error.log
   ```

### Purge Not Working

Ensure you're purging from localhost:
```bash
curl -X PURGE -H "Host: yourdomain.com" http://localhost/path
```

## 📊 Performance Tips

1. **Increase Cache Memory**: Allocate more memory for better cache hit rates
2. **Longer TTL for Static Files**: Set aggressive caching for images, CSS, JS
3. **Use CDN with Varnish**: Combine with CloudFlare or similar for even better performance
4. **Monitor Cache Hit Ratio**: Aim for >80% cache hit rate
5. **Implement Cache Warming**: Pre-load important pages after purging

## 🔄 Updating

### Via VitoDeploy UI

1. Navigate to **Admin → Plugins**
2. Find **Varnish Cache Plugin**
3. Click **Update** if an update is available

### Manual Update

```bash
cd /path/to/vitodeploy/app/Vito/Plugins/Lifelessrasel/VarnishCachePlugin
git pull origin master
```

Then go to **Admin → Plugins** in VitoDeploy and click **Reload**.

## 📝 Changelog

### Version 2.0.1 (2025-11-05)
- **Fixed**: Site type registration - now works with php, laravel, wordpress, php-blank sites
- **Fixed**: Port architecture - Varnish uses port 6081, Nginx stays on 80/443
- **Fixed**: No port conflicts with Redis (6379) or other services
- **Improved**: Cleaner Nginx configuration using include files
- **Improved**: Better error handling and backup/restore functionality

### Version 2.0.0 (2025-11-05)
- Complete rewrite for VitoDeploy 3.x compatibility
- Added per-site Varnish configuration
- Enhanced cache purging options (all/pattern/single URL)
- Improved VCL configuration with smart defaults
- Better WordPress support
- Added cache status headers
- Automatic installation and configuration
- Multi-domain support
- Enhanced error handling

### Version 1.1.0
- Initial release for VitoDeploy 2.x

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📄 License

This project is open-source and available under the MIT License.

## 👨‍💻 Author

**Lifeless Rasel**
- GitHub: [@lifelessrasel](https://github.com/lifelessrasel)

## 🙏 Acknowledgments

- VitoDeploy team for the excellent server management platform
- Varnish Software for the powerful caching solution
- Community contributors and testers

## 📞 Support

If you encounter any issues or have questions:

1. Check the [Troubleshooting](#-troubleshooting) section
2. Open an issue on [GitHub](https://github.com/lifelessrasel/varnish-cache-plugin/issues)
3. Consult the [VitoDeploy documentation](https://vitodeploy.com/docs)

## ⚠️ Important Notes

- Always test on a staging environment first
- Backup your site before enabling Varnish
- Monitor your site after enabling to ensure everything works correctly
- Some dynamic content may need cache exclusions
- Varnish requires memory - ensure your server has adequate RAM

## 🌟 Star History

If you find this plugin useful, please consider giving it a star on GitHub!

---

Made with ❤️ for the VitoDeploy community
