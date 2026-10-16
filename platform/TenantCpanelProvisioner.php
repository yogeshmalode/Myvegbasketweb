<?php

declare(strict_types=1);

namespace MyVegBasket\Platform;

use PDO;
use RuntimeException;

final class TenantCpanelProvisioner
{
    private CpanelUapiClient $client;
    private string $secretDirectory;
    private string $databaseHost;
    private int $databasePort;
    private string $clusterId;

    public function __construct(
        CpanelUapiClient $client,
        string $secretDirectory,
        string $databaseHost,
        int $databasePort,
        string $clusterId
    ) {
        $resolvedDirectory = realpath($secretDirectory);
        if ($resolvedDirectory === false || !is_dir($resolvedDirectory) || !is_writable($resolvedDirectory)) {
            throw new RuntimeException('Tenant secret directory is unavailable or not writable.');
        }
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,99}\z/D', $clusterId)) {
            throw new RuntimeException('Tenant database cluster reference is invalid.');
        }
        if ($databasePort < 1 || $databasePort > 65535) {
            throw new RuntimeException('Tenant database port is invalid.');
        }

        $this->client = $client;
        $this->secretDirectory = $resolvedDirectory;
        $this->databaseHost = TenantResolver::normalizeHost($databaseHost);
        $this->databasePort = $databasePort;
        $this->clusterId = $clusterId;
    }

    public static function fromEnvironment(): self
    {
        if (!CpanelUapiClient::isConfigured()) {
            throw new RuntimeException('cPanel automatic provisioning is not configured.');
        }

        $databaseHost = getenv('VEGBASKET_TENANT_DB_HOST');
        $databasePort = getenv('VEGBASKET_TENANT_DB_PORT');
        $clusterId = getenv('VEGBASKET_CPANEL_CLUSTER_ID');

        return new self(
            CpanelUapiClient::fromEnvironment(),
            (string)getenv('VEGBASKET_TENANT_SECRET_DIRECTORY'),
            is_string($databaseHost) && $databaseHost !== '' ? $databaseHost : 'localhost',
            is_string($databasePort) && ctype_digit($databasePort) ? (int)$databasePort : 3306,
            is_string($clusterId) && $clusterId !== '' ? $clusterId : 'cpanel-main'
        );
    }

    /**
     * @return array{database_name: string, credential_ref: string, database_cluster: string, secret_path: string}
     */
    public function provision(string $slug): array
    {
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $slug)) {
            throw new RuntimeException('Tenant slug is invalid.');
        }

        $suffix = 'vb' . bin2hex(random_bytes(4));
        $credentialRef = 'tenant-' . substr(str_replace('-', '', $slug), 0, 24) . '-' . bin2hex(random_bytes(3));
        $password = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        $databaseName = null;
        $databaseUser = null;
        $secretPath = null;
        $databaseCreated = false;
        $userCreated = false;

        try {
            $this->client->call('Mysql', 'create_database', ['name' => $suffix]);
            $databaseCreated = true;
            $databaseName = $suffix;
            $databaseName = $this->findDatabaseName($suffix);
            $this->client->call('Mysql', 'create_user', ['name' => $suffix, 'password' => $password]);
            $userCreated = true;
            $databaseUser = $databaseName;
            $this->client->call('Mysql', 'set_privileges_on_database', [
                'database' => $databaseName,
                'user' => $databaseUser,
                'privileges' => 'ALL PRIVILEGES',
            ]);
            $this->confirmDatabaseUser($databaseName, $databaseUser);

            $secretPath = $this->writeSecret($credentialRef, [
                'host' => $this->databaseHost,
                'port' => $this->databasePort,
                'database' => $databaseName,
                'username' => $databaseUser,
                'password' => $password,
            ]);
        } catch (\Throwable $exception) {
            $this->deleteProvisionedResources(
                $databaseCreated ? $databaseName : null,
                $userCreated ? $databaseUser : null
            );
            if ($secretPath !== null && is_file($secretPath)) {
                unlink($secretPath);
            }
            throw $exception;
        }

        return [
            'database_name' => $databaseName,
            'credential_ref' => $credentialRef,
            'database_cluster' => $this->clusterId,
            'secret_path' => $secretPath,
            'database_user' => $databaseUser,
        ];
    }

    /**
     * @param array{database_name: string, credential_ref: string, secret_path?: string, database_user?: string} $provisioned
     */
    public function rollback(array $provisioned): void
    {
        $this->deleteProvisionedResources(
            $provisioned['database_name'] ?? null,
            $provisioned['database_user'] ?? null
        );
        $secretPath = $provisioned['secret_path'] ?? $this->secretDirectory . DIRECTORY_SEPARATOR . $provisioned['credential_ref'] . '.json';
        if (is_file($secretPath) && dirname((string)realpath($secretPath)) === $this->secretDirectory) {
            unlink($secretPath);
        }
    }

    private function findDatabaseName(string $suffix): string
    {
        $databases = $this->client->call('Mysql', 'list_databases');
        if (!is_array($databases)) {
            throw new RuntimeException('Could not confirm the newly created cPanel database.');
        }

        $matches = [];
        foreach ($databases as $database) {
            $name = is_array($database) ? ($database['database'] ?? null) : null;
            if (is_string($name) && ($name === $suffix || str_ends_with($name, '_' . $suffix))) {
                $matches[] = $name;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('Could not uniquely identify the new cPanel database.');
        }

        return $matches[0];
    }

    private function confirmDatabaseUser(string $databaseName, string $databaseUser): void
    {
        $databases = $this->client->call('Mysql', 'list_databases');
        if (!is_array($databases)) {
            throw new RuntimeException('Could not confirm the new cPanel database user.');
        }
        foreach ($databases as $database) {
            if (!is_array($database) || ($database['database'] ?? null) !== $databaseName) {
                continue;
            }
            $users = $database['users'] ?? [];
            if (is_array($users) && in_array($databaseUser, $users, true)) {
                return;
            }
        }

        throw new RuntimeException('Could not confirm the cPanel database user was granted access.');
    }

    /**
     * @param array{host: string, port: int, database: string, username: string, password: string} $credentials
     */
    private function writeSecret(string $credentialRef, array $credentials): string
    {
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $resolvedDocumentRoot = is_string($documentRoot) && $documentRoot !== '' ? realpath($documentRoot) : false;
        if ($resolvedDocumentRoot !== false && str_starts_with(
            strtolower($this->secretDirectory . DIRECTORY_SEPARATOR),
            strtolower(rtrim($resolvedDocumentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        )) {
            throw new RuntimeException('Tenant credential directory must be outside the web document root.');
        }

        $secretPath = $this->secretDirectory . DIRECTORY_SEPARATOR . $credentialRef . '.json';
        if (file_exists($secretPath)) {
            throw new RuntimeException('Tenant credential reference already exists.');
        }
        $temporaryPath = tempnam($this->secretDirectory, '.tenant-secret-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Could not create a private tenant credential file.');
        }

        try {
            $encoded = json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
                throw new RuntimeException('Could not write tenant database credentials.');
            }
            chmod($temporaryPath, 0600);
            if (!rename($temporaryPath, $secretPath)) {
                throw new RuntimeException('Could not finalize the tenant credential file.');
            }
            chmod($secretPath, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        return $secretPath;
    }

    private function deleteProvisionedResources(?string $databaseName, ?string $databaseUser): void
    {
        foreach ([
            ['Mysql', 'delete_database', 'name', $databaseName],
            ['Mysql', 'delete_user', 'name', $databaseUser],
        ] as [$module, $function, $parameter, $value]) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            try {
                $this->client->call($module, $function, [$parameter => $value]);
            } catch (\Throwable $exception) {
                error_log('cPanel tenant provision rollback failed for ' . $function . '.');
            }
        }
    }
}
