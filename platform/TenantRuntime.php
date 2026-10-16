<?php

declare(strict_types=1);

namespace MyVegBasket\Platform;

use PDO;
use RuntimeException;

final class TenantRuntime
{
    /**
     * @return array{tenant: array<string, mixed>, pdo: PDO}
     */
    public static function connect(
        PDO $registryPdo,
        string $requestHost,
        TenantSecretFileProvider $secretProvider
    ): array {
        $tenant = TenantResolver::resolve($registryPdo, $requestHost);
        if ($tenant === null) {
            throw new TenantDomainNotFoundException('No active tenant is mapped to this domain.');
        }

        $credentials = $secretProvider->load((string) $tenant['credential_ref']);
        if (!hash_equals((string) $tenant['database_name'], $credentials['database'])) {
            throw new RuntimeException('Tenant database credentials do not match the registry.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $credentials['host'],
            $credentials['port'],
            $credentials['database']
        );
        $tenantPdo = new PDO(
            $dsn,
            $credentials['username'],
            $credentials['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        return ['tenant' => $tenant, 'pdo' => $tenantPdo];
    }
}
