# Testing Guide - Security Fixed Version

This guide explains how to checkout and test the security-fixed version of af_readability with your FreshRSS installation.

---

## Quick Start

### Option 1: Checkout This Branch (Recommended for Testing)

If you already have this extension installed:

```bash
cd /path/to/FreshRSS/extensions/af_readability

# Checkout the security-fixed branch
git fetch origin
git checkout claude/security-audit-011CULLMXFTtaMdusNbTbfSh

# Dependencies are already included - no composer needed!
# (Optional: only if you want to update dependencies)
# composer install --no-dev
```

### Option 2: Fresh Installation

```bash
cd /path/to/FreshRSS/extensions/

# Clone the repository
git clone https://github.com/jpaechnatz/af-readability-claude.git af_readability

# Checkout the security-fixed branch
cd af_readability
git checkout claude/security-audit-011CULLMXFTtaMdusNbTbfSh

# Dependencies are already included - no composer needed!
# (Optional: only if you want to update dependencies)
# composer install --no-dev
```

### Option 3: Docker Installation

**⚠️ Important for Docker users:** Composer is usually not included in FreshRSS Docker images, but you don't need it! All dependencies are already included in the repository.

See **DOCKER_INSTALLATION.md** for detailed German instructions.

```bash
# If using Docker, exec into the container first
docker exec -it freshrss bash

# Clone or update the extension
cd /usr/share/freshrss/extensions/
git clone https://github.com/jpaechnatz/af-readability-claude.git af_readability
cd af_readability
git checkout claude/security-audit-011CULLMXFTtaMdusNbTbfSh

# Set permissions and exit
chown -R www-data:www-data /usr/share/freshrss/extensions/af_readability
exit
```

---

## Set Proper Permissions

After installation, ensure the web server has proper access:

```bash
# For Apache
chown -R www-data:www-data /path/to/FreshRSS/extensions/af_readability

# For Nginx
chown -R nginx:nginx /path/to/FreshRSS/extensions/af_readability

# Or your specific user
chown -R your-web-user:your-web-group /path/to/FreshRSS/extensions/af_readability
```

---

## Activate the Extension

1. **Log in to FreshRSS** as an administrator
2. Navigate to **Settings → Extensions** (or **Configuration → Extensions**)
3. Find **"Af_Readability"** in the list
4. Click the **toggle switch** to enable it
5. Click **"Submit"** or **"Save"** to apply changes
6. Click the **gear/settings icon** next to the extension
7. **Check the boxes** for feeds where you want full article extraction
8. Click **"Submit"** to save feed selections

---

## Verify Installation

### Check Version

Look at the files to confirm you're on the security-fixed version:

```bash
cd /path/to/FreshRSS/extensions/af_readability
git log --oneline -3
```

You should see:
```
b59015d Add comprehensive security fixes documentation
c848758 Fix all identified security vulnerabilities
88ae3f3 Add comprehensive security audit report
```

### Check Code

Verify security fixes are present:

```bash
grep -n "isUrlSafe" extension.php
# Should show the new security method around line 156

grep -n "CURLOPT_TIMEOUT" extension.php
# Should show timeout settings around line 221-223

grep -n "LIBXML_NONET" extension.php
# Should show XXE protection around line 257
```

---

## Testing Procedure

### 1. Test with Normal Feeds

**Add a legitimate RSS feed:**
- Example: `https://news.ycombinator.com/rss`
- Example: `https://www.theguardian.com/world/rss`
- Example: `https://hnrss.org/newest`

**Expected behavior:**
- Articles should load with full content
- No errors in FreshRSS logs
- Content should display properly

### 2. Monitor Logs

Check FreshRSS logs for security-related messages:

```bash
# Find your FreshRSS log directory
# Common locations:
tail -f /var/log/freshrss/freshrss.log
tail -f /path/to/FreshRSS/data/users/_/log.txt
tail -f /path/to/FreshRSS/data/users/your-username/log.txt

# Look for af-readability messages:
grep "af-readability" /path/to/FreshRSS/data/users/_/log.txt
```

**Normal operation logs:**
- Should see minimal to no errors
- May see warnings for legitimately problematic articles

**Security blocks (these are GOOD):**
- `"af-readability: Blocked unsafe URL"` - SSRF protection working

### 3. Test Security Protections (Optional)

**WARNING: Only test these with feeds you control or in a test environment!**

#### Test SSRF Protection

Create a test feed with malicious URLs pointing to:
- `http://localhost/test` - Should be blocked
- `http://127.0.0.1/test` - Should be blocked
- `http://192.168.1.1/test` - Should be blocked
- `file:///etc/passwd` - Should be blocked

**Expected behavior:**
- These articles should NOT load full content
- Log should show: `"af-readability: Blocked unsafe URL"`
- No actual requests made to these addresses

#### Test Timeout Protection

Add a feed with articles linking to:
- A non-responsive server (should timeout in 30 seconds)
- A very slow server (should timeout during connection in 10 seconds)

**Expected behavior:**
- Request should abort after timeout
- Article won't have full content, but feed will continue working
- No hung processes or resource exhaustion

#### Test File Size Protection

Add a feed with articles linking to:
- Very large HTML files (>500KB)

**Expected behavior:**
- Download should abort
- Article won't load full content
- No memory exhaustion

### 4. Test SSL Validation

The extension now validates SSL certificates properly.

**Test with valid SSL:**
```
https://www.bbc.com/news/rss.xml
```
**Expected:** Works normally

**Test with invalid SSL (if you have access to such a feed):**
- Self-signed certificate sites should fail gracefully
- Expired certificate sites should fail gracefully

---

## Performance Testing

### Monitor Resource Usage

```bash
# Check PHP processes
ps aux | grep php-fpm
# or
ps aux | grep apache

# Monitor memory
free -h

# Watch system load
top
# Look for php processes
```

**Expected behavior:**
- No memory leaks
- Processes should complete within timeouts
- System load should remain reasonable

### Test with Multiple Feeds

1. Enable the extension for 5-10 feeds
2. Refresh all feeds: **Feeds → Refresh**
3. Monitor system during refresh
4. Check that all articles load properly

---

## Troubleshooting

### Extension Not Showing

**Problem:** Extension doesn't appear in FreshRSS Extensions list

**Solutions:**
```bash
# Check directory name (must be lowercase with underscore)
ls -la /path/to/FreshRSS/extensions/
# Should show: af_readability (NOT af-readability)

# If wrong name, rename it
mv af-readability af_readability

# Check permissions
ls -la /path/to/FreshRSS/extensions/af_readability
# Should be readable by web server

# Check ownership
chown -R www-data:www-data /path/to/FreshRSS/extensions/af_readability
```

### Composer Dependencies Missing

**Problem:** PHP errors about missing classes like `fivefilters\Readability\Readability`

**Solution:**
```bash
cd /path/to/FreshRSS/extensions/af_readability

# Check if vendor directory exists
ls -la vendor/

# If missing, install dependencies
composer install --no-dev

# If composer not available, install it first:
# https://getcomposer.org/download/
```

### Articles Not Extracting

**Problem:** Full article content not showing

**Possible causes:**

1. **Extension not enabled for the feed:**
   - Go to Settings → Extensions → Af_Readability (gear icon)
   - Check the box for the specific feed

2. **URL is blocked (security feature):**
   - Check logs for "Blocked unsafe URL"
   - Verify the article URL is not localhost/private IP

3. **SSL certificate issue:**
   - The site may have invalid SSL certificate
   - This is now properly rejected (security feature)

4. **Timeout or size limit:**
   - Site may be too slow or content too large
   - Check logs for curl errors

5. **Website structure:**
   - Some websites are difficult to parse
   - Not all sites work with Readability algorithm

### Too Many Blocked URLs

**Problem:** Legitimate URLs being blocked

**Check:**
```bash
# Review blocked URLs in logs
grep "Blocked unsafe URL" /path/to/FreshRSS/data/users/*/log.txt
```

**If legitimate URLs are blocked**, this may indicate:
- DNS resolution issues (hostname not resolving properly)
- IPv6 addresses being resolved incorrectly
- Network configuration issues

**To debug:**
```php
// Temporarily add debug logging to extension.php around line 207:
if (!$this->isUrlSafe($url)) {
    Minz_Log::warning('af-readability: Blocked unsafe URL: ' . $url); // Changed to include URL
    return null;
}
```

---

## Comparing with Previous Version

### Check What Changed

```bash
cd /path/to/FreshRSS/extensions/af_readability

# Show changes from main/master
git diff main extension.php
# or
git diff master extension.php

# See commit history
git log --oneline
```

### Test Side-by-Side (Advanced)

1. **Backup current installation:**
```bash
cp -r /path/to/FreshRSS/extensions/af_readability /tmp/af_readability_backup
```

2. **Test with security fixes** (current state)
   - Enable for some feeds
   - Note behavior and performance

3. **Switch to old version:**
```bash
cd /path/to/FreshRSS/extensions/af_readability
git checkout main  # or master
```

4. **Test old version**
   - Compare behavior
   - Note any differences

5. **Switch back to fixed version:**
```bash
git checkout claude/security-audit-011CULLMXFTtaMdusNbTbfSh
```

---

## What to Look For

### ✅ Good Signs

- Articles load with full content
- No PHP errors in logs
- Page loads quickly
- Memory usage stable
- Timeouts prevent hung requests
- Malicious URLs blocked (if testing)

### ⚠️ Potential Issues

- "Blocked unsafe URL" for legitimate sites (may need investigation)
- Timeouts on very slow sites (expected behavior, but may need tuning)
- SSL errors on sites with invalid certificates (expected behavior)

### ❌ Problems (Report These)

- PHP fatal errors
- Extension crashes FreshRSS
- Memory leaks
- Legitimate URLs incorrectly blocked
- Performance degradation

---

## Configuration Tips

### Adjust Timeouts (Optional)

If you have very slow feeds, you can adjust timeouts in `extension.php`:

```php
// Around line 221-223
curl_setopt($ch, CURLOPT_TIMEOUT, 60);           // Increase to 60 seconds
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);    // Increase to 15 seconds
```

### Adjust File Size Limit (Optional)

If you need larger articles:

```php
// Around line 230
curl_setopt($ch, CURLOPT_MAXFILESIZE, 1024 * 1000);  // Increase to 1MB
```

**Note:** Remember to update line 249 as well:
```php
if (!is_string($response) || mb_strlen($response) > 1024 * 1000) {
```

---

## Reporting Results

After testing, please report:

### Success Report
- ✅ Extension installs correctly
- ✅ Articles extract properly
- ✅ Performance is good
- ✅ No errors in logs
- ✅ Security features working as expected

### Issue Report
- ❌ What went wrong
- 📋 Error messages from logs
- 🖥️ System info (PHP version, FreshRSS version)
- 📝 Steps to reproduce
- 🔗 Example feeds that caused issues

---

## Useful Commands Reference

```bash
# Check PHP version
php -v

# Check required extensions
php -m | grep -E "(dom|xml|curl|mbstring)"

# Test PHP syntax
php -l /path/to/FreshRSS/extensions/af_readability/extension.php

# View logs in real-time
tail -f /path/to/FreshRSS/data/users/_/log.txt

# Check disk space
df -h

# Check memory usage
free -h

# Restart web server (if needed)
sudo systemctl restart apache2
# or
sudo systemctl restart nginx
# or
sudo systemctl restart php-fpm
```

---

## Getting Help

If you encounter issues:

1. **Check logs** for specific error messages
2. **Review SECURITY_FIXES.md** to understand what changed
3. **Check SECURITY_AUDIT.md** for detailed security information
4. **Create an issue** with detailed information
5. **Include:** PHP version, FreshRSS version, error logs, steps to reproduce

---

## Reverting Changes

If you need to revert to the original version:

```bash
cd /path/to/FreshRSS/extensions/af_readability

# Go back to main branch
git checkout main
# or
git checkout master

# Reinstall dependencies
composer install --no-dev

# Restart web server
sudo systemctl restart apache2  # or your web server
```

Then refresh FreshRSS (Ctrl+F5) and test again.

---

## Next Steps After Testing

Once testing is successful:

1. ✅ Keep using this security-fixed branch
2. 🔒 Review security documentation
3. 📝 Consider contributing back to upstream
4. 🔄 Watch for future updates
5. 🎯 Enable for all desired feeds

Thank you for testing! Your feedback helps improve the extension's security and stability.
