# Varnish Cache Plugin v2.0.1

## 🐛 Bug Fixes & Improvements

This release fixes critical issues from v2.0.0 and improves the architecture.

## ✨ What's Fixed

### Critical Fixes
- **✅ Site Type Registration Fixed** - Plugin now properly registers for php, laravel, wordpress, and php-blank site types
- **✅ Port Conflict Resolution** - Varnish now uses port 6081 internally, Nginx remains on 80/443
- **✅ No Service Disruption** - Works alongside Redis (6379), MySQL, and other services without conflicts

### Architecture Improvements
- **🔧 Better Nginx Integration** - Uses include files instead of direct config modification
- **♻️ Cleaner Rollback** - Backup and restore functionality improved
- **📁 Separate Config Files** - Varnish config stored in `/etc/nginx/varnish.d/`
- **🛡️ Less Invasive** - Original Nginx config preserved with backup

## 🏗️ New Architecture

```
Client Request → Nginx (80/443) → Varnish (6081) → Nginx → PHP/App
```

**Benefits**:
- Nginx stays on standard ports (80/443)
- Varnish on internal port (6081)
- No conflicts with existing services
- Easy to enable/disable per site

## 📋 Installation

Same as before - via VitoDeploy UI:

1. Navigate to **Admin → Plugins**
2. Click **Install Plugin**
3. Enter: `https://github.com/lifelessrasel/varnish-cache-plugin`
4. Install and Enable

## 🎯 Usage

Navigate to your site → **Features** tab → Enable **Varnish Cache**

The plugin settings appear at: `/servers/{server-id}/sites/{site-id}/features`

## ⚙️ Configuration

### Port Usage
- **Varnish**: Port 6081 (internal)
- **Nginx**: Ports 80/443 (public)
- **No conflicts** with Redis, MySQL, etc.

### Default Settings
- **Cache TTL**: 300 seconds (5 minutes)
- **Cache Memory**: 256M

## 🔄 Upgrading from v2.0.0

If you installed v2.0.0:

1. Disable Varnish on all sites
2. Update the plugin
3. Re-enable Varnish with new settings

The new version is backward compatible but uses a different architecture.

## 📊 Default Configuration
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
