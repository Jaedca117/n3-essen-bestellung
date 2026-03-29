<?php

declare(strict_types=1);

final class AppRepository
{
    public function __construct(private readonly PDO $pdo, private readonly string $prefix)
    {
    }

    private function t(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
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

    public function upsertCategory(?int $id, string $name): void
    {
        if ($id) {
            $this->pdo->prepare('UPDATE ' . $this->t('categories') . ' SET name=:n WHERE id=:id')->execute([':n'=>$name,':id'=>$id]);
            return;
        }
        $this->pdo->prepare('INSERT INTO ' . $this->t('categories') . ' (name) VALUES (:n)')->execute([':n'=>$name]);
    }

    public function upsertSupplier(array $data): void
    {
        $payload = [
            ':name' => $data['name'],
            ':category_id' => $data['category_id'],
            ':menu_url' => $data['menu_url'],
            ':phone' => $data['phone'],
            ':is_active' => $data['is_active'],
        ];
        if (!empty($data['id'])) {
            $payload[':id'] = $data['id'];
            $sql = 'UPDATE ' . $this->t('suppliers') . ' SET name=:name, category_id=:category_id, menu_url=:menu_url, phone=:phone, is_active=:is_active WHERE id=:id';
        } else {
            $sql = 'INSERT INTO ' . $this->t('suppliers') . ' (name, category_id, menu_url, phone, is_active) VALUES (:name,:category_id,:menu_url,:phone,:is_active)';
        }
        $this->pdo->prepare($sql)->execute($payload);
    }

    public function recordVote(string $token, int $supplierId): void
    {
        $sql = 'INSERT INTO ' . $this->t('votes') . ' (vote_token, supplier_id, created_at, updated_at)
                VALUES (:t,:s,NOW(),NOW()) ON DUPLICATE KEY UPDATE supplier_id=VALUES(supplier_id), updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([':t'=>$token, ':s'=>$supplierId]);
    }

    public function voteResults(): array
    {
        $sql = 'SELECT s.id, s.name, c.name AS category_name, s.menu_url, s.phone, COUNT(v.id) AS votes
                FROM ' . $this->t('suppliers') . ' s
                LEFT JOIN ' . $this->t('categories') . ' c ON c.id=s.category_id
                LEFT JOIN ' . $this->t('votes') . ' v ON v.supplier_id=s.id
                WHERE s.is_active=1
                GROUP BY s.id,s.name,c.name,s.menu_url,s.phone
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
        $sql = 'INSERT INTO ' . $this->t('orders') . ' (public_id, edit_token, nickname, dish_no, dish_name, price, payment_method, note, confirmed, created_at, updated_at)
                VALUES (:public_id,:edit_token,:nickname,:dish_no,:dish_name,:price,:payment_method,:note,:confirmed,NOW(),NOW())';
        $this->pdo->prepare($sql)->execute([
            ':public_id' => $publicId,
            ':edit_token' => $editToken,
            ':nickname' => $data['nickname'],
            ':dish_no' => $data['dish_no'],
            ':dish_name' => $data['dish_name'],
            ':price' => $data['price'],
            ':payment_method' => $data['payment_method'],
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
        $sql = 'UPDATE ' . $this->t('orders') . ' SET nickname=:nickname,dish_no=:dish_no,dish_name=:dish_name,price=:price,payment_method=:payment_method,note=:note,updated_at=NOW() WHERE edit_token=:token';
        $this->pdo->prepare($sql)->execute([
            ':nickname'=>$data['nickname'], ':dish_no'=>$data['dish_no'], ':dish_name'=>$data['dish_name'],
            ':price'=>$data['price'], ':payment_method'=>$data['payment_method'], ':note'=>$data['note'], ':token'=>$token,
        ]);
    }

    public function deleteOrder(string $token): void
    {
        $this->pdo->prepare('DELETE FROM ' . $this->t('orders') . ' WHERE edit_token=:t')->execute([':t'=>$token]);
    }

    public function orders(): array
    {
        return $this->pdo->query('SELECT * FROM ' . $this->t('orders') . ' ORDER BY created_at ASC')->fetchAll();
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
}
