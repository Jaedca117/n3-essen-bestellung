<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connect($config['db']);
$tablePrefix = (string) ($config['db']['table_prefix'] ?? 'n3_essen_');
$repo = new OrderRepository($pdo, $tablePrefix);

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));
    $rawItems = $_POST['items'] ?? [];

    $items = [];
    if (is_array($rawItems)) {
        foreach ($rawItems as $raw) {
            $name = trim((string) ($raw['name'] ?? ''));
            $quantity = (int) ($raw['quantity'] ?? 0);
            if ($name !== '' && $quantity > 0) {
                $items[] = ['name' => $name, 'quantity' => $quantity];
            }
        }
    }

    if ($customerName === '') {
        $error = 'Bitte einen Namen angeben.';
    } elseif ($items === []) {
        $error = 'Bitte mindestens ein Gericht mit Menge angeben.';
    } else {
        try {
            $orderId = $repo->createOrder($customerName, $note, $items);
            $message = 'Bestellung #' . $orderId . ' wurde gespeichert.';
        } catch (Throwable $e) {
            $error = 'Speichern fehlgeschlagen: ' . $e->getMessage();
        }
    }
}

$orders = $repo->listOrders();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars((string) ($config['app_name'] ?? 'Essen-Bestellung')) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <h1><?= htmlspecialchars((string) ($config['app_name'] ?? 'Essen-Bestellung')) ?></h1>

    <?php if ($message): ?>
        <p class="notice success"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="notice error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <section class="card">
        <h2>Neue Bestellung</h2>
        <form method="post">
            <label>
                Name
                <input type="text" name="customer_name" required>
            </label>

            <div class="items">
                <p>Gerichte</p>
                <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="row">
                        <input type="text" name="items[<?= $i ?>][name]" placeholder="Gericht <?= $i + 1 ?>">
                        <input type="number" min="0" name="items[<?= $i ?>][quantity]" placeholder="Menge">
                    </div>
                <?php endfor; ?>
            </div>

            <label>
                Notiz
                <textarea name="note" rows="3" placeholder="Optional"></textarea>
            </label>

            <button type="submit">Bestellung speichern</button>
        </form>
    </section>

    <section class="card">
        <h2>Letzte Bestellungen</h2>
        <?php if ($orders === []): ?>
            <p>Noch keine Bestellungen vorhanden.</p>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Gerichte</th>
                    <th>Notiz</th>
                    <th>Zeit</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= (int) $order['id'] ?></td>
                        <td><?= htmlspecialchars((string) $order['customer_name']) ?></td>
                        <td><?= htmlspecialchars((string) ($order['items'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($order['note'] ?: '-')) ?></td>
                        <td><?= htmlspecialchars((string) $order['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
