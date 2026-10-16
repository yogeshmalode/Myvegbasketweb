<?php

declare(strict_types=1);

namespace MyVegBasket\Platform;

use RuntimeException;

final class CpanelUapiClient
{
    private string $baseUrl;
    private string $username;
    private string $token;
    private ?\Closure $testTransport;

    public function __construct(string $baseUrl, string $username, string $token, ?callable $testTransport = null)
    {
        $parts = parse_url($baseUrl);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
            || $username === ''
            || $token === ''
        ) {
            throw new RuntimeException('cPanel UAPI configuration is invalid.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->token = $token;
        $this->testTransport = $testTransport === null ? null : \Closure::fromCallable($testTransport);
    }

    public static function isConfigured(): bool
    {
        foreach ([
            'VEGBASKET_CPANEL_API_URL',
            'VEGBASKET_CPANEL_USERNAME',
            'VEGBASKET_CPANEL_API_TOKEN',
            'VEGBASKET_TENANT_SECRET_DIRECTORY',
        ] as $setting) {
            $value = getenv($setting);
            if (!is_string($value) || $value === '') {
                return false;
            }
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        $secretDirectory = realpath((string)getenv('VEGBASKET_TENANT_SECRET_DIRECTORY'));
        if ($secretDirectory === false || !is_dir($secretDirectory) || !is_writable($secretDirectory)) {
            return false;
        }

        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $resolvedDocumentRoot = is_string($documentRoot) && $documentRoot !== '' ? realpath($documentRoot) : false;
        if ($resolvedDocumentRoot !== false && str_starts_with(
            strtolower($secretDirectory . DIRECTORY_SEPARATOR),
            strtolower(rtrim($resolvedDocumentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        )) {
            return false;
        }

        return true;
    }

    public static function fromEnvironment(): self
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('cPanel database provisioning is not configured on this server.');
        }

        return new self(
            (string)getenv('VEGBASKET_CPANEL_API_URL'),
            (string)getenv('VEGBASKET_CPANEL_USERNAME'),
            (string)getenv('VEGBASKET_CPANEL_API_TOKEN')
        );
    }

    /**
     * Calls a cPanel UAPI method using POST so passwords are not placed in the URL.
     *
     * @param array<string, scalar> $parameters
     * @return mixed
     */
    public function call(string $module, string $function, array $parameters = [])
    {
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $module)
            || !preg_match('/\A[a-z][a-z0-9_]*\z/D', $function)) {
            throw new RuntimeException('Invalid cPanel UAPI method.');
        }

        if ($this->testTransport !== null) {
            $decoded = ($this->testTransport)($module, $function, $parameters);
            return $this->extractSuccessfulData($decoded);
        }

        $curl = curl_init($this->baseUrl . '/execute/' . rawurlencode($module) . '/' . rawurlencode($function));
        if ($curl === false) {
            throw new RuntimeException('Could not initialize cPanel UAPI request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => [
                'Authorization: cpanel ' . $this->username . ':' . $this->token,
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (!is_string($responseBody)) {
            throw new RuntimeException('cPanel UAPI connection failed: ' . $curlError);
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException('cPanel UAPI returned HTTP ' . $httpStatus . '.');
        }

        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('cPanel UAPI returned invalid JSON.', 0, $exception);
        }

        return $this->extractSuccessfulData($decoded);
    }

    private function extractSuccessfulData($decoded)
    {
        $result = is_array($decoded) ? ($decoded['result'] ?? null) : null;
        if (!is_array($result) || (int)($result['status'] ?? 0) !== 1) {
            $errors = is_array($result) && is_array($result['errors'] ?? null)
                ? array_filter($result['errors'], 'is_string')
                : [];
            throw new RuntimeException(
                'cPanel UAPI operation failed' . ($errors ? ': ' . implode('; ', $errors) : '.')
            );
        }

        return $result['data'] ?? null;
    }
}
