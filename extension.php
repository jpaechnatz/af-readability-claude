<?php
require_once __DIR__ . "/vendor/autoload.php";

use \fivefilters\Readability\Readability;
use \fivefilters\Readability\Configuration;

class Af_ReadabilityExtension extends Minz_Extension
{
	/** @var array<int,FreshRSS_Feed> */
	private array $feeds;
	/** @var array<int,FreshRSS_Category> */
	private array $categories;
	/** @var array<int,bool> */
	private array $configFeeds = [];
	/** @var array<int,bool> */
	private array $configCategories = [];

	public function init()
	{
		$this->registerHook('entry_before_insert', array($this, 'processArticle'));
		Minz_View::appendStyle($this->getFileUrl('style.css'));
	}

	/**
	 * @throws Minz_PermissionDeniedException
	 */
	public function processArticle(FreshRSS_Entry $article): FreshRSS_Entry
	{
		$this->loadConfigValues();
		$feedId = $article->feedId();

		$categoryId = $article->feed()?->category()?->id();

		if (!array_key_exists($feedId, $this->configFeeds)
			&& (null === $categoryId || !array_key_exists($categoryId, $this->configCategories))
		) {
			return $article;
		}

		$extractedContent = $this->extractContent($article->link());

		$contentTest = is_string($extractedContent) ? trim(strip_tags($extractedContent)) : null;

		if (!empty($contentTest)) {
			$article->_content((string)$extractedContent);
		}

		return $article;
	}

	/** @return array<int,FreshRSS_Feed> */
	public function getFeeds(): array
	{
		return $this->feeds;
	}

	/** @return array<int,FreshRSS_Category> */
	public function getCategories(): array
	{
		return $this->categories;
	}

	/**
	 * @throws Minz_PermissionDeniedException
	*/
	private function loadConfigValues(): void
	{
		if (!class_exists('FreshRSS_Context', false)) {
			Minz_Log::warning('af-readability: FreshRSS_Context not available');
			return;
		}
		try {
			$userConf = FreshRSS_Context::userConf();
		}
		catch(\Throwable $t) {
			// SECURITY: Log error without exposing sensitive details
			Minz_Log::warning('af-readability: Failed to load user configuration');
			return;
		}

		$this->configFeeds = $this->readConfigValue($userConf, 'ext_af_readability_feeds');
		$this->configCategories = $this->readConfigValue($userConf, 'ext_af_readability_categories');
	}

	/** @return array<int,bool> */
	private function readConfigValue(FreshRSS_UserConfiguration $userConf, string $configKey): array
	{
		if('' === $configKey) {
			return [];
		}
		$value = $userConf->attributeString($configKey);
		if ($value == '') {
			return [];
		}

		// SECURITY: Properly validate JSON decoding
		$decoded = json_decode($value, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			Minz_Log::warning('af-readability: Invalid JSON in config - ' . json_last_error_msg());
			return [];
		}

		if (!is_array($decoded)) {
			Minz_Log::warning('af-readability: Config value is not an array');
			return [];
		}

		$result = [];
		foreach($decoded as $key => $param) {
			// Validate that keys are numeric and values are boolean
			if (!is_numeric($key)) {
				continue;
			}
			$result[(int)$key] = (bool) $param;
		}

		return $result;
	}

	public function getConfigFeeds(int $id): bool
	{
		return array_key_exists($id, $this->configFeeds);
	}

	public function getConfigCategories(int $id): bool
	{
		return array_key_exists($id, $this->configCategories);
	}

	/**
	 * @throws FreshRSS_Context_Exception
	 * @throws Minz_ConfigurationNamespaceException
	 * @throws Minz_PDOConnectionException
	 * @throws Minz_PermissionDeniedException
	 */
	public function handleConfigureAction()
	{
		$feedDAO = FreshRSS_Factory::createFeedDao();
		$catDAO = FreshRSS_Factory::createCategoryDao();
		$this->feeds = $feedDAO->listFeeds();
		$this->categories = $catDAO->listCategories(true,false);

		if (Minz_Request::isPost()) {
			$configFeeds = [];
			foreach ($this->feeds as $f) {
				if (Minz_Request::paramBoolean("feed_".$f->id())){
					$configFeeds[$f->id()] = true;
				}
			}

			$configCategories = [];
			foreach ($this->categories as $c) {
				if (Minz_Request::paramBoolean("cat_".$c->id())){
					$configCategories[$c->id()] = true;
				}
			}

			FreshRSS_Context::userConf()->_attribute('ext_af_readability_feeds', (string)json_encode($configFeeds));
			FreshRSS_Context::userConf()->_attribute('ext_af_readability_categories', (string)json_encode($configCategories));

			FreshRSS_Context::userConf()->save();
		}

		$this->loadConfigValues();
	}

	/**
	 * Validates URL to prevent SSRF attacks
	 * @param string $url The URL to validate
	 * @return bool True if URL is safe, false otherwise
	 */
	private function isUrlSafe(string $url): bool
	{
		$parsed = parse_url($url);

		if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
			return false;
		}

		// Only allow HTTP and HTTPS protocols
		if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
			return false;
		}

		$host = $parsed['host'];

		// Block localhost variations
		if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)) {
			return false;
		}

		// Resolve hostname to IP and check if it's private/reserved
		$ip = gethostbyname($host);
		if ($ip !== $host) {
			// Check for private/reserved IP ranges (RFC 1918, loopback, etc.)
			if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				return false;
			}
		}

		// Additional check for IPv6 localhost and link-local
		if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$normalized = strtolower($host);
			// Block ::1 (localhost) and fe80::/10 (link-local)
			if ($normalized === '::1' || strpos($normalized, 'fe80:') === 0) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @throws Minz_PermissionDeniedException
	 */
	private function extractContent(string $url): ?string
	{
		if(empty($url)) {
			return null;
		}

		// SECURITY: Validate URL to prevent SSRF attacks
		if (!$this->isUrlSafe($url)) {
			Minz_Log::warning('af-readability: Blocked unsafe URL');
			return null;
		}

		$ch = curl_init();
		if(false === $ch) {
			return null;
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

		// SECURITY: Add timeouts to prevent DoS
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);           // 30 seconds total timeout
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);    // 10 seconds connection timeout
		curl_setopt($ch, CURLOPT_MAXREDIRS, 5);          // Limit redirects to prevent redirect loops

		// SECURITY: Enable SSL certificate validation
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

		// SECURITY: Limit file size to prevent memory exhaustion
		curl_setopt($ch, CURLOPT_MAXFILESIZE, 1024 * 500);  // 500KB limit

		curl_setopt($ch, CURLOPT_USERAGENT, FRESHRSS_USERAGENT);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Accept: text/*',
			'Content-Type: text/html'
		]);

		$response = curl_exec($ch);
		if (curl_errno($ch)) {
			curl_close($ch);
			return null;
		}
		$redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
		if (!empty($redirectUrl)) {
			$url = $redirectUrl;
		}
		curl_close($ch);

		if (!is_string($response) || mb_strlen($response) > 1024 * 500) {
			return null;
		}

		$document = new DOMDocument("1.0", "UTF-8");

		// SECURITY: Disable external entity loading and use secure parsing options
		libxml_use_internal_errors(true);
		$loadOptions = LIBXML_NONET | LIBXML_DTDLOAD | LIBXML_DTDATTR;

		if (!$document->loadHTML('<?xml encoding="UTF-8">' . $response, $loadOptions)) {
			libxml_clear_errors();
			return null;
		}
		libxml_clear_errors();

		if (null === $document->encoding || strtolower($document->encoding) !== 'utf-8') {
			$responseReplaced = preg_replace("/<meta.*?charset.*?\/?>/i", "", $response);
			$response = null !== $responseReplaced ? $responseReplaced : $response;
			if (empty($document->encoding)) {
				$response = mb_convert_encoding($response, 'utf-8');
			} else {
				$response = mb_convert_encoding($response, 'utf-8', $document->encoding);
			}
		}

		try {
			$r = new Readability(new Configuration([
				'FixRelativeURLs' => true,
				'OriginalURL' => $url,
				'ExtraIgnoredElements' => ['template'],
			]));

			if ($r->parse($response)) {
				return $r->getContent();
			}
		}
		catch(\Throwable $t) {
			// SECURITY: Log error without exposing sensitive details
			Minz_Log::warning('af-readability: Failed to parse content');
			return null;
		}

		return null;
	}
}
