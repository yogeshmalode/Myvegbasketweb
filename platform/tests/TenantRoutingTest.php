<?php

declare(strict_types=1);

require_once __DIR__ . '/../TenantResolver.php';
require_once __DIR__ . '/../TenantSecretFileProvider.php';
require_once __DIR__ . '/../TenantDomainNotFoundException.php';
require_once __DIR__ . '/../TenantRuntime.php';
require_once __DIR__ . '/../TenantRuntimeBootstrap.php';

use MyVegBasket\Platform\TenantDomainNotFoundException;
use MyVegBasket\Platform\TenantResolver;
use MyVegBasket\Platform\TenantRuntime;
use MyVegBasket\Platform\TenantRuntimeBootstrap;
use MyVegBasket\Platform\TenantSecretFileProvider;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectInvalidHost(string $host): void
{
    try {
        TenantResolver::normalizeHost($host);
    } catch (InvalidArgumentException $exception) {
        return;
    }

    throw new RuntimeException('Malformed host was accepted: ' . $host);
}

$registry = new PDO('sqlite::memory:');
$registry->exec(
    'CREATE TABLE platform_tenants (
        id INTEGER PRIMARY KEY,
        slug TEXT,
        display_name TEXT,
        status TEXT,
        database_cluster TEXT,
        database_name TEXT,
        credential_ref TEXT,
        notification_email TEXT,
        currency_symbol TEXT,
        payment_qr_image TEXT,
        payment_qr_name TEXT,
        store_name TEXT,
        store_lat REAL,
        store_lng REAL
    )'
);
$registry->exec(
    'CREATE TABLE platform_tenant_domains (
        tenant_id INTEGER,
        domain TEXT,
        verified_at TEXT
    )'
);
$registry->exec(
    "INSERT INTO platform_tenants VALUES
        (1, 'active-shop', 'Active Shop', 'active', 'cluster-a', 'tenant_one', 'tenant-one',
         'owner@example.com', '₹', '/assets/tenant-qr.png', 'Shop UPI', 'Tenant Store', 18.52, 73.85),
        (2, 'unverified-shop', 'Unverified Shop', 'active', 'cluster-a', 'tenant_two', 'tenant-two',
         'owner@example.com', '₹', '/assets/tenant-qr.png', 'Shop UPI', 'Tenant Store', 18.52, 73.85),
        (3, 'suspended-shop', 'Suspended Shop', 'suspended', 'cluster-a', 'tenant_three', 'tenant-three',
         'owner@example.com', '₹', '/assets/tenant-qr.png', 'Shop UPI', 'Tenant Store', 18.52, 73.85),
        (4, 'incomplete-shop', 'Incomplete Shop', 'active', 'cluster-a', 'tenant_four', 'tenant-four',
         NULL, '₹', '/assets/tenant-qr.png', 'Shop UPI', 'Tenant Store', 18.52, 73.85)"
);
$registry->exec(
    "INSERT INTO platform_tenant_domains VALUES
        (1, 'shop.example.com', '2026-10-16 00:00:00'),
        (2, 'unverified.example.com', NULL),
        (3, 'suspended.example.com', '2026-10-16 00:00:00'),
        (4, 'incomplete.example.com', '2026-10-16 00:00:00')"
);

expect(TenantResolver::normalizeHost('SHOP.Example.com:443') === 'shop.example.com', 'Host normalization failed.');
expect((TenantResolver::resolve($registry, 'shop.example.com')['slug'] ?? null) === 'active-shop', 'Active verified domain did not resolve.');
foreach ([
    'unknown.example.com',
    'unverified.example.com',
    'suspended.example.com',
    'incomplete.example.com',
] as $host) {
    expect(TenantResolver::resolve($registry, $host) === null, 'A non-resolvable domain was accepted: ' . $host);
}
foreach (['', 'shop.example.com/path', 'bad..example.com', 'shop.example.com:99999'] as $host) {
    expectInvalidHost($host);
}

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vegbasket-tenant-test-' . bin2hex(random_bytes(8));
$secretDirectory = $testDirectory . DIRECTORY_SEPARATOR . 'secrets';
if (!mkdir($testDirectory) || !mkdir($secretDirectory)) {
    throw new RuntimeException('Could not create temporary tenant test directories.');
}
$runtimeConfigPath = $testDirectory . DIRECTORY_SEPARATOR . 'runtime.php';
$credentialPath = $secretDirectory . DIRECTORY_SEPARATOR . 'tenant-one.json';

try {
    $runtimeConfig = [
        'central_hosts' => ['myvegbasket.com', 'www.myvegbasket.com'],
        'tenant_secret_directory' => $secretDirectory,
    ];
    if (file_put_contents(
        $runtimeConfigPath,
        '<?php return ' . var_export($runtimeConfig, true) . ';'
    ) === false) {
        throw new RuntimeException('Could not create the temporary runtime config.');
    }

    $central = TenantRuntimeBootstrap::connectForRequest(
        $runtimeConfigPath,
        'WWW.MyVegBasket.com:443',
        $registry
    );
    expect(!$central['is_tenant'] && $central['pdo'] === $registry, 'Central host did not retain the existing PDO.');

    try {
        TenantRuntimeBootstrap::connectForRequest($runtimeConfigPath, 'unknown.example.com', $registry);
        throw new RuntimeException('Unmapped tenant host did not fail closed.');
    } catch (TenantDomainNotFoundException $exception) {
    }

    $credentials = [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'different_database',
        'username' => 'tenant_user',
        'password' => 'test-password',
    ];
    if (file_put_contents($credentialPath, json_encode($credentials, JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Could not create temporary tenant credentials.');
    }

    try {
        TenantRuntime::connect(
            $registry,
            'shop.example.com',
            new TenantSecretFileProvider($secretDirectory)
        );
        throw new RuntimeException('Mismatched tenant database credentials were accepted.');
    } catch (RuntimeException $exception) {
        expect(
            $exception->getMessage() === 'Tenant database credentials do not match the registry.',
            'Tenant credentials failed for an unexpected reason.'
        );
    }

    echo "Tenant routing tests passed.\n";
} finally {
    if (is_file($credentialPath)) {
        unlink($credentialPath);
    }
    if (is_file($runtimeConfigPath)) {
        unlink($runtimeConfigPath);
    }
    rmdir($secretDirectory);
    rmdir($testDirectory);
}
