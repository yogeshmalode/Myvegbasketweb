<?php

declare(strict_types=1);

namespace MyVegBasket\Platform;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class TenantRuntimeBootstrap
{
    /**
     * The private PHP config file must return:
     * [
     *     'registry' => ['host' => ..., 'port' => ..., 'database' => ..., 'username' => ..., 'password' => ...],
     *     'tenant_secret_directory' => '/private/path',
     * ]
     *
     * @return array{tenant: array<string, mixed>, pdo: PDO}
     */
    public static function connect(string $privateConfigPath, string $requestHost): array
    {
        $resolvedPath = realpath($privateConfigPath);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException('Tenant runtime configuration is unavailable.');
        }

        $configuration = require $resolvedPath;
        if (!is_array($configuration)) {
            throw new RuntimeException('Tenant runtime configuration has an invalid format.');
        }

        $registryConfig = $configuration['registry'] ?? null;
        $secretDirectory = $configuration['tenant_secret_directory'] ?? null;
        if (!is_array($registryConfig) || !is_string($secretDirectory) || $secretDirectory === '') {
            throw new RuntimeException('Tenant runtime configuration is incomplete.');
        }

        $host = $registryConfig['host'] ?? null;
        $port = $registryConfig['port'] ?? 3306;
        $database = $registryConfig['database'] ?? null;
        $username = $registryConfig['username'] ?? null;
        $password = $registryConfig['password'] ?? null;
        if (
            !is_string($host)
            || !is_int($port)
            || $port < 1
            || $port > 65535
            || !is_string($database)
            || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database)
            || !is_string($username)
            || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $username)
            || !is_string($password)
            || $password === ''
        ) {
            throw new RuntimeException('Tenant registry database settings are invalid.');
        }

        try {
            $host = TenantResolver::normalizeHost($host);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Tenant registry database host is invalid.', 0, $exception);
        }

        $registryPdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return TenantRuntime::connect(
            $registryPdo,
            $requestHost,
            new TenantSecretFileProvider($secretDirectory)
        );
    }

    /**
     * Central hosts retain the PDO connection already opened by config.php.
     *
     * @return array{is_tenant: bool, tenant: array<string, mixed>|null, pdo: PDO}
     */
    public static function connectForRequest(
        string $privateConfigPath,
        string $requestHost,
        PDO $centralPdo
    ): array {
        $resolvedPath = realpath($privateConfigPath);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException('Tenant runtime configuration is unavailable.');
        }

        $configuration = require $resolvedPath;
        if (!is_array($configuration) || !isset($configuration['central_hosts']) || !is_array($configuration['central_hosts'])) {
            throw new RuntimeException('Tenant runtime central host allowlist is missing.');
        }

        $normalizedRequestHost = TenantResolver::normalizeHost($requestHost);
        foreach ($configuration['central_hosts'] as $centralHost) {
            if (!is_string($centralHost)) {
                throw new RuntimeException('Tenant runtime central host allowlist is invalid.');
            }
            if (TenantResolver::normalizeHost($centralHost) === $normalizedRequestHost) {
                return ['is_tenant' => false, 'tenant' => null, 'pdo' => $centralPdo];
            }
        }

        $secretDirectory = $configuration['tenant_secret_directory'] ?? null;
        if (!is_string($secretDirectory) || $secretDirectory === '') {
            throw new RuntimeException('Tenant runtime secret directory is missing.');
        }

        $resolved = TenantRuntime::connect(
            $centralPdo,
            $normalizedRequestHost,
            new TenantSecretFileProvider($secretDirectory)
        );
        return ['is_tenant' => true, 'tenant' => $resolved['tenant'], 'pdo' => $resolved['pdo']];
    }
}
