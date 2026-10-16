ALTER TABLE platform_tenant_domains
    ADD COLUMN verification_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER verification_method,
    ADD COLUMN verification_expires_at TIMESTAMP NULL DEFAULT NULL AFTER verification_token;
