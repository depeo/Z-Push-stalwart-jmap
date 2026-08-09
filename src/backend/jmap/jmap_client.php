<?php
/***********************************************
* File      :   jmap_client.php
* Project   :   Z-Push
* Descr     :   Pure PHP JMAP HTTP client.
*               Handles session bootstrapping, API calls, blob
*               upload and download.
*
* Copyright 2024 - Z-Push Contributors
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
*
* Consult LICENSE file for details
************************************************/

class JmapClient {

    private string $sessionUrl;
    private string $username;
    private string $password;
    private ?array $session = null;
    private float  $lastCallTime = 0.0;
    private $ch = null;

    // Capability URNs
    const CAP_CORE       = 'urn:ietf:params:jmap:core';
    const CAP_MAIL       = 'urn:ietf:params:jmap:mail';
    const CAP_SUBMIT     = 'urn:ietf:params:jmap:submission';
    const CAP_CONTACTS   = 'urn:ietf:params:jmap:contacts';
    const CAP_CALENDARS  = 'urn:ietf:params:jmap:calendars';

    public function __construct(string $sessionUrl, string $username, string $password) {
        $this->sessionUrl = $sessionUrl;
        $this->username   = $username;
        $this->password   = $password;
    }

    /**
     * Fetch and cache the JMAP session object.
     * Throws FatalException if the server is unreachable or auth fails.
     */
    public function getSession(): array {
        if ($this->session === null) {
            $this->session = $this->httpRequest('GET', $this->sessionUrl);
        }
        return $this->session;
    }

    /**
     * Invalidate the cached session so the next call re-fetches it.
     */
    public function invalidateSession(): void {
        $this->session = null;
    }

    /**
     * Return the accountId for the mail capability.
     */
    public function getAccountId(): string {
        return $this->getCapabilityAccountId(self::CAP_MAIL);
    }

    /**
     * Return the accountId for a given capability URN.
     */
    public function getCapabilityAccountId(string $capability): string {
        $session = $this->getSession();
        $id = $session['primaryAccounts'][$capability] ?? $session['primaryAccounts'][self::CAP_MAIL] ?? null;
        if ($id === null) {
            throw new FatalException(sprintf('JmapClient: no account for capability "%s" in JMAP session', $capability), 0, null, LOGLEVEL_FATAL);
        }
        return $id;
    }

    /**
     * Return the JMAP API URL from the session.
     */
    public function getApiUrl(): string {
        return $this->getSession()['apiUrl'];
    }

    /**
     * Enforce inter-request delay + jitter to avoid hitting Stalwart's
     * maxConcurrentRequests limit.  Called before every outbound JMAP API request.
     */
    private function throttle(): void {
        $now = microtime(true);
        $elapsed = ($now - $this->lastCallTime) * 1000; // ms since last call
        $minInterval = JMAP_MIN_CALL_INTERVAL;
        $jitterMax   = JMAP_CALL_JITTER_MAX;

        // How long we still need to wait to meet the minimum interval
        $need = max(0, $minInterval - $elapsed);
        // Add random jitter on top
        $delay = (int)($need + mt_rand(0, max(0, $jitterMax)));

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    /**
     * Execute a JMAP method-call batch.
     *
     * @param array $methodCalls  Array of [methodName, arguments, callId] triples.
     * @param array $using        Capability URNs. Defaults to core + mail.
     * @return array              methodResponses array from the JMAP response.
     */
    public function call(array $methodCalls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        $this->throttle();
        $payload = json_encode([
            'using'       => array_values($using),
            'methodCalls' => $methodCalls,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $start = microtime(true);
        $response = $this->httpRequest('POST', $this->getApiUrl(), $payload, 'application/json');
        $elapsed = (int)((microtime(true) - $start) * 1000);

        if (!isset($response['methodResponses'])) {
            $names = array_map(fn($m) => $m[0], $methodCalls);
            throw new StatusException(
                sprintf('JmapClient: JMAP response missing methodResponses (calls: %s, %dms)', implode(',', $names), $elapsed),
                SYNC_STATUS_SERVERERROR
            );
        }

        if ($elapsed > 2000) {
            $names = array_map(fn($m) => $m[0], $methodCalls);
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                'JmapClient: slow JMAP call [%s] took %dms', implode(',', $names), $elapsed
            ));
        }
        return $response['methodResponses'];
    }

    /**
     * Upload binary data and return the blobId.
     *
     * @param string      $data         Raw binary data.
     * @param string      $contentType  MIME type of the blob.
     * @param string|null $accountId    Override account (null = mail account).
     * @return string                   The blobId assigned by the server.
     */
    public function uploadBlob(string $data, string $contentType = 'message/rfc822', ?string $accountId = null): string {
        $this->throttle();
        $accountId ??= $this->getAccountId();
        $url       = str_replace('{accountId}', rawurlencode($accountId), $this->getSession()['uploadUrl']);
        $result    = $this->httpRequest('POST', $url, $data, $contentType);

        if (!isset($result['blobId'])) {
            throw new StatusException('JmapClient: upload response missing blobId', SYNC_STATUS_SERVERERROR);
        }
        return $result['blobId'];
    }

    /**
     * Download a blob and return its raw bytes.
     *
     * @param string      $blobId     The blobId to download.
     * @param string      $name       Suggested filename (used in URL template).
     * @param string      $accept     Accepted content type.
     * @param string|null $accountId  Override account (null = mail account).
     * @return string                 Raw blob data.
     */
    public function downloadBlob(string $blobId, string $name = 'attachment', string $accept = 'application/octet-stream', ?string $accountId = null): string {
        $this->throttle();
        $accountId ??= $this->getAccountId();
        $url = $this->getSession()['downloadUrl'];
        $url = str_replace(
            ['{accountId}', '{blobId}', '{name}',           '{type}'],
            [rawurlencode($accountId), rawurlencode($blobId), rawurlencode($name), rawurlencode($accept)],
            $url
        );
        return $this->httpRequest('GET', $url, null, null, true);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Perform an HTTP request and return the decoded JSON body (or raw string).
     *
     * @param string      $method       HTTP verb.
     * @param string      $url          Full URL.
     * @param string|null $body         Request body (null for GET).
     * @param string|null $contentType  Content-Type header value.
     * @param bool        $rawResponse  Return raw bytes instead of decoded JSON.
     * @return mixed
     * @throws FatalException|StatusException on HTTP / decode errors.
     */
    private function httpRequest(
        string $method,
        string $url,
        ?string $body = null,
        ?string $contentType = null,
        bool $rawResponse = false,
        int $retryCount = 0
    ): mixed {
        $responseHeaders = [];

        // Reuse a persistent cURL handle so consecutive calls to the same host
        // share a single TLS/HTTP keep-alive connection instead of doing a new
        // TLS handshake per call (bulk syncs issue hundreds of calls per request).
        if ($this->ch === null) {
            $this->ch = curl_init();
            if ($this->ch === false) {
                throw new FatalException('JmapClient: curl_init failed', 0, null, LOGLEVEL_ERROR);
            }
            curl_setopt_array($this->ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD        => $this->username . ':' . $this->password,
                CURLOPT_TIMEOUT        => JMAP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => JMAP_SSL_VERIFY,
                CURLOPT_SSL_VERIFYHOST => JMAP_SSL_VERIFY ? 2 : 0,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_USERAGENT      => 'Z-Push JMAP Backend/1.0',
            ]);
        }

        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_HEADERFUNCTION, function ($ch, $h) use (&$responseHeaders) {
            $len = strlen($h);
            if (stripos($h, 'Retry-After:') === 0) {
                $responseHeaders['Retry-After'] = trim(substr($h, 12));
            }
            return $len;
        });

        $headers = ['Accept: application/json'];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($this->ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($this->ch, CURLOPT_POSTFIELDS, $body);
            }
        }
        else {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        }

        $start        = microtime(true);
        $responseBody = curl_exec($this->ch);
        $elapsed      = (int)((microtime(true) - $start) * 1000);
        $httpCode     = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($this->ch);
        // Drop the handle on failure so the next call rebuilds a clean connection.
        if ($curlError) {
            curl_close($this->ch);
            $this->ch = null;
        }

        if ($elapsed > 2000) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'JmapClient: slow HTTP %s (%dms, %d): %s', $method, $elapsed, $httpCode, self::sanitizeUrl($url)
            ));
        }

        if ($curlError) {
            throw new FatalException(
                sprintf('JmapClient: cURL error for %s %s: %s', $method, self::sanitizeUrl($url), $curlError),
                0, null, LOGLEVEL_ERROR
            );
        }

        if ($httpCode === 401) {
            $this->invalidateSession();
            throw new AuthenticationRequiredException(sprintf(
                'JmapClient: authentication failed (401) for %s', self::sanitizeUrl($url)
            ));
        }

        if ($httpCode === 429) {
            $maxRetries = 5;
            if ($retryCount < $maxRetries) {
                $ra = $responseHeaders['Retry-After'] ?? '';
                $delay = $ra !== '' ? (int)$ra : (int)(pow(2, $retryCount) * 3 + mt_rand(0, max(1, $retryCount * 2)));
                ZLog::Write(LOGLEVEL_WARN, sprintf(
                    'JmapClient: rate limited (429), retrying %s %s in %ds (attempt %d/%d)%s',
                    $method, self::sanitizeUrl($url), $delay, $retryCount + 1, $maxRetries,
                    $ra !== '' ? sprintf(' [Retry-After: %s]', $ra) : ''
                ));
                sleep($delay);
                return $this->httpRequest($method, $url, $body, $contentType, $rawResponse, $retryCount + 1);
            }
            throw new StatusException(
                sprintf('JmapClient: HTTP 429 (rate limited) from %s %s after %d retries', $method, self::sanitizeUrl($url), $maxRetries),
                SYNC_STATUS_SERVERERROR
            );
        }

        if ($httpCode >= 400) {
            $decoded = json_decode($responseBody, true);
            // JMAP concurrent-request / server-busy limits — retry like HTTP 429
            if (($decoded['type'] ?? '') === 'urn:ietf:params:jmap:error:limit') {
                $maxRetries = 5;
                if ($retryCount < $maxRetries) {
                    $ra = $responseHeaders['Retry-After'] ?? '';
                    $delay = $ra !== '' ? (int)$ra : (int)(pow(2, $retryCount) * 3 + mt_rand(0, max(1, $retryCount * 2)));
                    $limitDetail = sprintf('limit=%s', $decoded['limit'] ?? 'unknown');
                    if (isset($decoded['limitDescription'])) {
                        $limitDetail .= sprintf(' desc="%s"', $decoded['limitDescription']);
                    }
                    ZLog::Write(LOGLEVEL_WARN, sprintf(
                        'JmapClient: request limit exceeded (%s), retrying %s %s in %ds (attempt %d/%d)',
                        $limitDetail, $method, self::sanitizeUrl($url), $delay, $retryCount + 1, $maxRetries
                    ));
                    sleep($delay);
                    return $this->httpRequest($method, $url, $body, $contentType, $rawResponse, $retryCount + 1);
                }
                throw new StatusException(
                    sprintf('JmapClient: JMAP limit error from %s %s after %d retries: %s', $method, self::sanitizeUrl($url), $maxRetries, substr($responseBody, 0, 200)),
                    SYNC_STATUS_SERVERERROR
                );
            }
            throw new StatusException(
                sprintf('JmapClient: HTTP %d from %s %s: %s', $httpCode, $method, self::sanitizeUrl($url), substr($responseBody, 0, 200)),
                SYNC_STATUS_SERVERERROR
            );
        }

        $this->lastCallTime = microtime(true);

        if ($rawResponse) {
            return $responseBody;
        }

        $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }

    /**
     * Strip credentials from a URL for safe logging.
     */
    private static function sanitizeUrl(string $url): string {
        return preg_replace('/\/\/[^:]+:[^@]+@/', '//USER:PASS@', $url) ?? $url;
    }
}
