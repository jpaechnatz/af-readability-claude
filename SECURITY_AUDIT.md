# Security Audit Report - af-readability-claude

**Date:** 2025-10-21
**Auditor:** Claude Code
**Extension Version:** 0.2
**Status:** Multiple Critical and High Severity Issues Found

## Executive Summary

This security audit identified **8 security vulnerabilities** ranging from Critical to Low severity. The most critical issue is an **SSRF (Server-Side Request Forgery) vulnerability** that could allow attackers to access internal network resources, scan internal services, or cause denial of service.

**Immediate action required** to address CRITICAL and HIGH severity issues before production use.

---

## CRITICAL Severity Issues

### 1. Server-Side Request Forgery (SSRF) Vulnerability
**Location:** `extension.php:164`
**CVSS Score:** 9.1 (Critical)
**CWE:** CWE-918

**Description:**
The `extractContent()` method accepts URLs directly from RSS feed articles without any validation or sanitization. An attacker can craft a malicious RSS feed with links pointing to:
- Internal network resources (e.g., `http://127.0.0.1`, `http://192.168.x.x`)
- Internal services (e.g., `http://localhost:6379` for Redis)
- Cloud metadata endpoints (e.g., `http://169.254.169.254/latest/meta-data/`)
- File system resources using `file://` protocol
- Other dangerous protocols

**Vulnerable Code:**
```php
curl_setopt($ch, CURLOPT_URL, $url);  // Line 164 - No validation!
```

**Attack Scenario:**
1. Attacker creates RSS feed with malicious article links
2. User subscribes to the feed in FreshRSS
3. Extension fetches internal resources on behalf of the server
4. Attacker gains access to internal services, credentials, or network information

**Recommended Fix:**
```php
private function isUrlSafe(string $url): bool {
    $parsed = parse_url($url);

    if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
        return false;
    }

    // Only allow HTTP and HTTPS
    if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
        return false;
    }

    $host = $parsed['host'];

    // Block localhost variations
    if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)) {
        return false;
    }

    // Resolve hostname to IP and check if it's private
    $ip = gethostbyname($host);
    if ($ip !== $host) {
        // Check for private/reserved IP ranges
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }

    // Additional check for IPv6 localhost
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $ipv6 = inet_pton($host);
        if ($ipv6 === inet_pton('::1')) {
            return false;
        }
    }

    return true;
}

// In extractContent() before line 160:
if (!$this->isUrlSafe($url)) {
    Minz_Log::warning('af-readability: Blocked unsafe URL: ' . $url);
    return false;
}
```

**Additional Protection:**
Consider adding a whitelist/blacklist configuration option for domains.

---

### 2. Missing cURL Timeout Configuration
**Location:** `extension.php:160-180`
**CVSS Score:** 7.5 (High - DoS potential)
**CWE:** CWE-400

**Description:**
No timeout limits are set on cURL requests. An attacker can provide URLs that never respond, causing:
- Resource exhaustion (open connections)
- Worker/thread starvation
- Denial of Service

**Vulnerable Code:**
```php
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
// Missing: CURLOPT_TIMEOUT and CURLOPT_CONNECTTIMEOUT
```

**Recommended Fix:**
```php
curl_setopt($ch, CURLOPT_TIMEOUT, 30);           // 30 seconds total timeout
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);    // 10 seconds connection timeout
curl_setopt($ch, CURLOPT_MAXREDIRS, 5);          // Limit redirects
```

---

## HIGH Severity Issues

### 3. Inefficient File Size Limit Check
**Location:** `extension.php:182`
**CVSS Score:** 6.5 (Medium-High)
**CWE:** CWE-400

**Description:**
The 500KB size limit is checked AFTER downloading the entire response. An attacker can cause bandwidth exhaustion and memory issues by providing links to very large files.

**Vulnerable Code:**
```php
$response = curl_exec($ch);  // Downloads entire file
// ...
if (!is_string($response) || mb_strlen($response) > 1024 * 500) {  // Check happens too late
    return false;
}
```

**Recommended Fix:**
```php
// Add before curl_exec():
curl_setopt($ch, CURLOPT_MAXFILESIZE, 1024 * 500);  // 500KB limit

// Also add a callback to abort early:
curl_setopt($ch, CURLOPT_NOPROGRESS, false);
curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($resource, $download_size, $downloaded) {
    return ($download_size > 0 && $download_size > 1024 * 500) ? 1 : 0;
});
```

---

### 4. Potential XML External Entity (XXE) Vulnerability
**Location:** `extension.php:186-193`
**CVSS Score:** 6.5 (Medium-High)
**CWE:** CWE-611

**Description:**
DOMDocument is used to parse untrusted HTML/XML content without explicitly disabling external entity loading. While modern PHP versions have better defaults, this should be explicitly secured.

**Vulnerable Code:**
```php
$document = new DOMDocument("1.0", "UTF-8");
libxml_use_internal_errors(true);
if (!$document->loadHTML('<?xml encoding="UTF-8">' . $response)) {
    // No XXE protection flags
```

**Recommended Fix:**
```php
$document = new DOMDocument("1.0", "UTF-8");
libxml_use_internal_errors(true);

// Disable external entity loading (defense in depth)
$previousValue = libxml_disable_entity_loader(true);  // For PHP < 8.0
$loadOptions = LIBXML_NONET | LIBXML_DTDLOAD | LIBXML_DTDATTR;

if (!$document->loadHTML('<?xml encoding="UTF-8">' . $response, $loadOptions)) {
    libxml_clear_errors();
    libxml_disable_entity_loader($previousValue);
    return false;
}
libxml_disable_entity_loader($previousValue);
libxml_clear_errors();
```

**Note:** `libxml_disable_entity_loader()` is deprecated in PHP 8.0+ but included for compatibility.

---

## MEDIUM Severity Issues

### 5. Missing SSL Certificate Validation
**Location:** `extension.php:160-180`
**CVSS Score:** 5.3 (Medium)
**CWE:** CWE-295

**Description:**
SSL certificate validation is not explicitly enabled, potentially allowing Man-in-the-Middle attacks.

**Recommended Fix:**
```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
```

---

### 6. Unsafe Dependency Version Specification
**Location:** `composer.json:22`
**CVSS Score:** 5.0 (Medium)
**CWE:** CWE-1104

**Description:**
Using wildcard version `"*"` for the main dependency can lead to:
- Unexpected breaking changes
- Introduction of vulnerable versions
- Non-reproducible builds (though composer.lock mitigates this partially)

**Vulnerable Code:**
```json
"require": {
    "fivefilters/readability.php": "*",  // Dangerous wildcard
    "psr/http-factory": "1.0.1"
}
```

**Recommended Fix:**
```json
"require": {
    "fivefilters/readability.php": "^3.3",  // Semantic versioning
    "psr/http-factory": "^1.0"
}
```

---

### 7. Insufficient Input Validation on Configuration
**Location:** `extension.php:85-101`
**CVSS Score:** 4.3 (Medium)
**CWE:** CWE-20

**Description:**
Configuration values are decoded from JSON without proper error handling or validation.

**Vulnerable Code:**
```php
$decoded = (array)json_decode($value, true);  // No error checking
$result = [];
foreach($decoded as $key => $param) {
    $result[(int)$key] = (bool) $param;  // Type coercion could mask issues
}
```

**Recommended Fix:**
```php
$decoded = json_decode($value, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    Minz_Log::warning('af-readability: Invalid JSON in config: ' . json_last_error_msg());
    return [];
}

if (!is_array($decoded)) {
    return [];
}

$result = [];
foreach($decoded as $key => $param) {
    if (!is_numeric($key) || !is_bool($param)) {
        continue;  // Skip invalid entries
    }
    $result[(int)$key] = $param;
}
```

---

## LOW Severity Issues

### 8. Information Disclosure via Error Messages
**Location:** `extension.php:69, 76, 217`
**CVSS Score:** 3.1 (Low)
**CWE:** CWE-209

**Description:**
Error messages and exception details could leak sensitive information about the system.

**Issues:**
```php
echo "Failed data";  // Line 69 - Unclear error message
Minz_Log::warning('af-readability: ' . $t->getMessage());  // Could leak stack traces
```

**Recommended Fix:**
- Remove the `echo` statement
- Sanitize exception messages before logging
- Use specific error codes instead of raw messages

---

## Additional Code Quality Issues

### Type Safety
**Location:** `extension.php:154`

The return type `bool|string|null` is overly permissive. Consider returning only `string|null` for better type safety:

```php
private function extractContent(string $url): ?string
{
    // Return null instead of false for errors
}
```

### Encoding Detection Logic
**Location:** `extension.php:195-203`

The character encoding detection and conversion logic is complex and could be simplified using `mb_detect_encoding()` with a priority list.

---

## Testing Recommendations

1. **SSRF Testing:**
   - Test with URLs pointing to `http://127.0.0.1`
   - Test with `file://` protocol
   - Test with cloud metadata endpoints
   - Test with internal IP ranges

2. **DoS Testing:**
   - Test with non-responsive URLs
   - Test with very large files
   - Test with infinite redirect loops

3. **Input Validation:**
   - Test with malformed JSON in configuration
   - Test with negative feed/category IDs
   - Test with extremely long URLs

---

## Compliance Considerations

- **OWASP Top 10 2021:**
  - A05:2021 – Security Misconfiguration (Missing timeouts, SSL validation)
  - A10:2021 – Server-Side Request Forgery (SSRF)

- **PCI DSS:** If processing any payment-related data, SSRF and missing SSL validation are violations.

---

## Remediation Priority

1. **IMMEDIATE (Critical):**
   - Fix SSRF vulnerability (#1)
   - Add cURL timeouts (#2)

2. **HIGH (Within 1 week):**
   - Fix file size check (#3)
   - Add XXE protection (#4)

3. **MEDIUM (Within 1 month):**
   - Add SSL validation (#5)
   - Fix dependency versioning (#6)
   - Improve config validation (#7)

4. **LOW (Future release):**
   - Clean up error handling (#8)
   - Improve code quality

---

## Summary of Findings

| Severity | Count | Issues |
|----------|-------|--------|
| Critical | 1 | SSRF vulnerability |
| High | 3 | Missing timeouts, inefficient size check, XXE potential |
| Medium | 3 | SSL validation, dependency versions, config validation |
| Low | 1 | Information disclosure |
| **Total** | **8** | |

---

## Conclusion

The af-readability-claude extension has several security vulnerabilities that should be addressed before production deployment. The SSRF vulnerability is particularly critical as it could allow attackers to access internal network resources or cause denial of service.

All recommended fixes have been provided with code examples. Implementing these changes will significantly improve the security posture of the extension.

For questions or clarifications, please refer to the specific CWE identifiers and OWASP documentation linked in each issue.
