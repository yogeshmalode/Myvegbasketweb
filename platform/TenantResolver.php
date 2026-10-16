<?php

declare(strict_types=1);

namespace MyVegBasket\Platform;

use InvalidArgumentException;
use PDO;

final class TenantResolver
{
    public static function normalizeHost(string $host): string
    {
        if ($host === '' || preg_match('/[\x00-\x20\/\\\\@?#,]/', $host)) {
            throw new InvalidArgumentException('Invalid request host.');
        }

        if (preg_match('/^(.+):([0-9]{1,5})$/D', $host, $matches)) {
            $port = (int) $matches[2];
            if ($port < 1 || $port > 65535) {
                throw new InvalidArgumentException('Invalid request host port.');
            }
            $host = $matches[1];
        } elseif (str_contains($host, ':')) {
            throw new InvalidArgumentException('Invalid request host.');
        }

        $host = strtolower($host);
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if (
            strlen($host) > 253
            || !preg_match(
                '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D',
                $host
            )
        ) {
            throw new InvalidArgumentException('Invalid request host.');
        }

        return $host;
    }

    /**
     * Resolves only active tenants with a verified, exact domain mapping.
     *
     * @return array<string, mixed>|null
     */
    public static function resolve(PDO $registryPdo, string $requestHost): ?array
    {
        $domain = self::normalizeHost($requestHost);
        $statement = $registryPdo->prepare(
            'SELECT t.id, t.slug, t.display_name, t.database_cluster, t.database_name, t.credential_ref,
                    t.notification_email, t.currency_symbol, t.payment_qr_image, t.payment_qr_name,
                    t.store_name, t.store_lat, t.store_lng
             FROM platform_tenant_domains d
             INNER JOIN platform_tenants t ON t.id = d.tenant_id
             WHERE d.domain = :domain
               AND d.verified_at IS NOT NULL
               AND t.status = \'active\'
               AND t.database_cluster IS NOT NULL
               AND t.database_name IS NOT NULL
               AND t.credential_ref IS NOT NULL
               AND t.notification_email IS NOT NULL
               AND t.notification_email <> \'\'
               AND t.currency_symbol IS NOT NULL
               AND t.currency_symbol <> \'\'
               AND t.payment_qr_image IS NOT NULL
               AND t.payment_qr_image <> \'\'
               AND t.payment_qr_name IS NOT NULL
               AND t.payment_qr_name <> \'\'
               AND t.store_name IS NOT NULL
               AND t.store_name <> \'\'
               AND t.store_lat IS NOT NULL
               AND t.store_lng IS NOT NULL
             LIMIT 1'
        );
        $statement->execute(['domain' => $domain]);
        $tenant = $statement->fetch(PDO::FETCH_ASSOC);

        return $tenant === false ? null : $tenant;
    }
}
