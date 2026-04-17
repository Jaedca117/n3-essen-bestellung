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

    private function indexExists(string $table, string $indexName): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND INDEX_NAME = :index
             LIMIT 1'
        );
        $stmt->execute([
            ':table' => $this->prefix . $table,
            ':index' => $indexName,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    private function applySchemaFromSqlFile(): void
    {
        $schemaPath = dirname(__DIR__) . '/sql/schema.sql';
        if (!is_file($schemaPath)) {
            throw new RuntimeException('Schema-Datei fehlt: ' . $schemaPath);
        }

        $sql = (string) file_get_contents($schemaPath);
        $sql = str_replace('`n3_essen_', '`' . $this->prefix, $sql);
        $sql = str_replace('n3_essen_', $this->prefix, $sql);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement === '') {
                continue;
            }
            $this->pdo->exec($statement);
        }
    }

    private function ensureSchemaInitialized(): void
    {
        $this->applySchemaFromSqlFile();

        if (!$this->columnExists('suppliers', 'order_method')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('suppliers') . ' ADD COLUMN order_method VARCHAR(1000) NOT NULL DEFAULT "" AFTER menu_url');
            if ($this->columnExists('suppliers', 'phone')) {
                $this->pdo->exec('UPDATE ' . $this->t('suppliers') . ' SET order_method = phone WHERE order_method = "" AND phone <> ""');
            }
        }


        if (!$this->tableExists('supplier_weekdays')) {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t('supplier_weekdays') . ' (
                supplier_id INT UNSIGNED NOT NULL,
                weekday_key VARCHAR(10) NOT NULL,
                PRIMARY KEY (supplier_id, weekday_key),
                KEY idx_weekday_key (weekday_key),
                CONSTRAINT fk_supplier_weekdays_supplier FOREIGN KEY (supplier_id) REFERENCES ' . $this->t('suppliers') . ' (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }
        if (!$this->tableExists('admin_user_weekdays')) {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->t('admin_user_weekdays') . ' (
                admin_user_id INT UNSIGNED NOT NULL,
                weekday_key VARCHAR(10) NOT NULL,
                PRIMARY KEY (admin_user_id, weekday_key),
                KEY idx_admin_weekday_key (weekday_key),
                CONSTRAINT fk_admin_user_weekdays_user FOREIGN KEY (admin_user_id) REFERENCES ' . $this->t('admin_users') . ' (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }

        if ($this->indexExists('votes', 'uniq_vote_token')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('votes') . ' DROP INDEX uniq_vote_token');
        }
        if (!$this->indexExists('votes', 'uniq_vote_token_supplier')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('votes') . ' ADD UNIQUE KEY uniq_vote_token_supplier (vote_token, supplier_id)');
        }
        if (!$this->indexExists('votes', 'idx_vote_token')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('votes') . ' ADD KEY idx_vote_token (vote_token)');
        }

        if (!$this->columnExists('orders', 'dish_size')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('orders') . ' ADD COLUMN dish_size VARCHAR(40) NOT NULL DEFAULT "" AFTER dish_name');
        }
        if (!$this->columnExists('orders', 'created_by_token')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('orders') . ' ADD COLUMN created_by_token VARCHAR(64) NOT NULL DEFAULT "" AFTER id');
            $this->pdo->exec('ALTER TABLE ' . $this->t('orders') . ' ADD KEY idx_created_by_token (created_by_token)');
        }
        if (!$this->columnExists('orders', 'is_paid')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('orders') . ' ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_method');
        }

        if ($this->columnDataType('orders', 'dish_size') === 'enum') {
            $this->pdo->exec('ALTER TABLE ' . $this->t('orders') . ' MODIFY COLUMN dish_size VARCHAR(40) NOT NULL DEFAULT ""');
        }

        if (!$this->columnExists('admin_users', 'role')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('admin_users') . ' ADD COLUMN role ENUM("admin", "orga") NOT NULL DEFAULT "admin" AFTER password_hash');
        }
        if ($this->columnExists('admin_users', 'created_at')) {
            $this->pdo->exec('ALTER TABLE ' . $this->t('admin_users') . ' DROP COLUMN created_at');
        }

        if ($this->columnExists('suppliers', 'available_weekdays')) {
            $rows = $this->pdo->query('SELECT id, available_weekdays FROM ' . $this->t('suppliers'))->fetchAll();
            foreach ($rows as $row) {
                $this->syncSupplierWeekdays((int) $row['id'], (string) ($row['available_weekdays'] ?? ''));
            }
            $this->pdo->exec('ALTER TABLE ' . $this->t('suppliers') . ' DROP COLUMN available_weekdays');
        }
        if ($this->columnExists('admin_users', 'editable_weekdays')) {
            $rows = $this->pdo->query('SELECT id, editable_weekdays FROM ' . $this->t('admin_users'))->fetchAll();
            foreach ($rows as $row) {
                $this->syncAdminUserWeekdays((int) $row['id'], (string) ($row['editable_weekdays'] ?? ''));
            }
            $this->pdo->exec('ALTER TABLE ' . $this->t('admin_users') . ' DROP COLUMN editable_weekdays');
        }
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

    /**
     * @return list<string>
     */
    private function weekdayListFromCsv(string $csv): array
    {
        $valid = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $result = [];
        foreach (explode(',', $csv) as $raw) {
            $weekday = trim($raw);
            if ($weekday !== '' && in_array($weekday, $valid, true)) {
                $result[$weekday] = true;
            }
        }
        return array_values(array_keys($result));
    }

    private function syncSupplierWeekdays(int $supplierId, string $csvWeekdays): void
    {
        if ($supplierId <= 0) {
            return;
        }
        $this->pdo->prepare('DELETE FROM ' . $this->t('supplier_weekdays') . ' WHERE supplier_id=:id')->execute([':id' => $supplierId]);
        foreach ($this->weekdayListFromCsv($csvWeekdays) as $weekdayKey) {
            $this->pdo->prepare(
                'INSERT INTO ' . $this->t('supplier_weekdays') . ' (supplier_id, weekday_key) VALUES (:supplier_id,:weekday_key)'
            )->execute([
                ':supplier_id' => $supplierId,
                ':weekday_key' => $weekdayKey,
            ]);
        }
    }

    private function syncAdminUserWeekdays(int $adminUserId, string $csvWeekdays): void
    {
        if ($adminUserId <= 0) {
            return;
        }
        $this->pdo->prepare('DELETE FROM ' . $this->t('admin_user_weekdays') . ' WHERE admin_user_id=:id')->execute([':id' => $adminUserId]);
        foreach ($this->weekdayListFromCsv($csvWeekdays) as $weekdayKey) {
            $this->pdo->prepare(
                'INSERT INTO ' . $this->t('admin_user_weekdays') . ' (admin_user_id, weekday_key) VALUES (:admin_user_id,:weekday_key)'
            )->execute([
                ':admin_user_id' => $adminUserId,
                ':weekday_key' => $weekdayKey,
            ]);
        }
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
        if (!$this->isAllowedSettingKey($key)) {
            throw new InvalidArgumentException('Unbekannter Setting-Key: ' . $key);
        }
        $value = $this->normalizeSettingValue($key, $value);
        $sql = 'INSERT INTO ' . $this->t('settings') . ' (setting_key, setting_value) VALUES (:k,:v)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)';
        $this->pdo->prepare($sql)->execute([':k' => $key, ':v' => $value]);
    }

    private function isAllowedSettingKey(string $key): bool
    {
        $static = [
            'daily_reset_time',
            'paypal_links',
            'daily_note',
            'header_subtitle',
            'day_disabled_notice',
            'manual_winner_supplier_id',
            'reset_daily_note',
            'last_reset_at',
        ];
        if (in_array($key, $static, true)) {
            return true;
        }

        return preg_match('/^(voting_end_time|order_end_time|day_disabled|paypal_link_active_id|exclude_last_week_supplier|last_supplier_id)_(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/', $key) === 1;
    }

    private function normalizeSettingValue(string $key, string $value): string
    {
        $value = trim($value);
        if (preg_match('/^(voting_end_time|order_end_time)_(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/', $key) === 1 || $key === 'daily_reset_time') {
            if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value) !== 1) {
                throw new InvalidArgumentException('Ungültiger Zeitwert für ' . $key);
            }
            return strlen($value) === 5 ? $value : substr($value, 0, 8);
        }

        if (preg_match('/^day_disabled_(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/', $key) === 1 || $key === 'reset_daily_note') {
            return $value === '1' ? '1' : '0';
        }
        if (preg_match('/^exclude_last_week_supplier_(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/', $key) === 1) {
            return $value === '1' ? '1' : '0';
        }
        if (preg_match('/^last_supplier_id_(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/', $key) === 1) {
            return ctype_digit($value) ? $value : '';
        }

        if ($key === 'paypal_links') {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('paypal_links muss ein JSON-Array sein.');
            }
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    public function categories(): array
    {
        return $this->pdo->query('SELECT id,name FROM ' . $this->t('categories') . ' ORDER BY name ASC')->fetchAll();
    }

    public function suppliers(): array
    {
        $sql = 'SELECT s.id, s.category_id, s.name, s.menu_url, s.order_method, s.is_active, c.name AS category_name,
                       COALESCE(GROUP_CONCAT(sw.weekday_key ORDER BY sw.weekday_key SEPARATOR ","), "") AS available_weekdays
                FROM ' . $this->t('suppliers') . ' s
                LEFT JOIN ' . $this->t('categories') . ' c ON c.id=s.category_id
                LEFT JOIN ' . $this->t('supplier_weekdays') . ' sw ON sw.supplier_id=s.id
                WHERE s.is_active=1
                GROUP BY s.id, s.category_id, s.name, s.menu_url, s.order_method, s.is_active, c.name
                ORDER BY c.name ASC, s.name ASC';
        $rows = $this->pdo->query($sql)->fetchAll();
        $weekdayKey = current_weekday_key();
        return array_values(array_filter($rows, static fn(array $supplier): bool => supplier_available_on_weekday($supplier, $weekdayKey)));
    }

    public function allSuppliers(): array
    {
        $sql = 'SELECT s.id, s.category_id, s.name, s.menu_url, s.order_method, s.is_active, c.name AS category_name,
                       COALESCE(GROUP_CONCAT(sw.weekday_key ORDER BY sw.weekday_key SEPARATOR ","), "") AS available_weekdays
                FROM ' . $this->t('suppliers') . ' s
                LEFT JOIN ' . $this->t('categories') . ' c ON c.id=s.category_id
                LEFT JOIN ' . $this->t('supplier_weekdays') . ' sw ON sw.supplier_id=s.id
                GROUP BY s.id, s.category_id, s.name, s.menu_url, s.order_method, s.is_active, c.name
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
        $supplierId = (int) ($data['id'] ?? 0);
        if ($supplierId <= 0) {
            $supplierId = (int) $this->pdo->lastInsertId();
        }
        $this->syncSupplierWeekdays($supplierId, (string) ($data['available_weekdays'] ?? ''));
    }

    public function deleteSupplier(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM ' . $this->t('votes') . ' WHERE supplier_id=:id')->execute([':id' => $id]);
            $this->pdo->prepare('DELETE FROM ' . $this->t('supplier_ratings') . ' WHERE supplier_id=:id')->execute([':id' => $id]);
            $this->pdo->prepare('DELETE FROM ' . $this->t('suppliers') . ' WHERE id=:id')->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function voteCountForToken(string $token): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->t('votes') . ' WHERE vote_token=:t');
        $stmt->execute([':t' => $token]);
        return (int) $stmt->fetchColumn();
    }

    public function hasVoteForTokenAndSupplier(string $token, int $supplierId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . $this->t('votes') . ' WHERE vote_token=:t AND supplier_id=:s LIMIT 1');
        $stmt->execute([':t' => $token, ':s' => $supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    public function recordVote(string $token, int $supplierId): void
    {
        $sql = 'INSERT INTO ' . $this->t('votes') . ' (vote_token, supplier_id, created_at, updated_at)
                VALUES (:t,:s,NOW(),NOW())';
        $this->pdo->prepare($sql)->execute([':t' => $token, ':s' => $supplierId]);
    }

    public function voteResults(): array
    {
        $sql = 'SELECT s.id, s.name, c.name AS category_name, s.menu_url, s.order_method,
                       COALESCE(GROUP_CONCAT(DISTINCT sw.weekday_key ORDER BY sw.weekday_key SEPARATOR ","), "") AS available_weekdays,
                       COUNT(DISTINCT v.id) AS votes
                FROM ' . $this->t('suppliers') . ' s
                LEFT JOIN ' . $this->t('categories') . ' c ON c.id=s.category_id
                LEFT JOIN ' . $this->t('votes') . ' v ON v.supplier_id=s.id
                LEFT JOIN ' . $this->t('supplier_weekdays') . ' sw ON sw.supplier_id=s.id
                WHERE s.is_active=1
                GROUP BY s.id,s.name,c.name,s.menu_url,s.order_method
                ORDER BY votes DESC, s.id ASC';
        $rows = $this->pdo->query($sql)->fetchAll();
        $weekdayKey = current_weekday_key();
        return array_values(array_filter($rows, static fn(array $supplier): bool => supplier_available_on_weekday($supplier, $weekdayKey)));
    }

    public function hasRatingForTokenAndSupplier(string $token, int $supplierId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . $this->t('supplier_ratings') . ' WHERE vote_token=:t AND supplier_id=:s LIMIT 1');
        $stmt->execute([':t' => $token, ':s' => $supplierId]);
        return (bool) $stmt->fetchColumn();
    }

    public function recordSupplierRating(string $token, int $supplierId, int $rating): void
    {
        $sql = 'INSERT INTO ' . $this->t('supplier_ratings') . ' (vote_token, supplier_id, rating, created_at, updated_at)
                VALUES (:t,:s,:r,NOW(),NOW())';
        $this->pdo->prepare($sql)->execute([
            ':t' => $token,
            ':s' => $supplierId,
            ':r' => $rating,
        ]);
    }

    public function supplierRatingStatsBySupplierId(): array
    {
        $sql = 'SELECT supplier_id, COUNT(*) AS rating_count, SUM(rating) AS rating_sum, AVG(rating) AS rating_avg
                FROM ' . $this->t('supplier_ratings') . '
                GROUP BY supplier_id';
        $rows = $this->pdo->query($sql)->fetchAll();
        $stats = [];
        foreach ($rows as $row) {
            $supplierId = (int) ($row['supplier_id'] ?? 0);
            if ($supplierId <= 0) {
                continue;
            }
            $stats[$supplierId] = [
                'count' => (int) ($row['rating_count'] ?? 0),
                'sum' => (int) ($row['rating_sum'] ?? 0),
                'avg' => (float) ($row['rating_avg'] ?? 0.0),
            ];
        }
        return $stats;
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

    public function createOrder(array $data): void
    {
        $sql = 'INSERT INTO ' . $this->t('orders') . ' (created_by_token, nickname, dish_no, dish_name, dish_size, price, payment_method, is_paid, note, created_at, updated_at)
                VALUES (:created_by_token,:nickname,:dish_no,:dish_name,:dish_size,:price,:payment_method,:is_paid,:note,NOW(),NOW())';
        $this->pdo->prepare($sql)->execute([
            ':created_by_token' => (string) ($data['created_by_token'] ?? ''),
            ':nickname' => $data['nickname'],
            ':dish_no' => $data['dish_no'],
            ':dish_name' => $data['dish_name'],
            ':dish_size' => $data['dish_size'],
            ':price' => $data['price'],
            ':payment_method' => $data['payment_method'],
            ':is_paid' => !empty($data['is_paid']) ? 1 : 0,
            ':note' => $data['note'],
        ]);
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

    public function hasOrdersByOwnerToken(string $ownerToken): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM ' . $this->t('orders') . ' WHERE created_by_token=:token LIMIT 1');
        $stmt->execute([':token' => $ownerToken]);
        return (bool) $stmt->fetchColumn();
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
            $this->deleteAllRowsIfTableExists('orders');
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
        $stmt = $this->pdo->prepare(
            'SELECT au.*,
                    COALESCE(GROUP_CONCAT(auw.weekday_key ORDER BY auw.weekday_key SEPARATOR ","), "") AS editable_weekdays
             FROM ' . $this->t('admin_users') . ' au
             LEFT JOIN ' . $this->t('admin_user_weekdays') . ' auw ON auw.admin_user_id = au.id
             WHERE au.username=:u
             GROUP BY au.id, au.username, au.password_hash, au.role
             LIMIT 1'
        );
        $stmt->execute([':u'=>$username]);
        return $stmt->fetch() ?: null;
    }

    public function findAdminById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT au.*,
                    COALESCE(GROUP_CONCAT(auw.weekday_key ORDER BY auw.weekday_key SEPARATOR ","), "") AS editable_weekdays
             FROM ' . $this->t('admin_users') . ' au
             LEFT JOIN ' . $this->t('admin_user_weekdays') . ' auw ON auw.admin_user_id = au.id
             WHERE au.id=:id
             GROUP BY au.id, au.username, au.password_hash, au.role
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function adminUserCount(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->t('admin_users'));
        return (int) $stmt->fetchColumn();
    }

    public function allAdminUsers(): array
    {
        $sql = 'SELECT au.*,
                       COALESCE(GROUP_CONCAT(auw.weekday_key ORDER BY auw.weekday_key SEPARATOR ","), "") AS editable_weekdays
                FROM ' . $this->t('admin_users') . ' au
                LEFT JOIN ' . $this->t('admin_user_weekdays') . ' auw ON auw.admin_user_id = au.id
                GROUP BY au.id, au.username, au.password_hash, au.role
                ORDER BY au.username ASC';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function createAdminUser(string $username, string $passwordHash, string $role, string $editableWeekdays): void
    {
        $sql = 'INSERT INTO ' . $this->t('admin_users') . ' (username, password_hash, role) VALUES (:username, :password_hash, :role)';
        $this->pdo->prepare($sql)->execute([
            ':username' => $username,
            ':password_hash' => $passwordHash,
            ':role' => $role,
        ]);
        $this->syncAdminUserWeekdays((int) $this->pdo->lastInsertId(), $editableWeekdays);
    }

    public function updateAdminUser(int $id, string $username, string $role, string $editableWeekdays, ?string $passwordHash = null): void
    {
        if ($passwordHash !== null) {
            $sql = 'UPDATE ' . $this->t('admin_users') . ' SET username=:username, role=:role, password_hash=:password_hash WHERE id=:id';
            $this->pdo->prepare($sql)->execute([
                ':username' => $username,
                ':role' => $role,
                ':password_hash' => $passwordHash,
                ':id' => $id,
            ]);
            $this->syncAdminUserWeekdays($id, $editableWeekdays);
            return;
        }

        $sql = 'UPDATE ' . $this->t('admin_users') . ' SET username=:username, role=:role WHERE id=:id';
        $this->pdo->prepare($sql)->execute([
            ':username' => $username,
            ':role' => $role,
            ':id' => $id,
        ]);
        $this->syncAdminUserWeekdays($id, $editableWeekdays);
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
