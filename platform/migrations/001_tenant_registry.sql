CREATE TABLE platform_tenants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    status ENUM('provisioning', 'active', 'suspended', 'decommissioned') NOT NULL DEFAULT 'provisioning',
    database_cluster VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    database_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    credential_ref VARCHAR(255) DEFAULT NULL,
    notification_email VARCHAR(254) DEFAULT NULL,
    currency_symbol VARCHAR(8) NOT NULL DEFAULT '₹',
    payment_qr_image VARCHAR(255) DEFAULT NULL,
    payment_qr_name VARCHAR(150) DEFAULT NULL,
    store_name VARCHAR(150) DEFAULT NULL,
    store_lat DECIMAL(10, 7) DEFAULT NULL,
    store_lng DECIMAL(10, 7) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_tenants_slug (slug),
    KEY idx_platform_tenants_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE platform_tenant_domains (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    domain VARCHAR(253) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    domain_type ENUM('platform_subdomain', 'custom') NOT NULL,
    verification_method ENUM('platform_dns', 'dns_txt', 'http_file') DEFAULT NULL,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_tenant_domains_domain (domain),
    KEY idx_platform_tenant_domains_tenant (tenant_id),
    CONSTRAINT fk_platform_tenant_domains_tenant
        FOREIGN KEY (tenant_id) REFERENCES platform_tenants (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
