<?php

function ensure_team_portal_access_schema(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_portal_access (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        pin_hash VARCHAR(255) DEFAULT NULL,
        pin_version INT UNSIGNED NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO team_portal_access (id) VALUES (1)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_portal_pin_attempts (
        ip_hash CHAR(64) NOT NULL PRIMARY KEY,
        failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        window_started_at DATETIME NOT NULL,
        locked_until DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_portal_recovery_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_team_portal_recovery_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_portal_recovery_limits (
        ip_hash CHAR(64) NOT NULL PRIMARY KEY,
        request_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        window_started_at DATETIME NOT NULL,
        last_requested_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function team_portal_ip_hash() {
    return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function team_portal_access_settings(PDO $pdo) {
    $stmt = $pdo->query('SELECT pin_hash, pin_version FROM team_portal_access WHERE id = 1');
    return $stmt->fetch() ?: ['pin_hash' => null, 'pin_version' => 1];
}

function team_portal_has_access(PDO $pdo) {
    if (empty($_SESSION['team_portal_verified_at']) || empty($_SESSION['team_portal_pin_version'])) {
        return false;
    }
    if ((int)$_SESSION['team_portal_verified_at'] < time() - 43200) {
        unset($_SESSION['team_portal_verified_at'], $_SESSION['team_portal_pin_version']);
        return false;
    }

    $settings = team_portal_access_settings($pdo);
    if (empty($settings['pin_hash']) || (int)$settings['pin_version'] !== (int)$_SESSION['team_portal_pin_version']) {
        unset($_SESSION['team_portal_verified_at'], $_SESSION['team_portal_pin_version']);
        return false;
    }
    return true;
}

function team_portal_pin_lock_seconds(PDO $pdo, $ipHash) {
    $stmt = $pdo->prepare('SELECT GREATEST(TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), locked_until), 0) FROM team_portal_pin_attempts WHERE ip_hash = ?');
    $stmt->execute([$ipHash]);
    return (int)$stmt->fetchColumn();
}

function record_team_portal_pin_failure(PDO $pdo, $ipHash) {
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT IGNORE INTO team_portal_pin_attempts (ip_hash, failed_attempts, window_started_at) VALUES (?, 0, UTC_TIMESTAMP())');
        $insert->execute([$ipHash]);
        $stmt = $pdo->prepare('SELECT failed_attempts, TIMESTAMPDIFF(SECOND, window_started_at, UTC_TIMESTAMP()) AS window_age FROM team_portal_pin_attempts WHERE ip_hash = ? FOR UPDATE');
        $stmt->execute([$ipHash]);
        $attempt = $stmt->fetch();

        $failures = (int)$attempt['failed_attempts'];
        $windowAge = (int)$attempt['window_age'];
        if ($windowAge >= 900) {
            $failures = 0;
        }
        $failures++;
        $lockedUntil = $failures >= 5 ? ', locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)' : ', locked_until = NULL';
        $update = $pdo->prepare('UPDATE team_portal_pin_attempts SET failed_attempts = ?, window_started_at = IF(? = 1, UTC_TIMESTAMP(), window_started_at)' . $lockedUntil . ' WHERE ip_hash = ?');
        $update->execute([$failures, $windowAge >= 900 ? 1 : 0, $ipHash]);
        $pdo->commit();
        return $failures >= 5;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function clear_team_portal_pin_failures(PDO $pdo, $ipHash) {
    $stmt = $pdo->prepare('DELETE FROM team_portal_pin_attempts WHERE ip_hash = ?');
    $stmt->execute([$ipHash]);
}

function reserve_team_portal_recovery_request(PDO $pdo, $ipHash) {
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT IGNORE INTO team_portal_recovery_limits (ip_hash, request_count, window_started_at, last_requested_at) VALUES (?, 0, UTC_TIMESTAMP(), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE))');
        $insert->execute([$ipHash]);
        $stmt = $pdo->prepare('SELECT request_count, TIMESTAMPDIFF(SECOND, window_started_at, UTC_TIMESTAMP()) AS window_age, TIMESTAMPDIFF(SECOND, last_requested_at, UTC_TIMESTAMP()) AS since_last FROM team_portal_recovery_limits WHERE ip_hash = ? FOR UPDATE');
        $stmt->execute([$ipHash]);
        $limit = $stmt->fetch();

        if ((int)$limit['since_last'] < 60) {
            $pdo->commit();
            return false;
        }

        $count = (int)$limit['request_count'];
        $windowAge = (int)$limit['window_age'];
        if ($windowAge >= 3600) $count = 0;
        if ($count >= 5) {
            $pdo->commit();
            return false;
        }
        $count++;
        $update = $pdo->prepare('UPDATE team_portal_recovery_limits SET request_count = ?, window_started_at = IF(? = 1, UTC_TIMESTAMP(), window_started_at), last_requested_at = UTC_TIMESTAMP() WHERE ip_hash = ?');
        $update->execute([$count, $windowAge >= 3600 ? 1 : 0, $ipHash]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
