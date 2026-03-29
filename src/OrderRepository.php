<?php

declare(strict_types=1);

final class OrderRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix
    ) {
    }

    public function createOrder(string $customerName, string $note, array $items): int
    {
        $ordersTable = $this->tablePrefix . 'orders';
        $itemsTable = $this->tablePrefix . 'order_items';

        $this->pdo->beginTransaction();

        try {
            $stmtOrder = $this->pdo->prepare(
                "INSERT INTO `{$ordersTable}` (customer_name, note, created_at) VALUES (:customer_name, :note, NOW())"
            );
            $stmtOrder->execute([
                ':customer_name' => $customerName,
                ':note' => $note,
            ]);

            $orderId = (int) $this->pdo->lastInsertId();

            $stmtItem = $this->pdo->prepare(
                "INSERT INTO `{$itemsTable}` (order_id, item_name, quantity) VALUES (:order_id, :item_name, :quantity)"
            );

            foreach ($items as $item) {
                $stmtItem->execute([
                    ':order_id' => $orderId,
                    ':item_name' => $item['name'],
                    ':quantity' => $item['quantity'],
                ]);
            }

            $this->pdo->commit();

            return $orderId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function listOrders(int $limit = 30): array
    {
        $ordersTable = $this->tablePrefix . 'orders';
        $itemsTable = $this->tablePrefix . 'order_items';

        $stmt = $this->pdo->prepare(
            "SELECT o.id,
                    o.customer_name,
                    o.note,
                    o.created_at,
                    GROUP_CONCAT(CONCAT(i.item_name, ' (', i.quantity, 'x)') SEPARATOR ', ') AS items
             FROM `{$ordersTable}` o
             LEFT JOIN `{$itemsTable}` i ON i.order_id = o.id
             GROUP BY o.id, o.customer_name, o.note, o.created_at
             ORDER BY o.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
