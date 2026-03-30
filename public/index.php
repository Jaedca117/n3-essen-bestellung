<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connect($config['db']);
$repo = new AppRepository($pdo, (string) ($config['db']['table_prefix'] ?? 'n3_essen_'));
$service = new AppService($repo);
$state = $service->runtimeState();

/**
 * @return array{id:string,name:string,url:string}|null
 */
function active_paypal_link(array $settings): ?array
{
    $decoded = json_decode((string) ($settings['paypal_links'] ?? ''), true);
    $activeId = trim((string) ($settings['paypal_link_active_id'] ?? ''));
    if (is_array($decoded)) {
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = trim((string) ($entry['id'] ?? ''));
            $name = trim((string) ($entry['name'] ?? ''));
            $url = trim((string) ($entry['url'] ?? ''));
            if ($id === '' || $name === '' || $url === '') {
                continue;
            }
            if ($activeId !== '' && $id === $activeId) {
                return ['id' => $id, 'name' => $name, 'url' => $url];
            }
            if ($activeId === '') {
                return ['id' => $id, 'name' => $name, 'url' => $url];
            }
        }
    }

    $legacyLink = trim((string) ($settings['paypal_link'] ?? ''));
    if ($legacyLink !== '') {
        return ['id' => 'legacy', 'name' => 'PayPal', 'url' => $legacyLink];
    }
    return null;
}

$message = null;
$error = null;
$editOrder = null;

if (empty($_COOKIE['vote_token'])) {
    $voteToken = bin2hex(random_bytes(16));
    setcookie('vote_token', $voteToken, ['expires' => time() + 365 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['vote_token'] = $voteToken;
}

if (isset($_GET['edit_id'])) {
    $editId = (int) $_GET['edit_id'];
    if ($editId <= 0) {
        $error = 'Ungültige Bestellung.';
    } else {
        $editOrder = $repo->findOrderByIdAndOwnerToken($editId, (string) $_COOKIE['vote_token']);
        if (!$editOrder) {
            $error = 'Bestellung nicht gefunden oder kein Zugriff.';
        }
    }
}

$hasVoted = $repo->hasVoteForToken((string) $_COOKIE['vote_token']);

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
            } elseif ($hasVoted) {
                $error = 'Du hast heute bereits abgestimmt.';
            } else {
                $repo->recordVote((string) $_COOKIE['vote_token'], $supplierId);
                $hasVoted = true;
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
            $orderId = (int) ($_POST['order_id'] ?? 0);
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
                $payload['created_by_token'] = (string) $_COOKIE['vote_token'];
                $repo->createOrder($payload);
                $message = 'Bestellung gespeichert.';
            } elseif ($action === 'order_update') {
                if ($orderId <= 0 || !$repo->findOrderByIdAndOwnerToken($orderId, (string) $_COOKIE['vote_token'])) {
                    $error = 'Bestellung nicht gefunden oder kein Zugriff.';
                } else {
                    $repo->updateOrderByIdAndOwnerToken($orderId, (string) $_COOKIE['vote_token'], $payload);
                    $message = 'Bestellung wurde aktualisiert.';
                }
            } elseif ($action === 'order_delete') {
                if ($orderId <= 0 || !$repo->findOrderByIdAndOwnerToken($orderId, (string) $_COOKIE['vote_token'])) {
                    $error = 'Bestellung nicht gefunden oder kein Zugriff.';
                } else {
                    $repo->deleteOrderByIdAndOwnerToken($orderId, (string) $_COOKIE['vote_token']);
                    $message = 'Bestellung wurde gelöscht.';
                }
            }
        }
    }

    $state = $service->runtimeState();
    $hasVoted = $repo->hasVoteForToken((string) $_COOKIE['vote_token']);
}

$settings = $state['settings'];
$dailyResetTime = trim((string) ($settings['daily_reset_time'] ?? '10:30:00'));
if (preg_match('/^\d{2}:\d{2}/', $dailyResetTime) === 1) {
    $dailyResetTime = substr($dailyResetTime, 0, 5);
} else {
    $dailyResetTime = '10:30';
}
$suppliers = $repo->suppliers();
$voteResults = $repo->voteResults();
$winner = $service->winner($settings);
$orders = $repo->ordersByOwnerToken((string) $_COOKIE['vote_token']);
$totals = $repo->orderTotalsByOwnerToken((string) $_COOKIE['vote_token']);
$activePaypalLink = active_paypal_link($settings);

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
        <?php if (!empty($settings['header_subtitle'])): ?><p><?= e((string) $settings['header_subtitle']) ?></p><?php endif; ?>
        <p>Phase: <strong><?= $state['phase'] === 'voting' ? 'Abstimmung offen' : ($state['phase'] === 'ordering' ? 'Bestellphase offen' : 'Geschlossen') ?></strong></p>
        <p>Abstimmung bis <?= e($state['voting_end']->format('H:i')) ?> Uhr · Bestellung bis <?= e($state['order_end']->format('H:i')) ?> Uhr</p>
        <?php if (!empty($settings['daily_note'])): ?><p class="notice info"><strong>Tageshinweis:</strong> <?= e((string) $settings['daily_note']) ?></p><?php endif; ?>
    </header>

    <?php if ($message): ?><p class="notice success"><?= e($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>

    <?php if ($state['phase'] === 'voting' && !$hasVoted): ?>
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

    <?php if ($state['phase'] === 'voting' && $hasVoted): ?>
        <section class="card">
            <h2>1) Abstimmen</h2>
            <p class="notice success">Vielen Dank für deine Stimme! Du hast heute bereits abgestimmt – unten siehst du das aktuelle Zwischenergebnis.</p>
        </section>
    <?php endif; ?>

    <?php if ($state['phase'] === 'voting'): ?>
        <section class="card">
            <h2>Zwischenstand</h2>
            <table>
                <thead><tr><th>Lieferant</th><th>Kategorie</th><th>Speisekarte</th><th>Stimmen</th></tr></thead>
                <tbody>
                <?php foreach ($voteResults as $row): ?>
                    <tr<?= ($winner && (int) $winner['id'] === (int) $row['id']) ? ' class="leading"' : '' ?>>
                        <td><?= e((string) $row['name']) ?></td>
                        <td><?= e((string) ($row['category_name'] ?? '-')) ?></td>
                        <td><a href="<?= e((string) $row['menu_url']) ?>" target="_blank" rel="noopener">Link</a></td>
                        <td><?= (int) $row['votes'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted">Bei Gleichstand gewinnt der Lieferant mit der kleineren ID (also der zuerst angelegte Lieferant).</p>
        </section>
    <?php endif; ?>

    <?php if ($winner && $state['phase'] !== 'voting'): ?>
        <section class="card">
            <h2>Gewinner: <?= e((string) ($winner['category_name'] ?? '-')) ?></h2>
            <p><?= e((string) $winner['name']) ?> - <a href="<?= e((string) $winner['menu_url']) ?>" target="_blank" rel="noopener">Speisekarte</a></p>
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
                <?php if ($editOrder): ?><input type="hidden" name="order_id" value="<?= (int) $editOrder['id'] ?>"><?php endif; ?>
                <label class="check"><input type="checkbox" name="confirmed" value="1" required> Ich bestätige, dass ich verbindlich bestellen möchte.</label>
                <button type="submit"><?= $editOrder ? 'Bestellung aktualisieren' : 'Bestellung speichern' ?></button>
            </form>

            <?php if ($editOrder): ?>
                <form method="post"><input type="hidden" name="action" value="order_delete"><input type="hidden" name="order_id" value="<?= (int) $editOrder['id'] ?>"><button type="submit" class="danger">Bestellung löschen</button></form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Meine Bestellungen</h2>
        <table>
            <thead><tr><th>Name</th><th>#</th><th>Gericht</th><th>Größe</th><th>Preis</th><th>Zahlung</th><th>Hinweis</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <tr><td><?= e((string) $order['nickname']) ?></td><td><?= e((string) $order['dish_no']) ?></td><td><?= e((string) $order['dish_name']) ?></td><td><?php if (!empty($order['dish_size'])): ?><span class="dish-size"><?= e((string) $order['dish_size']) ?></span><?php else: ?>-<?php endif; ?></td><td><?= number_format((float) $order['price'], 2, ',', '.') ?> €</td><td><?= e(strtoupper((string) $order['payment_method'])) ?></td><td><?= e((string) ($order['note'] ?: '-')) ?></td></tr>
                <?php if ($state['phase'] === 'ordering'): ?>
                    <tr><td colspan="7"><a href="?edit_id=<?= (int) $order['id'] ?>">Diese Bestellung bearbeiten/löschen</a></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$orders): ?><p class="muted">Du hast noch keine Bestellung erfasst.</p><?php endif; ?>
        <p><strong>Gesamt:</strong> <?= number_format((float) $totals['all'], 2, ',', '.') ?> € · <strong>Bar:</strong> <?= number_format((float) $totals['bar'], 2, ',', '.') ?> € · <strong>PayPal:</strong> <?= number_format((float) $totals['paypal'], 2, ',', '.') ?> €</p>
        <?php if ($activePaypalLink && $state['paypal_enabled'] && (float) $totals['paypal'] > 0): ?><p><a href="<?= e($activePaypalLink['url']) ?>" target="_blank" rel="noopener">PayPal-Link: <?= e($activePaypalLink['name']) ?></a></p><?php endif; ?>
    </section>
    <section id="datenschutz" class="card privacy-card">
        <h2>Datenschutzhinweis</h2>
        <p>Die hier eingetragenen Daten können nur von den Stammtisch-/Eventverantwortlichen eingesehen werden. Diese Daten löschen sich täglich um <?= e($dailyResetTime) ?> Uhr.</p>
    </section>

    <div class="admin-link-bottom">
        <a href="admin.php" class="admin-link-button">Adminbereich</a>
        <a href="#datenschutz" class="admin-link-button">Datenschutzhinweis</a>
    </div>
</main>
</body>
</html>
