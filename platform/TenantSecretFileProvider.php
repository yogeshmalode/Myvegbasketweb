<?php

declare(strict_types=1);

namespace MyVegBasket\Platform;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class TenantSecretFileProvider
{
    private string $directory;

    public function __construct(string $directory)
    {
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || !is_dir($resolvedDirectory) || !is_readable($resolvedDirectory)) {
            throw new RuntimeException('Tenant secret directory is unavailable.');
        }

        $this->directory = $resolvedDirectory;
    }

    /**
     * @return array{host: string, port: int, database: string, username: string, password: string}
     */
    public function load(string $credentialRef): array
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $credentialRef)) {
            throw new InvalidArgumentException('Invalid tenant credential reference.');
        }

        $path = $this->directory . DIRECTORY_SEPARATOR . $credentialRef . '.json';
        $resolvedPath = realpath($path);
        if (
            $resolvedPath === false
            || dirname($resolvedPath) !== $this->directory
            || !is_file($resolvedPath)
            || !is_readable($resolvedPath)
        ) {
            throw new RuntimeException('Tenant database credentials are unavailable.');
        }

        $contents = file_get_contents($resolvedPath);
        if ($contents === false) {
            throw new RuntimeException('Tenant database credentials could not be read.');
        }

        try {
            $credentials = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Tenant database credentials contain invalid JSON.', 0, $exception);
        }

        if (!is_array($credentials)) {
            throw new RuntimeException('Tenant database credentials have an invalid format.');
        }

        $host = $credentials['host'] ?? null;
        $database = $credentials['database'] ?? null;
        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;
        $port = $credentials['port'] ?? 3306;

        if (
            !is_string($host)
            || !is_string($database)
            || !is_string($username)
            || !is_string($password)
            || $password === ''
            || !is_int($port)
            || $port < 1
            || $port > 65535
            || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database)
            || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $username)
        ) {
            throw new RuntimeException('Tenant database credentials have invalid values.');
        }

        try {
            $host = TenantResolver::normalizeHost($host);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Tenant database credentials have an invalid host.', 0, $exception);
        }

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
        ];
    }
}
