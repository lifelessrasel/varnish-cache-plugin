# Varnish Cache Plugin v2.0.0

## 🎉 Major Release - VitoDeploy 3.x Support

This is a complete rewrite of the Varnish Cache Plugin with full support for VitoDeploy 3.x and many new features!

## ✨ What's New

### Core Features
- **✅ VitoDeploy 3.x Compatibility** - Completely rewritten to work with the latest VitoDeploy
- **🎯 Per-Site Configuration** - Enable Varnish on a per-website basis with individual settings
- **🧹 Enhanced Cache Purging** - Three purge modes: all content, URL patterns, or single URLs
- **⚡ Smart VCL Defaults** - Intelligent caching rules that work out of the box
- **🔧 Automatic Setup** - Installs and configures Varnish automatically
- **🌐 Multi-Domain Support** - Works with domain aliases seamlessly

### Improvements
- **WordPress Optimization** - Automatic handling of WordPress cookies and logged-in users
- **Cache Monitoring** - Added `X-Cache` headers to monitor cache hits/misses
- **Better Error Handling** - More robust error handling and recovery
- **Comprehensive Documentation** - Detailed README with troubleshooting guide
- **Flexible Configuration** - Customize cache TTL, memory allocation, and backend ports

## 📋 Installation

### Via VitoDeploy UI (Recommended)

1. Navigate to **Admin → Plugins** in VitoDeploy
2. Click **Install Plugin**
3. Enter: `https://github.com/lifelessrasel/varnish-cache-plugin`
4. Click **Install** and then **Enable**

### Manual Installation

```bash
cd /path/to/vitodeploy/app/Vito/Plugins
git clone https://github.com/lifelessrasel/varnish-cache-plugin.git Lifelessrasel/VarnishCachePlugin
```

Then discover and enable the plugin in VitoDeploy UI.

## 🚀 Quick Start

1. Install and enable the plugin
2. Go to any site → **Features** tab
3. Find **Varnish Cache** and click **Enable**
4. Configure settings (or use defaults)
5. Save and enjoy blazing fast performance!

## 📊 Default Configuration

- **Backend Port**: 8080 (web server listens here)
- **Cache TTL**: 300 seconds (5 minutes)
- **Cache Memory**: 256M
- **Varnish Ports**: 80 and 443 (public access)

## 🎯 Cache Behavior

### Cached Automatically
✅ GET and HEAD requests  
✅ Static files (images, CSS, JS, fonts)  
✅ Unauthenticated requests  
✅ Public pages  

### Not Cached
❌ POST, PUT, DELETE requests  
❌ Authenticated users  
❌ Pages with cookies  
❌ Error responses (4xx, 5xx)  

## 📈 Performance

After enabling Varnish, you should see:
- **10-100x faster** page load times for cached content
- Reduced server load
- Better ability to handle traffic spikes
- Improved SEO rankings due to faster load times

## 🔄 Upgrading from v1.x

This is a **breaking change**. If you're using v1.x:

1. Disable Varnish on all sites using the old plugin
2. Uninstall the v1.x plugin
3. Install v2.0.0
4. Re-enable Varnish on your sites with the new configuration

## ⚠️ Important Notes

- Always test on staging first
- Backup your site before enabling
- Ensure your server has adequate RAM (recommend 512MB+ for Varnish)
- Monitor cache hit rates after enabling
- Some dynamic content may need cache exclusions

## 🐛 Bug Fixes

- Fixed compatibility issues with VitoDeploy 3.x
- Fixed port conflicts when multiple sites use Varnish
- Improved VCL syntax for better compatibility
- Fixed cache purging not working in some scenarios

## 📚 Documentation

Full documentation available in the [README.md](https://github.com/lifelessrasel/varnish-cache-plugin/blob/master/README.md)

## 🤝 Contributing

Contributions welcome! Please open issues or pull requests on GitHub.

## 📄 License

MIT License - See [LICENSE](https://github.com/lifelessrasel/varnish-cache-plugin/blob/master/LICENSE)

## 👨‍💻 Author

**Lifeless Rasel**  
GitHub: [@lifelessrasel](https://github.com/lifelessrasel)

---

**Full Changelog**: https://github.com/lifelessrasel/varnish-cache-plugin/compare/v1.1.0...v2.0.0
