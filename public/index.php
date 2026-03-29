<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connect($config['db']);
$repo = new AppRepository($pdo, (string) ($config['db']['table_prefix'] ?? 'n3_essen_'));
$service = new AppService($repo);
$state = $service->runtimeState();

$message = null;
$error = null;
$editOrder = null;

if (empty($_COOKIE['vote_token'])) {
    $voteToken = bin2hex(random_bytes(16));
    setcookie('vote_token', $voteToken, ['expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['vote_token'] = $voteToken;
}

if (isset($_GET['edit']) && preg_match('/^[a-f0-9]{32}$/', (string) $_GET['edit'])) {
    $editOrder = $repo->findOrderByToken((string) $_GET['edit']);
    if (!$editOrder) {
        $error = 'Ungültiger Bearbeitungs-Token.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'vote') {
        if ($state['phase'] !== 'voting') {
            $error = 'Die Abstimmungsphase ist beendet.';
        } elseif (!$service->canProceed('vote', client_ip())) {
            $error = 'Zu viele Abstimmungen in kurzer Zeit. Bitte kurz warten.';
        } else {
            $supplierId = (int) ($_POST['supplier_id'] ?? 0);
            $ids = array_map(static fn($r) => (int) $r['id'], $repo->suppliers());
            if (!in_array($supplierId, $ids, true)) {
                $error = 'Ungültiger Lieferant.';
            } else {
                $repo->recordVote((string) $_COOKIE['vote_token'], $supplierId);
                $message = 'Danke! Deine Stimme wurde gespeichert.';
            }
        }
    }

    if (in_array($action, ['order_create', 'order_update', 'order_delete'], true)) {
        if ($state['phase'] !== 'ordering') {
            $error = 'Bestellungen sind aktuell nicht möglich.';
        } elseif (!$service->canProceed($action === 'order_create' ? 'order_create' : 'order_update', client_ip())) {
            $error = 'Zu viele Aktionen in kurzer Zeit. Bitte kurz warten.';
        } else {
            $token = (string) ($_POST['edit_token'] ?? '');
            $payload = [
                'nickname' => trim((string) ($_POST['nickname'] ?? '')),
                'dish_no' => trim((string) ($_POST['dish_no'] ?? '')),
                'dish_name' => trim((string) ($_POST['dish_name'] ?? '')),
                'dish_size' => trim((string) ($_POST['dish_size'] ?? '')),
                'price' => (float) ($_POST['price'] ?? 0),
                'payment_method' => (string) ($_POST['payment_method'] ?? 'bar'),
                'note' => trim((string) ($_POST['note'] ?? '')),
            ];

            $errors = [];
            if (mb_strlen($payload['nickname']) < 2 || mb_strlen($payload['nickname']) > 40) $errors[] = 'Name muss 2-40 Zeichen haben.';
            if (mb_strlen($payload['dish_no']) > 20) $errors[] = 'Essensnummer ist zu lang.';
            if (mb_strlen($payload['dish_name']) < 2 || mb_strlen($payload['dish_name']) > 120) $errors[] = 'Gericht muss 2-120 Zeichen haben.';
            if (mb_strlen($payload['dish_size']) > 40) $errors[] = 'Größe darf höchstens 40 Zeichen haben.';
            if ($payload['price'] <= 0 || $payload['price'] > 999) $errors[] = 'Preis muss zwischen 0,01 und 999 liegen.';
            if (!in_array($payload['payment_method'], ['bar', 'paypal'], true)) $errors[] = 'Ungültige Zahlungsart.';
            if (!$state['paypal_enabled'] && $payload['payment_method'] === 'paypal') $errors[] = 'PayPal ist heute nicht verfügbar.';
            if (mb_strlen($payload['note']) > 200) $errors[] = 'Bemerkung darf höchstens 200 Zeichen haben.';
            if (empty($_POST['confirmed']) && $action !== 'order_delete') $errors[] = 'Bitte verbindliche Bestellung bestätigen.';

            if ($errors) {
                $error = implode(' ', $errors);
            } elseif ($action === 'order_create') {
                $created = $repo->createOrder($payload);
                $message = 'Bestellung gespeichert. Bearbeitungs-Token: ' . $created['edit_token'];
            } elseif ($action === 'order_update') {
                if (!preg_match('/^[a-f0-9]{32}$/', $token) || !$repo->findOrderByToken($token)) {
                    $error = 'Ungültiger Bearbeitungs-Token.';
                } else {
                    $repo->updateOrder($token, $payload);
                    $message = 'Bestellung wurde aktualisiert.';
                }
            } elseif ($action === 'order_delete') {
                if (!preg_match('/^[a-f0-9]{32}$/', $token) || !$repo->findOrderByToken($token)) {
                    $error = 'Ungültiger Bearbeitungs-Token.';
                } else {
                    $repo->deleteOrder($token);
                    $message = 'Bestellung wurde gelöscht.';
                }
            }
        }
    }

    $state = $service->runtimeState();
}

$settings = $state['settings'];
$suppliers = $repo->suppliers();
$voteResults = $repo->voteResults();
$winner = $service->winner($settings);
$orders = $repo->orders();
$totals = $repo->orderTotals();

$groupedSuppliers = [];
foreach ($suppliers as $supplier) {
    $groupedSuppliers[$supplier['category_name'] ?? 'Sonstiges'][] = $supplier;
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e((string) ($config['app_name'] ?? 'Vereins-Essen')) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="container">
    <header class="hero">
        <h1><?= e((string) ($config['app_name'] ?? 'Vereins-Essen')) ?></h1>
        <p>Phase: <strong><?= $state['phase'] === 'voting' ? 'Abstimmung offen' : ($state['phase'] === 'ordering' ? 'Bestellphase offen' : 'Geschlossen') ?></strong></p>
        <p>Abstimmung bis <?= e($state['voting_end']->format('H:i')) ?> Uhr · Bestellung bis <?= e($state['order_end']->format('H:i')) ?> Uhr</p>
        <?php if (!empty($settings['daily_note'])): ?><p class="notice info"><strong>Tageshinweis:</strong> <?= e((string) $settings['daily_note']) ?></p><?php endif; ?>
        <p><a href="print.php">Druckansicht</a> · <a href="admin.php">Admin</a></p>
    </header>

    <?php if ($message): ?><p class="notice success"><?= e($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>

    <?php if ($state['phase'] === 'voting'): ?>
        <section class="card">
            <h2>1) Abstimmen</h2>
            <?php foreach ($groupedSuppliers as $category => $items): ?>
                <h3><?= e((string) $category) ?></h3>
                <div class="supplier-grid">
                    <?php foreach ($items as $supplier): ?>
                        <form method="post" class="supplier-card">
                            <input type="hidden" name="action" value="vote">
                            <input type="hidden" name="supplier_id" value="<?= (int) $supplier['id'] ?>">
                            <strong><?= e((string) $supplier['name']) ?></strong>
                            <small><?= e((string) $supplier['phone']) ?></small>
                            <a href="<?= e((string) $supplier['menu_url']) ?>" target="_blank" rel="noopener">Speisekarte</a>
                            <button type="submit">Dafür stimmen</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Zwischenstand</h2>
        <table>
            <thead><tr><th>Lieferant</th><th>Kategorie</th><th>Stimmen</th></tr></thead>
            <tbody>
            <?php foreach ($voteResults as $row): ?>
                <tr<?= ($winner && (int) $winner['id'] === (int) $row['id']) ? ' class="leading"' : '' ?>>
                    <td><?= e((string) $row['name']) ?></td><td><?= e((string) ($row['category_name'] ?? '-')) ?></td><td><?= (int) $row['votes'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted">Bei Gleichstand gewinnt der Lieferant mit der kleineren ID (also der zuerst angelegte Lieferant).</p>
    </section>

    <?php if ($winner): ?>
        <section class="card">
            <h2>Gewinner: <?= e((string) $winner['name']) ?></h2>
            <p><?= e((string) ($winner['category_name'] ?? '-')) ?> · Tel. <?= e((string) $winner['phone']) ?> · <a href="<?= e((string) $winner['menu_url']) ?>" target="_blank" rel="noopener">Speisekarte</a></p>
        </section>
    <?php endif; ?>

    <?php if ($state['phase'] === 'ordering'): ?>
        <section class="card">
            <h2>2) Bestellung eintragen</h2>
            <form method="post">
                <input type="hidden" name="action" value="<?= $editOrder ? 'order_update' : 'order_create' ?>">
                <label>Name/Kurzname<input type="text" name="nickname" maxlength="40" required value="<?= e((string) ($editOrder['nickname'] ?? '')) ?>"></label>
                <label>Essensnummer<input type="text" name="dish_no" maxlength="20" value="<?= e((string) ($editOrder['dish_no'] ?? '')) ?>"></label>
                <label>Gericht<input type="text" name="dish_name" maxlength="120" required value="<?= e((string) ($editOrder['dish_name'] ?? '')) ?>"></label>
                <label>Größe (optional)<input type="text" name="dish_size" maxlength="40" placeholder="z. B. 30cm oder Familienpizza" value="<?= e((string) ($editOrder['dish_size'] ?? '')) ?>"></label>
                <label>Preis in Euro<input type="number" step="0.01" min="0.01" max="999" name="price" required value="<?= e((string) ($editOrder['price'] ?? '')) ?>"></label>
                <label>Zahlungsart
                    <select name="payment_method">
                        <option value="bar" <?= (($editOrder['payment_method'] ?? 'bar') === 'bar') ? 'selected' : '' ?>>Bar</option>
                        <?php if ($state['paypal_enabled']): ?><option value="paypal" <?= (($editOrder['payment_method'] ?? '') === 'paypal') ? 'selected' : '' ?>>PayPal</option><?php endif; ?>
                    </select>
                </label>
                <label>Bemerkung<input type="text" name="note" maxlength="200" value="<?= e((string) ($editOrder['note'] ?? '')) ?>"></label>
                <?php if ($editOrder): ?><input type="hidden" name="edit_token" value="<?= e((string) $editOrder['edit_token']) ?>"><?php endif; ?>
                <label class="check"><input type="checkbox" name="confirmed" value="1" required> Ich bestätige, dass ich verbindlich bestellen möchte.</label>
            </form>

            <h3>Bestehende Bestellung bearbeiten/löschen</h3>
            <form method="get" class="inline">
                <input type="text" name="edit" placeholder="Bearbeitungs-Token" pattern="[a-f0-9]{32}" required>
                <button type="submit">Laden</button>
            </form>
            <?php if ($editOrder): ?>
                <form method="post"><input type="hidden" name="action" value="order_delete"><input type="hidden" name="edit_token" value="<?= e((string) $editOrder['edit_token']) ?>"><button type="submit" class="danger">Bestellung löschen</button></form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Bestellliste</h2>
        <table>
            <thead><tr><th>Name</th><th>#</th><th>Gericht</th><th>Größe</th><th>Preis</th><th>Zahlung</th><th>Hinweis</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <tr><td><?= e((string) $order['nickname']) ?></td><td><?= e((string) $order['dish_no']) ?></td><td><?= e((string) $order['dish_name']) ?></td><td><?php if (!empty($order['dish_size'])): ?><span class="dish-size"><?= e((string) $order['dish_size']) ?></span><?php else: ?>-<?php endif; ?></td><td><?= number_format((float) $order['price'], 2, ',', '.') ?> €</td><td><?= e(strtoupper((string) $order['payment_method'])) ?></td><td><?= e((string) ($order['note'] ?: '-')) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><strong>Gesamt:</strong> <?= number_format((float) $totals['all'], 2, ',', '.') ?> € · <strong>Bar:</strong> <?= number_format((float) $totals['bar'], 2, ',', '.') ?> € · <strong>PayPal:</strong> <?= number_format((float) $totals['paypal'], 2, ',', '.') ?> €</p>
        <?php if (!empty($settings['paypal_link']) && $state['paypal_enabled']): ?><p><a href="<?= e((string) $settings['paypal_link']) ?>" target="_blank" rel="noopener">PayPal-Link des Verantwortlichen</a></p><?php endif; ?>
    </section>
</main>
</body>
</html>
