<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '';
if (preg_match('#/index\.php$#', $requestPath) === 1) {
    $targetPath = (string) preg_replace('#/index\.php$#', '/', $requestPath);
    $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    header('Location: ' . ($query !== '' ? $targetPath . '?' . $query : $targetPath), true, 301);
    exit;
}

$pdo = Database::connect($config['db']);
$repo = new AppRepository($pdo, (string) ($config['db']['table_prefix'] ?? 'n3_essen_'));
$service = new AppService($repo);
$state = $service->runtimeState();

$message = isset($_SESSION['flash_message']) ? (string) $_SESSION['flash_message'] : null;
$error = isset($_SESSION['flash_error']) ? (string) $_SESSION['flash_error'] : null;
$editOrder = null;
unset($_SESSION['flash_message'], $_SESSION['flash_error']);

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

$voteCount = $repo->voteCountForToken((string) $_COOKIE['vote_token']);
$hasVoted = $voteCount >= 2;
$settings = $state['settings'];
$weekdayKey = current_weekday_key();
$excludeLastWeekSupplier = (($settings['exclude_last_week_supplier_' . $weekdayKey] ?? '0') === '1');
$excludedSupplierId = $excludeLastWeekSupplier ? (int) ($settings['last_supplier_id_' . $weekdayKey] ?? 0) : 0;
$winner = $service->winner($settings);
$hasPlacedOrder = $repo->hasOrdersByOwnerToken((string) $_COOKIE['vote_token']);
$hasRatedWinner = $winner ? $repo->hasRatingForTokenAndSupplier((string) $_COOKIE['vote_token'], (int) $winner['id']) : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $orderId = 0;

    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges Formular-Token. Bitte Seite neu laden.';
    } elseif ($action === 'vote') {
        if ($state['phase'] !== 'voting') {
            $error = 'Die Abstimmungsphase ist beendet.';
        } elseif (!$service->canProceed('vote', client_ip())) {
            $error = 'Zu viele Abstimmungen in kurzer Zeit. Bitte kurz warten.';
        } else {
            $supplierId = (int) ($_POST['supplier_id'] ?? 0);
            $availableSuppliers = array_values(array_filter(
                $repo->suppliers(),
                static fn(array $supplier): bool => (int) ($supplier['id'] ?? 0) !== $excludedSupplierId
            ));
            $ids = array_map(static fn($r) => (int) $r['id'], $availableSuppliers);
            if (!in_array($supplierId, $ids, true)) {
                $error = 'Ungültiger Lieferant.';
            } elseif ($repo->hasVoteForTokenAndSupplier((string) $_COOKIE['vote_token'], $supplierId)) {
                $error = 'Du hast für diesen Lieferanten bereits abgestimmt.';
            } elseif ($hasVoted) {
                $error = 'Du hast heute bereits 2 Stimmen abgegeben.';
            } else {
                $repo->recordVote((string) $_COOKIE['vote_token'], $supplierId);
                $voteCount++;
                $hasVoted = $voteCount >= 2;
                $message = $hasVoted
                    ? 'Danke! Deine zweite Stimme wurde gespeichert.'
                    : 'Danke! Deine Stimme wurde gespeichert. Du kannst noch eine zweite Stimme abgeben.';
            }
        }
    }

    if ($error === null && in_array($action, ['order_create', 'order_update', 'order_delete'], true)) {
        if ($state['phase'] !== 'ordering') {
            $error = 'Bestellungen sind aktuell nicht möglich.';
        } elseif (!$service->canProceed($action === 'order_create' ? 'order_create' : 'order_update', client_ip())) {
            $error = 'Zu viele Aktionen in kurzer Zeit. Bitte kurz warten.';
        } else {
            $orderId = (int) ($_POST['order_id'] ?? 0);
            $errors = [];
            $payload = [];
            if ($action !== 'order_delete') {
                $payload = [
                    'nickname' => trim((string) ($_POST['nickname'] ?? '')),
                    'dish_no' => trim((string) ($_POST['dish_no'] ?? '')),
                    'dish_name' => trim((string) ($_POST['dish_name'] ?? '')),
                    'dish_size' => trim((string) ($_POST['dish_size'] ?? '')),
                    'price' => (float) ($_POST['price'] ?? 0),
                    'payment_method' => (string) ($_POST['payment_method'] ?? 'bar'),
                    'note' => trim((string) ($_POST['note'] ?? '')),
                ];
                $errors = validate_order_payload($payload, $state['paypal_enabled']);
                if (empty($_POST['confirmed'])) {
                    $errors[] = 'Bitte verbindliche Bestellung bestätigen.';
                }
            }

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

    if ($error === null && $action === 'supplier_rating') {
        if ($state['day_disabled']) {
            $error = 'Heute sind keine Aktionen möglich.';
        } elseif ($state['phase'] !== 'closed') {
            $error = 'Bewertungen sind erst nach der Bestellphase möglich.';
        } elseif (!$winner) {
            $error = 'Aktuell gibt es keinen Lieferanten zum Bewerten.';
        } elseif (!$hasPlacedOrder) {
            $error = 'Du kannst erst bewerten, nachdem du eine Bestellung abgegeben hast.';
        } elseif ($hasRatedWinner) {
            $error = 'Du hast diesen Lieferanten bereits bewertet.';
        } elseif (!$service->canProceed('supplier_rating', client_ip())) {
            $error = 'Zu viele Aktionen in kurzer Zeit. Bitte kurz warten.';
        } else {
            $supplierId = (int) ($_POST['supplier_id'] ?? 0);
            $rating = (int) ($_POST['rating'] ?? 0);
            if ((int) $winner['id'] !== $supplierId) {
                $error = 'Ungültiger Lieferant für die Bewertung.';
            } elseif ($rating < 1 || $rating > 5) {
                $error = 'Bitte gib eine Bewertung zwischen 1 und 5 Sternen ab.';
            } else {
                $repo->recordSupplierRating((string) $_COOKIE['vote_token'], $supplierId, $rating);
                $hasRatedWinner = true;
                $message = 'Danke! Deine Bewertung wurde gespeichert.';
            }
        }
    }

    if ($message !== null) {
        $_SESSION['flash_message'] = $message;
    }
    if ($error !== null) {
        $_SESSION['flash_error'] = $error;
    }

    $redirectParams = [];
    if ($action === 'order_update' && $error !== null && $orderId > 0) {
        $redirectParams['edit_id'] = $orderId;
    }

    $redirectUrl = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    if ($redirectUrl === false || $redirectUrl === '') {
        $redirectUrl = '/';
    }
    if ($redirectParams !== []) {
        $redirectUrl .= '?' . http_build_query($redirectParams);
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$dailyResetTime = trim((string) ($settings['daily_reset_time'] ?? '10:30:00'));
if (preg_match('/^\d{2}:\d{2}/', $dailyResetTime) === 1) {
    $dailyResetTime = substr($dailyResetTime, 0, 5);
} else {
    $dailyResetTime = '10:30';
}
$suppliers = $repo->suppliers();
$suppliers = array_values(array_filter(
    $suppliers,
    static fn(array $supplier): bool => (int) ($supplier['id'] ?? 0) !== $excludedSupplierId
));
$voteResults = $repo->voteResults();
$voteResults = array_values(array_filter(
    $voteResults,
    static fn(array $supplier): bool => (int) ($supplier['id'] ?? 0) !== $excludedSupplierId
));
$supplierRatingStats = $repo->supplierRatingStatsBySupplierId();
$orders = $repo->ordersByOwnerToken((string) $_COOKIE['vote_token']);
$totals = $repo->orderTotalsByOwnerToken((string) $_COOKIE['vote_token']);
$activePaypalLink = $service->activePaypalLinkForWeekday($settings, current_weekday_key());
$displayTotals = $totals;
if (!$state['paypal_enabled']) {
    $displayTotals['paypal'] = 0.0;
    $displayTotals['all'] = (float) $displayTotals['bar'];
}

$groupedSuppliers = [];
foreach ($suppliers as $supplier) {
    $groupedSuppliers[$supplier['category_name'] ?? 'Sonstiges'][] = $supplier;
}

$formatSupplierAverageRating = static function (int $supplierId) use ($supplierRatingStats): string {
    $avg = (float) ($supplierRatingStats[$supplierId]['avg'] ?? 0.0);
    if ($avg <= 0) {
        return '-';
    }
    return number_format($avg, 1, ',', '.');
};
$dayDisabledNotice = trim((string) ($settings['day_disabled_notice'] ?? ''));
if ($dayDisabledNotice === '') {
    $dayDisabledNotice = 'Bestellungen sind heute deaktiviert.';
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
        <div class="hero-head">
            <?php if (!empty($config['app_logo'])): ?>
                <img class="hero-logo" src="<?= e((string) $config['app_logo']) ?>" alt="Vereinslogo">
            <?php endif; ?>
            <h1><?= e((string) ($config['app_name'] ?? 'Vereins-Essen')) ?></h1>
        </div>
        <?php if (!empty($settings['header_subtitle'])): ?><p><?= e((string) $settings['header_subtitle']) ?></p><?php endif; ?>
        <?php if (!$state['day_disabled']): ?>
            <p>Abstimmung bis <?= e($state['voting_end']->format('H:i')) ?> Uhr<br>Bestellung bis <?= e($state['order_end']->format('H:i')) ?> Uhr</p>
        <?php endif; ?>
        <?php if (!empty($settings['daily_note'])): ?><p class="notice info"><strong>Tageshinweis:</strong> <?= e((string) $settings['daily_note']) ?></p><?php endif; ?>
        <?php if ($state['day_disabled']): ?><p class="notice error"><?= e($dayDisabledNotice) ?></p><?php endif; ?>
    </header>

    <?php if ($message): ?><p class="notice success"><?= e($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>

    <?php if (!$state['day_disabled'] && $state['phase'] === 'voting' && !$hasVoted): ?>
        <section class="card">
            <h2>Abstimmen</h2>
            <p class="muted">Du hast <?= (int) $voteCount ?> von 2 Stimmen abgegeben.</p>
            <?php foreach ($groupedSuppliers as $category => $items): ?>
                <details class="supplier-category" open>
                    <summary>
                        <span><?= e((string) $category) ?></span>
                        <span class="supplier-category-count"><?= count($items) ?> Lieferant<?= count($items) === 1 ? '' : 'en' ?></span>
                    </summary>
                    <div class="supplier-grid">
                        <?php foreach ($items as $supplier): ?>
                            <form method="post" class="supplier-card">
                                <input type="hidden" name="action" value="vote">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="supplier_id" value="<?= (int) $supplier['id'] ?>">
                                <strong><?= e((string) $supplier['name']) ?></strong>
                                <a href="<?= e((string) $supplier['menu_url']) ?>" target="_blank" rel="noopener">Speisekarte</a>
                                <span class="muted">Ø Bewertung: <?= e($formatSupplierAverageRating((int) $supplier['id'])) ?></span>
                                <button type="submit">Dafür stimmen</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if (!$state['day_disabled'] && $state['phase'] === 'voting' && $hasVoted): ?>
        <section class="card">
            <h2>Abstimmen</h2>
            <p class="notice success">Vielen Dank für deine Stimmen! Du hast heute bereits 2 Stimmen abgegeben – unten siehst du das aktuelle Zwischenergebnis.</p>
        </section>
    <?php endif; ?>

    <?php if (!$state['day_disabled'] && $state['phase'] === 'voting'): ?>
        <section class="card">
            <h2>Zwischenstand</h2>
            <table>
                <thead><tr><th>Lieferant</th><th>Kategorie</th><th>Speisekarte</th><th>Ø Bewertung</th><th>Stimmen</th></tr></thead>
                <tbody>
                <?php foreach ($voteResults as $row): ?>
                    <tr<?= ($winner && (int) $winner['id'] === (int) $row['id']) ? ' class="leading"' : '' ?>>
                        <td><?= e((string) $row['name']) ?></td>
                        <td><?= e((string) ($row['category_name'] ?? '-')) ?></td>
                        <td><a href="<?= e((string) $row['menu_url']) ?>" target="_blank" rel="noopener">Link</a></td>
                        <td><?= e($formatSupplierAverageRating((int) $row['id'])) ?></td>
                        <td><?= (int) $row['votes'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted">Bei Gleichstand gewinnt der Lieferant mit der kleineren ID (also der zuerst angelegte Lieferant).</p>
        </section>
    <?php endif; ?>

    <?php if (!$state['day_disabled'] && $winner && $state['phase'] !== 'voting'): ?>
        <section class="card">
            <h2>Gewinner: <?= e((string) ($winner['category_name'] ?? '-')) ?></h2>
            <p><?= e((string) $winner['name']) ?> - <a href="<?= e((string) $winner['menu_url']) ?>" target="_blank" rel="noopener">Speisekarte</a></p>
        </section>
    <?php endif; ?>

    <?php if (!$state['day_disabled'] && $state['phase'] === 'ordering'): ?>
        <section class="card">
            <h2>Bestellen</h2>
            <form method="post">
                <input type="hidden" name="action" value="<?= $editOrder ? 'order_update' : 'order_create' ?>">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
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
                <?php if (!$state['paypal_enabled']): ?><p class="muted">Heute ist nur BAR-Zahlung möglich.</p><?php endif; ?>
                <label>Bemerkung<input type="text" name="note" maxlength="200" value="<?= e((string) ($editOrder['note'] ?? '')) ?>"></label>
                <?php if ($editOrder): ?><input type="hidden" name="order_id" value="<?= (int) $editOrder['id'] ?>"><?php endif; ?>
                <label class="check"><input type="checkbox" name="confirmed" value="1" required> Ich bestätige, dass ich verbindlich bestellen möchte.</label>
                <button type="submit"><?= $editOrder ? 'Bestellung aktualisieren' : 'Bestellung speichern' ?></button>
            </form>

            <?php if ($editOrder): ?>
                <form method="post"><input type="hidden" name="action" value="order_delete"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="order_id" value="<?= (int) $editOrder['id'] ?>"><button type="submit" class="danger">Bestellung löschen</button></form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!$state['day_disabled'] && $state['phase'] !== 'voting'): ?>
        <section class="card">
            <h2>Meine Bestellungen</h2>
            <?php if ($orders): ?>
                <div class="my-orders-list">
                    <?php foreach ($orders as $order): ?>
                        <details class="my-order-item">
                            <summary>
                                <span class="my-order-title"><?= e((string) $order['dish_name']) ?></span>
                                <span class="my-order-price"><?= number_format((float) $order['price'], 2, ',', '.') ?> €</span>
                            </summary>
                            <div class="my-order-content">
                                <dl>
                                    <div><dt>Name</dt><dd><?= e((string) $order['nickname']) ?></dd></div>
                                    <div><dt>Essensnummer</dt><dd><?= e((string) ($order['dish_no'] !== '' ? $order['dish_no'] : '-')) ?></dd></div>
                                    <div><dt>Größe</dt><dd><?php if (!empty($order['dish_size'])): ?><span class="dish-size"><?= e((string) $order['dish_size']) ?></span><?php else: ?>-<?php endif; ?></dd></div>
                                    <div><dt>Zahlung</dt><dd><?= e(strtoupper((string) $order['payment_method'])) ?></dd></div>
                                    <div><dt>Hinweis</dt><dd><?= e((string) ($order['note'] ?: '-')) ?></dd></div>
                                    <div><dt>Bezahlt</dt><dd><?= ((int) ($order['is_paid'] ?? 0) === 1) ? 'Ja' : 'Nein' ?></dd></div>
                                </dl>
                                <?php if ($state['phase'] === 'ordering'): ?>
                                    <p class="my-order-actions"><a href="?edit_id=<?= (int) $order['id'] ?>">Diese Bestellung bearbeiten/löschen</a></p>
                                <?php endif; ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!$orders): ?><p class="muted">Du hast noch keine Bestellung erfasst.</p><?php endif; ?>
            <p><strong>Gesamt:</strong> <?= number_format((float) $displayTotals['all'], 2, ',', '.') ?> € · <strong>Bar:</strong> <?= number_format((float) $displayTotals['bar'], 2, ',', '.') ?> €<?php if ($state['paypal_enabled']): ?> · <strong>PayPal:</strong> <?= number_format((float) $displayTotals['paypal'], 2, ',', '.') ?> €<?php endif; ?></p>
            <?php if ($activePaypalLink && $state['paypal_enabled'] && (float) $totals['paypal'] > 0): ?><p><a href="<?= e($activePaypalLink['url']) ?>" target="_blank" rel="noopener">PayPal-Link: <?= e($activePaypalLink['name']) ?></a></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!$state['day_disabled'] && $state['phase'] === 'closed' && $winner): ?>
        <section class="card">
            <h2>Lieferant bewerten</h2>
            <?php if (!$hasPlacedOrder): ?>
                <p class="muted">Bewertung nur möglich, wenn du heute mindestens eine Bestellung abgegeben hast.</p>
            <?php elseif ($hasRatedWinner): ?>
                <p class="notice success">Danke! Du hast <strong><?= e((string) $winner['name']) ?></strong> bereits bewertet.</p>
            <?php else: ?>
                <p>Wie zufrieden warst du mit <strong><?= e((string) $winner['name']) ?></strong>?</p>
                <form method="post" class="rating-form">
                    <input type="hidden" name="action" value="supplier_rating">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="supplier_id" value="<?= (int) $winner['id'] ?>">
                    <div class="rating-stars">
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                            <button type="submit" name="rating" value="<?= $star ?>" class="rating-star-button" aria-label="<?= $star ?> Stern<?= $star === 1 ? '' : 'e' ?>">
                                <?= str_repeat('★', $star) ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <section id="datenschutz" class="card privacy-card">
        <h2>Datenschutzhinweis</h2>
        <p>Die hier eingetragenen Daten können nur von den Stammtisch-/Eventverantwortlichen eingesehen werden. Diese Daten löschen sich täglich um <?= e($dailyResetTime) ?> Uhr.</p>
    </section>

    <div class="admin-link-bottom">
        <a href="/admin" class="admin-link-button">Adminbereich</a>
    </div>
</main>
</body>
</html>
