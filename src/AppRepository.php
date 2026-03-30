<?php

declare(strict_types=1);

final class AppRepository
{
    public function __construct(private readonly PDO $pdo, private readonly string $prefix)
    {
        $this->ensureSchemaInitialized();
    }

    private function t(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1'
        );
        $stmt->execute([
            ':table' => $this->prefix . $table,
            ':column' => $column,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    private function columnDataType(string $table, string $column): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column
             LIMIT 1'
        );
        $stmt->execute([
            ':table' => $this->prefix . $table,
            ':column' => $column,
        ]);
        $value = $stmt->fetchColumn();
        return $value !== false ? strtolower((string) $value) : null;
    }

    private function ensureSchemaInitialized(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t('settings') . ' (
            setting_key VARCHAR(64) NOT NULL,
            setting_value TEXT NOT NULL,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t('categories') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(80) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_category_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t('suppliers') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            menu_url VARCHAR(255) NOT NULL DEFAULT "",
            order_method VARCHAR(1000) NOT NULL DEFAULT "",
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY idx_category_id (category_id),
            FOREIGN KEY (category_id) REFERENCES ' . $this->t('categories') . ' (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        if (!$this->columnExists('suppliers', 'order_method')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('suppliers') . ' ADD COLUMN order_method VARCHAR(1000) NOT NULL DEFAULT "" AFTER menu_url');
            if ($this->columnExists('suppliers', 'phone')) {
                $this->pdo->exec('UPDATE ' . $this->t('suppliers') . ' SET order_method = phone WHERE order_method = "" AND phone <> ""');
            }
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t('votes') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            vote_token VARCHAR(64) NOT NULL,
            supplier_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_vote_token (vote_token),
            KEY idx_supplier_id (supplier_id),
            FOREIGN KEY (supplier_id) REFERENCES ' . $this->t('suppliers') . ' (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t('orders') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id VARCHAR(12) NOT NULL,
            edit_token VARCHAR(64) NOT NULL,
            created_by_token VARCHAR(64) NOT NULL DEFAULT "",
            nickname VARCHAR(40) NOT NULL,
            dish_no VARCHAR(20) NOT NULL DEFAULT "",
            dish_name VARCHAR(120) NOT NULL,
            dish_size VARCHAR(40) NOT NULL DEFAULT "",
            price DECIMAL(8,2) NOT NULL,
            payment_method ENUM("bar", "paypal") NOT NULL DEFAULT "bar",
            note VARCHAR(200) NOT NULL DEFAULT "",
            confirmed TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_public_id (public_id),
            UNIQUE KEY uniq_edit_token (edit_token),
            KEY idx_created_by_token (created_by_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        if (!$this->columnExists('orders', 'dish_size')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('orders') . ' ADD COLUMN dish_size VARCHAR(40) NOT NULL DEFAULT "" AFTER dish_name');
        }
        if (!$this->columnExists('orders', 'created_by_token')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('orders') . ' ADD COLUMN created_by_token VARCHAR(64) NOT NULL DEFAULT "" AFTER edit_token');
            $this->pdo->exec('ALTER TABLE ' . $this->t('orders') . ' ADD KEY idx_created_by_token (created_by_token)');
        }
        if (!$this->columnExists('orders', 'is_paid')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('orders') . ' ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_method');
        }

        if ($this->columnDataType('orders', 'dish_size') === 'enum') {
            $this->pdo->exec('ALTER TABLE ' . $this->t('orders') . ' MODIFY COLUMN dish_size VARCHAR(40) NOT NULL DEFAULT ""');
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t('admin_users') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(40) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM("admin", "orga") NOT NULL DEFAULT "admin",
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        if (!$this->columnExists('admin_users', 'role')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('admin_users') . ' ADD COLUMN role ENUM("admin", "orga") NOT NULL DEFAULT "admin" AFTER password_hash');
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t('audit_logs') . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_user_id INT UNSIGNED NOT NULL,
            actor_username VARCHAR(40) NOT NULL,
            actor_role ENUM("admin", "orga") NOT NULL,
            action_key VARCHAR(80) NOT NULL,
            target_type VARCHAR(40) NOT NULL,
            target_id VARCHAR(80) NOT NULL DEFAULT "",
            details_json TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_admin_user_id (admin_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t('rate_limits') . ' (
            action_key VARCHAR(160) NOT NULL,
            window_started_at DATETIME NOT NULL,
            request_count INT UNSIGNED NOT NULL,
            PRIMARY KEY (action_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $this->pdo->exec('INSERT IGNORE INTO ' . $this->t('settings') . ' (setting_key, setting_value) VALUES
            ("voting_end_time", "16:00:00"),
            ("order_end_time", "18:00:00"),
            ("daily_reset_time", "10:30:00"),
            ("voting_end_time_monday", "16:00"),
            ("voting_end_time_tuesday", "16:00"),
            ("voting_end_time_wednesday", "16:00"),
            ("voting_end_time_thursday", "16:00"),
            ("voting_end_time_friday", "16:00"),
            ("voting_end_time_saturday", "16:00"),
            ("voting_end_time_sunday", "16:00"),
            ("order_end_time_monday", "18:00"),
            ("order_end_time_tuesday", "18:00"),
            ("order_end_time_wednesday", "18:00"),
            ("order_end_time_thursday", "18:00"),
            ("order_end_time_friday", "18:00"),
            ("order_end_time_saturday", "18:00"),
            ("order_end_time_sunday", "18:00"),
            ("paypal_link", ""),
            ("paypal_links", "[]"),
            ("paypal_link_active_id", ""),
            ("daily_note", ""),
            ("header_subtitle", ""),
            ("order_closed", "0"),
            ("manual_winner_supplier_id", ""),
            ("reset_daily_note", "1"),
            ("last_reset_at", "1970-01-01 00:00:00")');

        $this->pdo->exec('INSERT IGNORE INTO ' . $this->t('categories') . ' (name) VALUES
            ("Italienisch"), ("Griechisch"), ("Burger"), ("Döner"), ("Asiatisch")');

        $this->pdo->exec('INSERT INTO ' . $this->t('admin_users') . ' (username, password_hash, role, created_at)
            SELECT "admin", "$2y$12$GNqw/UBiF19Pd1o5Z2Toke.OTW7T.Pn0veykfJLqDpGcp7a0G.NcG", "admin", NOW()
            WHERE NOT EXISTS (SELECT 1 FROM ' . $this->t('admin_users') . ')');
    }

    private function tableExists(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             LIMIT 1'
        );
        $stmt->execute([':table' => $this->prefix . $name]);
        return (bool) $stmt->fetchColumn();
    }

    private function deleteAllRowsIfTableExists(string $name): void
    {
        if (!$this->tableExists($name)) {
            return;
        }
        $this->pdo->exec('DELETE FROM ' . $this->t($name));
    }

    public function getSettings(): array
    {
        $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM ' . $this->t('settings'));
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public function saveSetting(string $key, string $value): void
    {
        $sql = 'INSERT INTO ' . $this->t('settings') . ' (setting_key, setting_value) VALUES (:k,:v)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
        $this->pdo->prepare($sql)->execute([':k' => $key, ':v' => $value]);
    }

    public function categories(): array
    {
        return $this->pdo->query('SELECT id,name FROM ' . $this->t('categories') . ' ORDER BY name ASC')->fetchAll();
    }

    public function suppliers(): array
    {
        $sql = 'SELECT s.*, c.name AS category_name
                FROM ' . $this->t('suppliers') . ' s
                LEFT JOIN ' . $this->t('categories') . ' c ON c.id=s.category_id
                WHERE s.is_active=1
                ORDER BY c.name ASC, s.name ASC';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function allSuppliers(): array
    {
        $sql = 'SELECT s.*, c.name AS category_name
                FROM ' . $this->t('suppliers') . ' s
                LEFT JOIN ' . $this->t('categories') . ' c ON c.id=s.category_id
                ORDER BY c.name ASC, s.name ASC';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function upsertCategory(?int $id, string $name): void
    {
        if ($id) {
            $this->pdo->prepare('UPDATE ' . $this->t('categories') . ' SET name=:n WHERE id=:id')->execute([':n'=>$name,':id'=>$id]);
            return;
        }
        $this->pdo->prepare('INSERT INTO ' . $this->t('categories') . ' (name) VALUES (:n)')->execute([':n'=>$name]);
    }

    public function deleteCategory(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->t('suppliers') . ' WHERE category_id=:id');
        $stmt->execute([':id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            return false;
        }

        $this->pdo->prepare('DELETE FROM ' . $this->t('categories') . ' WHERE id=:id')->execute([':id' => $id]);
        return true;
    }

    public function upsertSupplier(array $data): void
    {
        $payload = [
            ':name' => $data['name'],
            ':category_id' => $data['category_id'],
            ':menu_url' => $data['menu_url'],
            ':order_method' => $data['order_method'],
            ':is_active' => $data['is_active'],
        ];
        if (!empty($data['id'])) {
            $payload[':id'] = $data['id'];
            $sql = 'UPDATE ' . $this->t('suppliers') . ' SET name=:name, category_id=:category_id, menu_url=:menu_url, order_method=:order_method, is_active=:is_active WHERE id=:id';
        } else {
            $sql = 'INSERT INTO ' . $this->t('suppliers') . ' (name, category_id, menu_url, order_method, is_active) VALUES (:name,:category_id,:menu_url,:order_method,:is_active)';
        }
        $this->pdo->prepare($sql)->execute($payload);
    }

    public function deleteSupplier(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM ' . $this->t('votes') . ' WHERE supplier_id=:id')->execute([':id' => $id]);
            $this->pdo->prepare('DELETE FROM ' . $this->t('suppliers') . ' WHERE id=:id')->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function hasVoteForToken(string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . $this->t('votes') . ' WHERE vote_token=:t LIMIT 1');
        $stmt->execute([':t' => $token]);
        return (bool) $stmt->fetchColumn();
    }

    public function recordVote(string $token, int $supplierId): void
    {
        $sql = 'INSERT INTO ' . $this->t('votes') . ' (vote_token, supplier_id, created_at, updated_at)
                VALUES (:t,:s,NOW(),NOW()) ON DUPLICATE KEY UPDATE supplier_id=VALUES(supplier_id), updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([':t'=>$token, ':s'=>$supplierId]);
    }

    public function voteResults(): array
    {
        $sql = 'SELECT s.id, s.name, c.name AS category_name, s.menu_url, s.order_method, COUNT(v.id) AS votes
                FROM ' . $this->t('suppliers') . ' s
                LEFT JOIN ' . $this->t('categories') . ' c ON c.id=s.category_id
                LEFT JOIN ' . $this->t('votes') . ' v ON v.supplier_id=s.id
                WHERE s.is_active=1
                GROUP BY s.id,s.name,c.name,s.menu_url,s.order_method
                ORDER BY votes DESC, s.id ASC';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function winner(): ?array
    {
        $rows = $this->voteResults();
        if (!$rows) {
            return null;
        }
        $top = (int) $rows[0]['votes'];
        if ($top === 0) {
            return null;
        }
        return $rows[0];
    }

    public function createOrder(array $data): array
    {
        $publicId = strtoupper(bin2hex(random_bytes(4)));
        $editToken = bin2hex(random_bytes(16));
        $sql = 'INSERT INTO ' . $this->t('orders') . ' (public_id, edit_token, created_by_token, nickname, dish_no, dish_name, dish_size, price, payment_method, is_paid, note, confirmed, created_at, updated_at)
                VALUES (:public_id,:edit_token,:created_by_token,:nickname,:dish_no,:dish_name,:dish_size,:price,:payment_method,:is_paid,:note,:confirmed,NOW(),NOW())';
        $this->pdo->prepare($sql)->execute([
            ':public_id' => $publicId,
            ':edit_token' => $editToken,
            ':created_by_token' => (string) ($data['created_by_token'] ?? ''),
            ':nickname' => $data['nickname'],
            ':dish_no' => $data['dish_no'],
            ':dish_name' => $data['dish_name'],
            ':dish_size' => $data['dish_size'],
            ':price' => $data['price'],
            ':payment_method' => $data['payment_method'],
            ':is_paid' => !empty($data['is_paid']) ? 1 : 0,
            ':note' => $data['note'],
            ':confirmed' => 1,
        ]);
        return ['public_id'=>$publicId,'edit_token'=>$editToken];
    }

    public function findOrderByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->t('orders') . ' WHERE edit_token=:t LIMIT 1');
        $stmt->execute([':t'=>$token]);
        return $stmt->fetch() ?: null;
    }

    public function updateOrder(string $token, array $data): void
    {
        $sql = 'UPDATE ' . $this->t('orders') . ' SET nickname=:nickname,dish_no=:dish_no,dish_name=:dish_name,dish_size=:dish_size,price=:price,payment_method=:payment_method,note=:note,updated_at=NOW() WHERE edit_token=:token';
        $this->pdo->prepare($sql)->execute([
            ':nickname'=>$data['nickname'], ':dish_no'=>$data['dish_no'], ':dish_name'=>$data['dish_name'], ':dish_size'=>$data['dish_size'],
            ':price'=>$data['price'], ':payment_method'=>$data['payment_method'], ':note'=>$data['note'], ':token'=>$token,
        ]);
    }

    public function deleteOrder(string $token): void
    {
        $this->pdo->prepare('DELETE FROM ' . $this->t('orders') . ' WHERE edit_token=:t')->execute([':t'=>$token]);
    }

    public function findOrderById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->t('orders') . ' WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function findOrderByIdAndOwnerToken(int $id, string $ownerToken): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->t('orders') . ' WHERE id=:id AND created_by_token=:token LIMIT 1');
        $stmt->execute([':id' => $id, ':token' => $ownerToken]);
        return $stmt->fetch() ?: null;
    }

    public function updateOrderById(int $id, array $data): void
    {
        $sql = 'UPDATE ' . $this->t('orders') . ' SET nickname=:nickname,dish_no=:dish_no,dish_name=:dish_name,dish_size=:dish_size,price=:price,payment_method=:payment_method,is_paid=:is_paid,note=:note,updated_at=NOW() WHERE id=:id';
        $this->pdo->prepare($sql)->execute([
            ':nickname' => $data['nickname'],
            ':dish_no' => $data['dish_no'],
            ':dish_name' => $data['dish_name'],
            ':dish_size' => $data['dish_size'],
            ':price' => $data['price'],
            ':payment_method' => $data['payment_method'],
            ':is_paid' => !empty($data['is_paid']) ? 1 : 0,
            ':note' => $data['note'],
            ':id' => $id,
        ]);
    }

    public function deleteOrderById(int $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . $this->t('orders') . ' WHERE id=:id')->execute([':id' => $id]);
    }

    public function setOrderPaidStatus(int $id, bool $isPaid): void
    {
        $this->pdo->prepare('UPDATE ' . $this->t('orders') . ' SET is_paid=:is_paid, updated_at=NOW() WHERE id=:id')->execute([
            ':is_paid' => $isPaid ? 1 : 0,
            ':id' => $id,
        ]);
    }

    public function updateOrderByIdAndOwnerToken(int $id, string $ownerToken, array $data): void
    {
        $sql = 'UPDATE ' . $this->t('orders') . ' SET nickname=:nickname,dish_no=:dish_no,dish_name=:dish_name,dish_size=:dish_size,price=:price,payment_method=:payment_method,note=:note,updated_at=NOW() WHERE id=:id AND created_by_token=:owner_token';
        $this->pdo->prepare($sql)->execute([
            ':nickname' => $data['nickname'],
            ':dish_no' => $data['dish_no'],
            ':dish_name' => $data['dish_name'],
            ':dish_size' => $data['dish_size'],
            ':price' => $data['price'],
            ':payment_method' => $data['payment_method'],
            ':note' => $data['note'],
            ':id' => $id,
            ':owner_token' => $ownerToken,
        ]);
    }

    public function deleteOrderByIdAndOwnerToken(int $id, string $ownerToken): void
    {
        $this->pdo->prepare('DELETE FROM ' . $this->t('orders') . ' WHERE id=:id AND created_by_token=:token')->execute([
            ':id' => $id,
            ':token' => $ownerToken,
        ]);
    }

    public function orders(): array
    {
        return $this->pdo->query('SELECT * FROM ' . $this->t('orders') . ' ORDER BY created_at ASC')->fetchAll();
    }

    public function ordersByOwnerToken(string $ownerToken): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->t('orders') . ' WHERE created_by_token=:token ORDER BY created_at ASC');
        $stmt->execute([':token' => $ownerToken]);
        return $stmt->fetchAll();
    }

    public function orderTotalsByOwnerToken(string $ownerToken): array
    {
        $stmt = $this->pdo->prepare('SELECT payment_method, SUM(price) AS total FROM ' . $this->t('orders') . ' WHERE created_by_token=:token GROUP BY payment_method');
        $stmt->execute([':token' => $ownerToken]);
        $rows = $stmt->fetchAll();
        $totals = ['bar' => 0.0, 'paypal' => 0.0, 'all' => 0.0];
        foreach ($rows as $row) {
            $k = strtolower((string) $row['payment_method']);
            $totals[$k] = (float) $row['total'];
            $totals['all'] += (float) $row['total'];
        }
        return $totals;
    }

    public function orderTotals(): array
    {
        $sql = 'SELECT payment_method, SUM(price) AS total FROM ' . $this->t('orders') . ' GROUP BY payment_method';
        $rows = $this->pdo->query($sql)->fetchAll();
        $totals = ['bar' => 0.0, 'paypal' => 0.0, 'all' => 0.0];
        foreach ($rows as $row) {
            $k = strtolower((string) $row['payment_method']);
            $totals[$k] = (float) $row['total'];
            $totals['all'] += (float) $row['total'];
        }
        return $totals;
    }

    public function upsertRateLimit(string $actionKey, string $ip, int $windowSec): array
    {
        $rowKey = $actionKey . '|' . $ip;
        $stmt = $this->pdo->prepare('SELECT action_key, window_started_at, request_count FROM ' . $this->t('rate_limits') . ' WHERE action_key=:k LIMIT 1');
        $stmt->execute([':k' => $rowKey]);
        $existing = $stmt->fetch();

        if (!$existing) {
            $this->pdo->prepare('INSERT INTO ' . $this->t('rate_limits') . ' (action_key, window_started_at, request_count) VALUES (:k, NOW(), 1)')->execute([':k' => $rowKey]);
            return ['request_count' => 1];
        }

        $startedAt = new DateTimeImmutable((string) $existing['window_started_at']);
        $isNewWindow = $startedAt->getTimestamp() <= (time() - $windowSec);
        $count = $isNewWindow ? 1 : ((int) $existing['request_count'] + 1);
        $sql = 'UPDATE ' . $this->t('rate_limits') . ' SET window_started_at=:started, request_count=:count WHERE action_key=:k';
        $this->pdo->prepare($sql)->execute([
            ':started' => $isNewWindow ? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') : $startedAt->format('Y-m-d H:i:s'),
            ':count' => $count,
            ':k' => $rowKey,
        ]);

        return ['request_count' => $count];
    }

    public function resetDaily(bool $resetNote): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->deleteAllRowsIfTableExists('votes');
            $this->deleteAllRowsIfTableExists('order_items');
            $this->deleteAllRowsIfTableExists('orders');
            $this->saveSetting('order_closed', '0');
            $this->saveSetting('manual_winner_supplier_id', '');
            if ($resetNote) {
                $this->saveSetting('daily_note', '');
            }
            $this->saveSetting('last_reset_at', (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function findAdminByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->t('admin_users') . ' WHERE username=:u LIMIT 1');
        $stmt->execute([':u'=>$username]);
        return $stmt->fetch() ?: null;
    }

    public function findAdminById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->t('admin_users') . ' WHERE id=:id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function allAdminUsers(): array
    {
        return $this->pdo->query('SELECT * FROM ' . $this->t('admin_users') . ' ORDER BY username ASC')->fetchAll();
    }

    public function createAdminUser(string $username, string $passwordHash, string $role): void
    {
        $sql = 'INSERT INTO ' . $this->t('admin_users') . ' (username, password_hash, role, created_at) VALUES (:username, :password_hash, :role, NOW())';
        $this->pdo->prepare($sql)->execute([
            ':username' => $username,
            ':password_hash' => $passwordHash,
            ':role' => $role,
        ]);
    }

    public function updateAdminUser(int $id, string $username, string $role, ?string $passwordHash = null): void
    {
        if ($passwordHash !== null) {
            $sql = 'UPDATE ' . $this->t('admin_users') . ' SET username=:username, role=:role, password_hash=:password_hash WHERE id=:id';
            $this->pdo->prepare($sql)->execute([
                ':username' => $username,
                ':role' => $role,
                ':password_hash' => $passwordHash,
                ':id' => $id,
            ]);
            return;
        }

        $sql = 'UPDATE ' . $this->t('admin_users') . ' SET username=:username, role=:role WHERE id=:id';
        $this->pdo->prepare($sql)->execute([
            ':username' => $username,
            ':role' => $role,
            ':id' => $id,
        ]);
    }

    public function deleteAdminUser(int $id): void
    {
        $this->pdo->prepare('DELETE FROM ' . $this->t('admin_users') . ' WHERE id=:id')->execute([':id' => $id]);
    }

    public function logAdminAction(int $adminUserId, string $actorUsername, string $actorRole, string $actionKey, string $targetType, string $targetId, array $details = []): void
    {
        $sql = 'INSERT INTO ' . $this->t('audit_logs') . ' (admin_user_id, actor_username, actor_role, action_key, target_type, target_id, details_json, created_at)
                VALUES (:admin_user_id, :actor_username, :actor_role, :action_key, :target_type, :target_id, :details_json, NOW())';
        $this->pdo->prepare($sql)->execute([
            ':admin_user_id' => $adminUserId,
            ':actor_username' => $actorUsername,
            ':actor_role' => $actorRole,
            ':action_key' => $actionKey,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function purgeOldAuditLogs(int $days): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->t('audit_logs') . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)');
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function auditLogsLastDays(int $days): array
    {
        $sql = 'SELECT *
                FROM ' . $this->t('audit_logs') . '
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY created_at DESC, id DESC
                LIMIT 500';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
