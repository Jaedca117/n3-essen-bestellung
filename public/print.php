<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connect($config['db']);
$repo = new AppRepository($pdo, (string) ($config['db']['table_prefix'] ?? 'n3_essen_'));
$service = new AppService($repo);
$state = $service->runtimeState();
$settings = $state['settings'];

if (!isset($_SESSION['admin_id'])) {
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'toggle_paid_print')) {
    if (verify_csrf_token($_POST['csrf'] ?? null)) {
        $orderId = (int) ($_POST['id'] ?? 0);
        if ($orderId > 0 && $repo->findOrderById($orderId)) {
            $repo->setOrderPaidStatus($orderId, isset($_POST['is_paid']));
        }
    }
    header('Location: print.php');
    exit;
}

$winner = $service->winner($settings);
$orders = $repo->orders();
$totals = $repo->orderTotals();
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Druckansicht</title><link rel="stylesheet" href="style.css"><style>.paid-cell{text-align:right}.paid-toggle-form{display:flex;justify-content:flex-end}.paid-checkbox{width:1.2rem;height:1.2rem;accent-color:#111}@media print{a{color:#000;text-decoration:none}.noprint{display:none}.paid-checkbox{width:1.25rem;height:1.25rem;border:1px solid #000;appearance:none;background:#fff}.paid-checkbox:checked{background:linear-gradient(135deg,#000 0,#000 100%)}}</style></head>
<body><main class="container"><p class="noprint"><a href="admin.php">Zurück</a></p><h1>Druckansicht <?= e((new DateTimeImmutable('now'))->format('d.m.Y')) ?></h1>
<p><strong>Gewinner:</strong> <?= e((string) ($winner['category_name'] ?? 'Noch kein Gewinner')) ?></p>
<?php if ($winner): ?><p><?= e((string) $winner['name']) ?> - <strong>Speisekarte:</strong> <?= e((string) $winner['menu_url']) ?></p><?php endif; ?>
<?php if (!empty($winner['order_method'])): ?><p><strong>Bestellverfahren:</strong> <?= nl2br(e((string) $winner['order_method'])) ?></p><?php endif; ?>
<?php if (!empty($settings['daily_note'])): ?><p><strong>Tageshinweis:</strong> <?= e((string) $settings['daily_note']) ?></p><?php endif; ?>
<table><thead><tr><th>Name</th><th>Nr</th><th>Gericht</th><th>Größe</th><th>Preis</th><th>Zahlung</th><th>Notiz</th><th class="paid-cell">Bezahlt</th></tr></thead><tbody>
<?php foreach ($orders as $o): ?><tr><td><?= e((string) $o['nickname']) ?></td><td><?= e((string) $o['dish_no']) ?></td><td><?= e((string) $o['dish_name']) ?></td><td><?= e((string) ($o['dish_size'] ?: '-')) ?></td><td><?= number_format((float) $o['price'], 2, ',', '.') ?> €</td><td><?= e((string) strtoupper((string) $o['payment_method'])) ?></td><td><?= e((string) ($o['note'] ?: '-')) ?></td><td class="paid-cell"><form method="post" class="paid-toggle-form"><input type="hidden" name="action" value="toggle_paid_print"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input class="paid-checkbox" type="checkbox" name="is_paid" value="1" aria-label="Bezahlt von <?= e((string) $o['nickname']) ?>" <?= ((int) ($o['is_paid'] ?? 0) === 1) ? 'checked' : '' ?> onchange="this.form.submit()"></form></td></tr><?php endforeach; ?>
</tbody></table>
<p><strong>Summe Gesamt:</strong> <?= number_format((float) $totals['all'], 2, ',', '.') ?> €</p>
<p><strong>Summe Bar:</strong> <?= number_format((float) $totals['bar'], 2, ',', '.') ?> € · <strong>Summe PayPal:</strong> <?= number_format((float) $totals['paypal'], 2, ',', '.') ?> €</p>
</main></body></html>
