<?php
require_once __DIR__ . '/includes/auth.php';
if (is_tenant_request()) {
    http_response_code(403);
    exit('Tenant management is available only on the central admin host.');
}
require_role(['admin']);

require_once __DIR__ . '/../platform/TenantResolver.php';
require_once __DIR__ . '/../platform/TenantSecretFileProvider.php';
require_once __DIR__ . '/../platform/CpanelUapiClient.php';
require_once __DIR__ . '/../platform/TenantCpanelProvisioner.php';
require_once __DIR__ . '/../platform/TenantSchemaCloner.php';

$page_title = 'Tenant Management';

function tenant_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function tenant_post_value(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function tenant_domain_input(string $domain): string
{
    if ($domain === '' || str_contains($domain, ':')) {
        throw new InvalidArgumentException('Enter a domain name without a scheme, path, or port.');
    }

    $normalizedDomain = \MyVegBasket\Platform\TenantResolver::normalizeHost($domain);
    $centralHost = parse_url(SITE_URL, PHP_URL_HOST);
    if (is_string($centralHost) && $centralHost !== '') {
        $normalizedCentralHost = \MyVegBasket\Platform\TenantResolver::normalizeHost($centralHost);
        $centralHosts = [$normalizedCentralHost];
        if (str_starts_with($normalizedCentralHost, 'www.')) {
            $centralHosts[] = substr($normalizedCentralHost, 4);
        } else {
            $centralHosts[] = 'www.' . $normalizedCentralHost;
        }
        if (in_array($normalizedDomain, $centralHosts, true)) {
            throw new InvalidArgumentException('The central store domain cannot be registered as a tenant domain.');
        }
    }

    return $normalizedDomain;
}

function tenant_activate_preflight(PDO $registryPdo, array $tenant): void
{
    $domainStatement = $registryPdo->prepare(
        'SELECT COUNT(*) FROM platform_tenant_domains WHERE tenant_id = ? AND verified_at IS NOT NULL'
    );
    $domainStatement->execute([(int)$tenant['id']]);
    if ((int)$domainStatement->fetchColumn() < 1) {
        throw new RuntimeException('Verify at least one tenant domain before activation.');
    }

    $runtimeConfigPath = getenv('VEGBASKET_TENANT_RUNTIME_CONFIG');
    if (!is_string($runtimeConfigPath) || $runtimeConfigPath === '') {
        throw new RuntimeException('Set the private tenant runtime config path before activating tenants.');
    }

    $resolvedPath = realpath($runtimeConfigPath);
    if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
        throw new RuntimeException('The configured tenant runtime file is unavailable.');
    }

    $runtimeConfig = require $resolvedPath;
    $secretDirectory = is_array($runtimeConfig)
        ? ($runtimeConfig['tenant_secret_directory'] ?? null)
        : null;
    if (!is_string($secretDirectory) || $secretDirectory === '') {
        throw new RuntimeException('The tenant secret directory is not configured.');
    }

    $credentials = (new \MyVegBasket\Platform\TenantSecretFileProvider($secretDirectory))
        ->load((string)$tenant['credential_ref']);
    if (!hash_equals((string)$tenant['database_name'], $credentials['database'])) {
        throw new RuntimeException('Tenant credential database does not match the registry.');
    }

    $tenantPdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $credentials['host'],
            $credentials['port'],
            $credentials['database']
        ),
        $credentials['username'],
        $credentials['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $requiredTables = ['admins', 'vegetables', 'orders', 'order_items', 'dark_stores', 'store_inventory'];
    $tableCheck = $tenantPdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $missingTables = [];
    foreach ($requiredTables as $table) {
        $tableCheck->execute([$table]);
        if ((int)$tableCheck->fetchColumn() !== 1) {
            $missingTables[] = $table;
        }
    }
    if ($missingTables) {
        throw new RuntimeException('Tenant database is missing required tables: ' . implode(', ', $missingTables) . '.');
    }
}

function tenant_cleanup_failed_provision(
    ?\MyVegBasket\Platform\TenantCpanelProvisioner $provisioner,
    ?array $provisioned
): void {
    if ($provisioner !== null && $provisioned !== null) {
        $provisioner->rollback($provisioned);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $provisioner = null;
    $provisioned = null;

    try {
        if (isset($_POST['create_tenant'])) {
            $slug = strtolower(tenant_post_value('slug'));
            $name = tenant_post_value('display_name');
            $domain = tenant_domain_input(tenant_post_value('domain'));
            $domainType = tenant_post_value('domain_type');
            $credentialRef = tenant_post_value('credential_ref');
            $databaseName = tenant_post_value('database_name');
            $databaseCluster = tenant_post_value('database_cluster');
            $autoProvision = isset($_POST['auto_provision']);
            $email = tenant_post_value('notification_email');
            $currency = tenant_post_value('currency_symbol');
            $qrImage = tenant_post_value('payment_qr_image');
            $qrName = tenant_post_value('payment_qr_name');
            $storeName = tenant_post_value('store_name');
            $latitude = tenant_post_value('store_lat');
            $longitude = tenant_post_value('store_lng');

            if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $slug)) {
                throw new InvalidArgumentException('Slug must be 1-63 lowercase letters, numbers, or internal hyphens.');
            }
            if ($name === '' || mb_strlen($name) > 150) {
                throw new InvalidArgumentException('Store name is required and must be at most 150 characters.');
            }
            if (!in_array($domainType, ['platform_subdomain', 'custom'], true)) {
                throw new InvalidArgumentException('Choose a supported domain type.');
            }
            if ($autoProvision) {
                if (!\MyVegBasket\Platform\CpanelUapiClient::isConfigured()) {
                    throw new RuntimeException('Configure the cPanel UAPI environment settings before using automatic provisioning.');
                }
            } else {
                if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $credentialRef)) {
                    throw new InvalidArgumentException('Credential reference must use letters, numbers, underscores, or hyphens.');
                }
                if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $databaseName)) {
                    throw new InvalidArgumentException('Enter the tenant database name as configured in cPanel.');
                }
                if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,99}\z/D', $databaseCluster)) {
                    throw new InvalidArgumentException('Database cluster reference must use letters, numbers, underscores, or hyphens.');
                }
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Enter a valid notification email address.');
            }
            if (!preg_match('/\A[\p{L}\p{S}]{1,8}\z/uD', $currency)) {
                throw new InvalidArgumentException('Currency symbol is required and must be at most 8 characters.');
            }
            if (
                !preg_match('/\A\/[A-Za-z0-9._\/-]+\z/D', $qrImage)
                || str_starts_with($qrImage, '//')
                || str_contains($qrImage, '..')
            ) {
                throw new InvalidArgumentException('UPI QR image must be an app-local absolute path, such as /uploads/store-qr.png.');
            }
            if ($qrName === '' || mb_strlen($qrName) > 150 || $storeName === '' || mb_strlen($storeName) > 150) {
                throw new InvalidArgumentException('UPI payee name and store location name are required (150 characters max).');
            }
            if (
                !is_numeric($latitude) || !is_numeric($longitude)
                || (float)$latitude < -90 || (float)$latitude > 90
                || (float)$longitude < -180 || (float)$longitude > 180
            ) {
                throw new InvalidArgumentException('Enter valid latitude (-90 to 90) and longitude (-180 to 180).');
            }

            if ($autoProvision) {
                $provisioner = \MyVegBasket\Platform\TenantCpanelProvisioner::fromEnvironment();
                $provisioned = $provisioner->provision($slug);
                $databaseName = $provisioned['database_name'];
                $credentialRef = $provisioned['credential_ref'];
                $databaseCluster = $provisioned['database_cluster'];

                $secretDirectory = (string)getenv('VEGBASKET_TENANT_SECRET_DIRECTORY');
                $credentials = (new \MyVegBasket\Platform\TenantSecretFileProvider($secretDirectory))->load($credentialRef);
                $tenantPdo = new PDO(
                    sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                        $credentials['host'],
                        $credentials['port'],
                        $credentials['database']
                    ),
                    $credentials['username'],
                    $credentials['password'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
                $tableCount = \MyVegBasket\Platform\TenantSchemaCloner::cloneStructure($pdo, $tenantPdo);
                $tenantStore = $tenantPdo->prepare(
                    'INSERT INTO dark_stores (name, lat, lng, service_radius_km, is_active) VALUES (?, ?, ?, 5, 1)'
                );
                $tenantStore->execute([$storeName, (float)$latitude, (float)$longitude]);
            }

            $pdo->beginTransaction();
            $tenantInsert = $pdo->prepare(
                "INSERT INTO platform_tenants
                    (slug, display_name, status, database_cluster, database_name, credential_ref, notification_email,
                     currency_symbol, payment_qr_image, payment_qr_name, store_name, store_lat, store_lng)
                 VALUES (?, ?, 'provisioning', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $tenantInsert->execute([
                $slug,
                $name,
                $databaseCluster,
                $databaseName,
                $credentialRef,
                $email,
                $currency,
                $qrImage,
                $qrName,
                $storeName,
                (float)$latitude,
                (float)$longitude,
            ]);
            $tenantId = (int)$pdo->lastInsertId();

            $domainInsert = $pdo->prepare(
                'INSERT INTO platform_tenant_domains (tenant_id, domain, domain_type) VALUES (?, ?, ?)'
            );
            $domainInsert->execute([$tenantId, $domain, $domainType]);
            $pdo->commit();
            tenant_flash(
                'success',
                $autoProvision
                    ? 'Tenant database and private credentials were provisioned and the schema structure was copied (' . $tableCount . ' tables, no data). Tenant remains in provisioning status.'
                    : 'Tenant created in provisioning status. Its domain remains unverified and the tenant is not live.'
            );
        } elseif (isset($_POST['add_domain'])) {
            $tenantId = filter_input(INPUT_POST, 'tenant_id', FILTER_VALIDATE_INT);
            $domain = tenant_domain_input(tenant_post_value('domain'));
            $domainType = tenant_post_value('domain_type');
            if (!$tenantId || !in_array($domainType, ['platform_subdomain', 'custom'], true)) {
                throw new InvalidArgumentException('Choose a tenant and supported domain type.');
            }
            $tenantCheck = $pdo->prepare('SELECT id FROM platform_tenants WHERE id = ?');
            $tenantCheck->execute([$tenantId]);
            if (!$tenantCheck->fetchColumn()) {
                throw new InvalidArgumentException('Tenant not found.');
            }
            $pdo->prepare(
                'INSERT INTO platform_tenant_domains (tenant_id, domain, domain_type) VALUES (?, ?, ?)'
            )->execute([$tenantId, $domain, $domainType]);
            tenant_flash('success', 'Domain added as unverified. Verify its DNS and host configuration before marking it verified.');
        } elseif (isset($_POST['prepare_domain_verification']) || isset($_POST['verify_domain']) || isset($_POST['unverify_domain'])) {
            $domainId = filter_input(INPUT_POST, 'domain_id', FILTER_VALIDATE_INT);
            if (!$domainId) {
                throw new InvalidArgumentException('Invalid domain selection.');
            }

            $domainCheck = $pdo->prepare(
                'SELECT d.id, d.domain, d.domain_type, d.verification_token, d.verification_expires_at
                 FROM platform_tenant_domains d WHERE d.id = ?'
            );
            $domainCheck->execute([$domainId]);
            $domainRow = $domainCheck->fetch();
            if (!$domainRow) {
                throw new InvalidArgumentException('Domain not found.');
            }

            if (isset($_POST['prepare_domain_verification'])) {
                $verificationToken = bin2hex(random_bytes(32));
                $pdo->prepare(
                    'UPDATE platform_tenant_domains
                     SET verification_method = ?, verification_token = ?, verification_expires_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 48 HOUR),
                         verified_at = NULL
                     WHERE id = ?'
                )->execute(['dns_txt', $verificationToken, $domainId]);
                tenant_flash('success', 'DNS verification challenge generated. Add the displayed TXT record, wait for DNS propagation, then verify it here.');
            } elseif (isset($_POST['verify_domain'])) {
                $token = $domainRow['verification_token'] ?? '';
                $expiresAt = $domainRow['verification_expires_at'] ?? '';
                if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/D', $token) || !$expiresAt || strtotime($expiresAt) < time()) {
                    throw new InvalidArgumentException('Generate a fresh DNS challenge; the current challenge is missing or expired.');
                }
                if (!function_exists('dns_get_record')) {
                    throw new RuntimeException('DNS TXT verification is unavailable in this PHP installation.');
                }
                $recordName = '_myvegbasket-verification.' . $domainRow['domain'];
                $records = @dns_get_record($recordName, DNS_TXT);
                if (!is_array($records)) {
                    throw new RuntimeException('Could not query DNS right now. Check DNS availability and retry.');
                }
                $expectedTxt = 'myvegbasket-verification=' . $token;
                $verified = false;
                foreach ($records as $record) {
                    if (($record['txt'] ?? '') === $expectedTxt || ($record['entries'][0] ?? '') === $expectedTxt) {
                        $verified = true;
                        break;
                    }
                }
                if (!$verified) {
                    throw new InvalidArgumentException('DNS verification record was not found. Check its name/value and propagation, then retry.');
                }
                $pdo->prepare(
                    'UPDATE platform_tenant_domains
                     SET verified_at = CURRENT_TIMESTAMP, verification_expires_at = NULL
                     WHERE id = ?'
                )->execute([$domainId]);
                tenant_flash('success', 'DNS TXT proof verified. Configure the domain in cPanel/web hosting before activating the tenant.');
            } else {
                $pdo->prepare(
                    'UPDATE platform_tenant_domains
                     SET verified_at = NULL, verification_token = NULL, verification_expires_at = NULL'
                     . ' WHERE id = ?'
                )->execute([$domainId]);
                tenant_flash('success', 'Domain verification removed. Tenant routing will no longer resolve this domain.');
            }
        } elseif (isset($_POST['update_tenant'])) {
            $tenantId = filter_input(INPUT_POST, 'tenant_id', FILTER_VALIDATE_INT);
            $name = tenant_post_value('display_name');
            $email = tenant_post_value('notification_email');
            $currency = tenant_post_value('currency_symbol');
            $qrImage = tenant_post_value('payment_qr_image');
            $qrName = tenant_post_value('payment_qr_name');
            $storeName = tenant_post_value('store_name');
            $latitude = tenant_post_value('store_lat');
            $longitude = tenant_post_value('store_lng');
            if (!$tenantId || $name === '' || mb_strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Enter a tenant, valid store name, and valid notification email.');
            }
            if (!preg_match('/\A[\p{L}\p{S}]{1,8}\z/uD', $currency)) {
                throw new InvalidArgumentException('Enter a valid currency symbol.');
            }
            if (
                !preg_match('/\A\/[A-Za-z0-9._\/-]+\z/D', $qrImage)
                || str_starts_with($qrImage, '//')
                || str_contains($qrImage, '..')
                || $qrName === ''
                || $storeName === ''
                || mb_strlen($qrName) > 150
                || mb_strlen($storeName) > 150
            ) {
                throw new InvalidArgumentException('Enter valid local QR path, payee name, and store location name.');
            }
            if (
                !is_numeric($latitude) || !is_numeric($longitude)
                || (float)$latitude < -90 || (float)$latitude > 90
                || (float)$longitude < -180 || (float)$longitude > 180
            ) {
                throw new InvalidArgumentException('Enter valid latitude and longitude.');
            }

            $updateStatement = $pdo->prepare(
                'UPDATE platform_tenants
                 SET display_name = ?, notification_email = ?, currency_symbol = ?, payment_qr_image = ?,
                     payment_qr_name = ?, store_name = ?, store_lat = ?, store_lng = ?
                 WHERE id = ?'
            );
            $updateStatement->execute([
                $name, $email, $currency, $qrImage, $qrName, $storeName,
                (float)$latitude, (float)$longitude, $tenantId,
            ]);
            if ($updateStatement->rowCount() === 0) {
                $tenantExists = $pdo->prepare('SELECT COUNT(*) FROM platform_tenants WHERE id = ?');
                $tenantExists->execute([$tenantId]);
                if ((int)$tenantExists->fetchColumn() === 0) {
                        throw new InvalidArgumentException('Tenant not found.');
                }
            }
            tenant_flash('success', 'Tenant store settings updated.');
        } elseif (isset($_POST['change_status'])) {
            $tenantId = filter_input(INPUT_POST, 'tenant_id', FILTER_VALIDATE_INT);
            $newStatus = tenant_post_value('status');
            if (!$tenantId || !in_array($newStatus, ['active', 'suspended'], true)) {
                throw new InvalidArgumentException('Invalid tenant status change.');
            }

            $tenantCheck = $pdo->prepare('SELECT * FROM platform_tenants WHERE id = ?');
            $tenantCheck->execute([$tenantId]);
            $tenantRow = $tenantCheck->fetch();
            if (!$tenantRow) {
                throw new InvalidArgumentException('Tenant not found.');
            }
            if ($newStatus === 'active') {
                tenant_activate_preflight($pdo, $tenantRow);
                $pdo->prepare("UPDATE platform_tenants SET status = 'active' WHERE id = ?")->execute([$tenantId]);
                tenant_flash('success', 'Tenant activated. Requests will resolve only after tenant routing is enabled in the runtime environment.');
            } else {
                $pdo->prepare("UPDATE platform_tenants SET status = 'suspended' WHERE id = ?")->execute([$tenantId]);
                tenant_flash('success', 'Tenant suspended. Its domains will no longer resolve to its database.');
            }
        } else {
            throw new InvalidArgumentException('Unknown tenant management action.');
        }
    } catch (InvalidArgumentException $exception) {
        tenant_flash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tenant_cleanup_failed_provision($provisioner, $provisioned);
        error_log('Tenant management database action failed: ' . $exception->getMessage());
        tenant_flash('error', 'The change could not be saved. Check for a duplicate tenant slug or domain and review the server log.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tenant_cleanup_failed_provision($provisioner, $provisioned);
        error_log('Tenant management action failed: ' . $exception->getMessage());
        tenant_flash('error', $exception->getMessage());
    }

    redirect('tenants.php');
}

$registryCheck = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('platform_tenants', 'platform_tenant_domains'))
        +
        (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_tenant_domains'
           AND COLUMN_NAME IN ('verification_token', 'verification_expires_at'))"
)->fetchColumn();
$registryReady = (int)$registryCheck === 4;
$tenants = [];
if ($registryReady) {
    $tenants = $pdo->query(
        'SELECT t.* FROM platform_tenants t ORDER BY t.created_at DESC, t.id DESC'
    )->fetchAll();
    $domainStatement = $pdo->prepare(
        'SELECT id, tenant_id, domain, domain_type, verification_method, verified_at,
        verification_token, verification_expires_at
         FROM platform_tenant_domains WHERE tenant_id = ? ORDER BY id'
    );
    foreach ($tenants as &$tenant) {
        $domainStatement->execute([(int)$tenant['id']]);
        $tenant['domains'] = $domainStatement->fetchAll();
    }
    unset($tenant);
}

include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head">
  <h2>Tenant Management</h2>
  <p>Create and manage isolated SaaS stores. Existing MyVegBasket store operations are unchanged. New tenants remain in provisioning until their domain is verified and their separate database passes activation checks.</p>
</div>
<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-<?= h($_SESSION['flash']['type']) ?>"><?= h($_SESSION['flash']['message']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (!$registryReady): ?>
  <div class="alert alert-error">Tenant registry migrations are missing or incomplete. Apply <code>platform/migrations/001_tenant_registry.sql</code> and <code>platform/migrations/002_tenant_domain_verification.sql</code> to the central database.</div>
<?php else: ?>
  <div class="alert alert-info">
    <strong>Important:</strong> automatic database/user creation and private credentials are available when cPanel UAPI is configured. DNS TXT ownership is verified from this page; you still need to add the TXT record and configure the domain in cPanel. Never enter database passwords here.
    Routing is live only when the private runtime config is enabled.
  </div>

  <div class="table-wrap" style="margin-bottom:24px;">
    <table>
      <thead><tr><th>Tenant</th><th>Domain(s)</th><th>Database</th><th>Status</th><th>Manage</th></tr></thead>
      <tbody>
      <?php foreach ($tenants as $tenant): ?>
        <tr>
          <td><strong><?= h($tenant['display_name']) ?></strong><br><small><?= h($tenant['slug']) ?></small></td>
          <td>
            <?php foreach ($tenant['domains'] as $domain): ?>
              <div style="margin-bottom:8px;">
                <strong><?= h($domain['domain']) ?></strong>
                <small>(<?= h($domain['domain_type']) ?>) — <?= $domain['verified_at'] ? 'Verified' : 'Unverified' ?></small>
                <?php if ($domain['verified_at']): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="domain_id" value="<?= (int)$domain['id'] ?>">
                    <button class="btn" name="unverify_domain" value="1">Revoke verification</button>
                  </form>
                <?php else: ?>
                  <?php if (empty($domain['verification_token']) || (strtotime((string)$domain['verification_expires_at']) ?: 0) < time()): ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                      <input type="hidden" name="domain_id" value="<?= (int)$domain['id'] ?>">
                      <button class="btn" name="prepare_domain_verification" value="1">Generate DNS challenge</button>
                    </form>
                  <?php else: ?>
                    <div style="padding:8px;background:#f5f7f1;margin:6px 0;">
                      <small>Add this DNS TXT record (expires <?= h($domain['verification_expires_at']) ?>):</small><br>
                      <code>Name: _myvegbasket-verification.<?= h($domain['domain']) ?></code><br>
                      <code>Value: myvegbasket-verification=<?= h($domain['verification_token']) ?></code>
                      <form method="post" style="margin-top:6px;">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="domain_id" value="<?= (int)$domain['id'] ?>">
                        <button class="btn btn-primary" name="verify_domain" value="1">Check DNS TXT &amp; verify</button>
                      </form>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
            <details>
              <summary>Add domain</summary>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="tenant_id" value="<?= (int)$tenant['id'] ?>">
                <div class="form-group"><label>Domain</label><input name="domain" placeholder="shop.example.com" required></div>
                <div class="form-group"><label>Domain type</label>
                  <select name="domain_type" required>
                    <option value="platform_subdomain">Platform subdomain</option>
                    <option value="custom">Custom domain</option>
                  </select>
                </div>
                <button class="btn" name="add_domain" value="1">Add unverified domain</button>
              </form>
            </details>
          </td>
          <td><?= h($tenant['database_name'] ?? '') ?><br><small>Credential ref: <?= h($tenant['credential_ref'] ?? '') ?></small></td>
          <td><?= h(ucfirst($tenant['status'])) ?></td>
          <td>
            <?php if ($tenant['status'] === 'active'): ?>
              <form method="post" onsubmit="return confirm('Suspend this tenant? Its domains will stop routing to its database.');">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="tenant_id" value="<?= (int)$tenant['id'] ?>">
                <input type="hidden" name="status" value="suspended">
                <button class="btn" name="change_status" value="1">Suspend</button>
              </form>
            <?php elseif ($tenant['status'] === 'suspended' || $tenant['status'] === 'provisioning'): ?>
              <form method="post" onsubmit="return confirm('Run database checks and activate this tenant?');">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="tenant_id" value="<?= (int)$tenant['id'] ?>">
                <input type="hidden" name="status" value="active">
                <button class="btn btn-primary" name="change_status" value="1">Preflight &amp; activate</button>
              </form>
            <?php else: ?>
              <small>Decommissioned tenants cannot be reactivated.</small>
            <?php endif; ?>
            <details style="margin-top:8px;">
              <summary>Edit store settings</summary>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="tenant_id" value="<?= (int)$tenant['id'] ?>">
                <div class="form-group"><label>Store display name</label><input name="display_name" value="<?= h($tenant['display_name']) ?>" maxlength="150" required></div>
                <div class="form-group"><label>Notification email</label><input type="email" name="notification_email" value="<?= h($tenant['notification_email']) ?>" required></div>
                <div class="form-group"><label>Currency symbol</label><input name="currency_symbol" value="<?= h($tenant['currency_symbol']) ?>" maxlength="8" required></div>
                <div class="form-group"><label>UPI QR local path</label><input name="payment_qr_image" value="<?= h($tenant['payment_qr_image']) ?>" required></div>
                <div class="form-group"><label>UPI payee name</label><input name="payment_qr_name" value="<?= h($tenant['payment_qr_name']) ?>" maxlength="150" required></div>
                <div class="form-group"><label>Store/location name</label><input name="store_name" value="<?= h($tenant['store_name']) ?>" maxlength="150" required></div>
                <div class="form-group"><label>Latitude</label><input type="number" step="0.0000001" name="store_lat" value="<?= h($tenant['store_lat']) ?>" min="-90" max="90" required></div>
                <div class="form-group"><label>Longitude</label><input type="number" step="0.0000001" name="store_lng" value="<?= h($tenant['store_lng']) ?>" min="-180" max="180" required></div>
                <button class="btn btn-primary" name="update_tenant" value="1">Save settings</button>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tenants): ?><tr><td colspan="5" style="text-align:center;">No tenants registered yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="form-card" style="max-width:900px;">
    <h3>Register tenant</h3>
    <p>Creates a provisioning record and DNS verification challenge. Automatic mode can provision a dedicated cPanel database/user, save its password outside the web root, and copy the current application schema without customer/order data.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <?php if (\MyVegBasket\Platform\CpanelUapiClient::isConfigured()): ?>
        <input type="hidden" name="auto_provision" value="1">
        <div class="alert alert-success">Automatic provisioning is enabled: cPanel will create the isolated database/user, private credentials, and empty application schema.</div>
      <?php else: ?>
        <div class="alert alert-warning">
          Automatic cPanel provisioning is not configured. Ask your hosting administrator to set the UAPI server URL, cPanel username/API token, and a writable credential directory outside the web root. Until then, create the DB/user and credential file manually.
        </div>
      <?php endif; ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 16px;">
        <div class="form-group"><label>Store display name</label><input name="display_name" maxlength="150" required></div>
        <div class="form-group"><label>Unique slug</label><input name="slug" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" maxlength="63" placeholder="green-farms" required></div>
        <div class="form-group"><label>Domain</label><input name="domain" placeholder="shop.example.com" required></div>
        <div class="form-group"><label>Domain type</label>
          <select name="domain_type" required>
            <option value="platform_subdomain">Platform subdomain</option>
            <option value="custom">Custom domain</option>
          </select>
        </div>
        <?php if (!\MyVegBasket\Platform\CpanelUapiClient::isConfigured()): ?>
          <div class="form-group"><label>Tenant database name (created in cPanel)</label><input name="database_name" maxlength="64" required></div>
          <div class="form-group"><label>Database cluster reference</label><input name="database_cluster" maxlength="100" value="cpanel-main" required></div>
          <div class="form-group"><label>Credential reference (private JSON filename, no extension)</label><input name="credential_ref" maxlength="64" placeholder="tenant-green-farms" required></div>
        <?php endif; ?>
        <div class="form-group"><label>Notification email</label><input type="email" name="notification_email" required></div>
        <div class="form-group"><label>Currency symbol</label><input name="currency_symbol" value="₹" maxlength="8" required></div>
        <div class="form-group"><label>UPI QR image local path</label><input name="payment_qr_image" placeholder="/uploads/green-farms-qr.png" required></div>
        <div class="form-group"><label>UPI payee name</label><input name="payment_qr_name" maxlength="150" required></div>
        <div class="form-group"><label>Main store/location name</label><input name="store_name" maxlength="150" required></div>
        <div class="form-group"><label>Latitude</label><input type="number" step="0.0000001" name="store_lat" min="-90" max="90" required></div>
        <div class="form-group"><label>Longitude</label><input type="number" step="0.0000001" name="store_lng" min="-180" max="180" required></div>
      </div>
      <p><strong>Security:</strong> never enter a database password here. Automatic mode generates and writes it to a private credential file outside the web root.</p>
      <button class="btn btn-primary" name="create_tenant" value="1">Create provisioning tenant</button>
    </form>
  </div>
<?php endif; ?>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
