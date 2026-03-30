<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connect($config['db']);
$repo = new AppRepository($pdo, (string) ($config['db']['table_prefix'] ?? 'n3_essen_'));
$service = new AppService($repo);
$state = $service->runtimeState();
$message = null;
$error = null;

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_id']);
    session_regenerate_id(true);
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'login')) {
    if (!$service->canProceed('admin_login', client_ip())) {
        $error = 'Zu viele Login-Versuche. Bitte warten.';
    } else {
        $user = $repo->findAdminByUsername(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($user && password_verify($password, (string) $user['password_hash'])) {
            $_SESSION['admin_id'] = (int) $user['id'];
            session_regenerate_id(true);
            header('Location: admin.php');
            exit;
        }
        $error = 'Login fehlgeschlagen.';
    }
}

$isAdmin = isset($_SESSION['admin_id']);
$adminSections = [
    'current' => 'Aktuelle Bestellung',
    'suppliers' => 'Lieferanten',
    'settings' => 'Seiten-Einstellungen',
];
$adminSection = (string) ($_GET['section'] ?? 'current');
if (!array_key_exists($adminSection, $adminSections)) {
    $adminSection = 'current';
}

/**
 * @return list<string>
 */
function validate_order_payload(array $payload, bool $paypalEnabled): array
{
    $errors = [];
    if (mb_strlen((string) $payload['nickname']) < 2 || mb_strlen((string) $payload['nickname']) > 40) $errors[] = 'Name muss 2-40 Zeichen haben.';
    if (mb_strlen((string) $payload['dish_no']) > 20) $errors[] = 'Essensnummer ist zu lang.';
    if (mb_strlen((string) $payload['dish_name']) < 2 || mb_strlen((string) $payload['dish_name']) > 120) $errors[] = 'Gericht muss 2-120 Zeichen haben.';
    if (mb_strlen((string) $payload['dish_size']) > 40) $errors[] = 'Größe darf höchstens 40 Zeichen haben.';
    if ((float) $payload['price'] <= 0 || (float) $payload['price'] > 999) $errors[] = 'Preis muss zwischen 0,01 und 999 liegen.';
    if (!in_array((string) $payload['payment_method'], ['bar', 'paypal'], true)) $errors[] = 'Ungültige Zahlungsart.';
    if (!$paypalEnabled && $payload['payment_method'] === 'paypal') $errors[] = 'PayPal ist heute nicht verfügbar.';
    if (mb_strlen((string) $payload['note']) > 200) $errors[] = 'Bemerkung darf höchstens 200 Zeichen haben.';
    return $errors;
}

/**
 * @return array<string, string>
 */
function weekday_labels(): array
{
    return [
        'monday' => 'Montag',
        'tuesday' => 'Dienstag',
        'wednesday' => 'Mittwoch',
        'thursday' => 'Donnerstag',
        'friday' => 'Freitag',
        'saturday' => 'Samstag',
        'sunday' => 'Sonntag',
    ];
}

function normalized_hhmm(string $value, string $fallback): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return $fallback;
    }
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $trimmed) !== 1) {
        return $fallback;
    }
    return substr($trimmed, 0, 5);
}

/**
 * @return list<array{id:string,name:string,url:string}>
 */
function paypal_link_options(array $settings): array
{
    $raw = (string) ($settings['paypal_links'] ?? '');
    if ($raw === '') {
        $legacyLink = trim((string) ($settings['paypal_link'] ?? ''));
        if ($legacyLink === '') {
            return [];
        }
        return [[
            'id' => 'legacy',
            'name' => 'Standard',
            'url' => $legacyLink,
        ]];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $result = [];
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
        $result[] = ['id' => $id, 'name' => $name, 'url' => $url];
    }

    return $result;
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_current_settings')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $dailyNote = trim((string) ($_POST['daily_note'] ?? ''));
        $repo->saveSetting('daily_note', $dailyNote);
        $repo->saveSetting('order_closed', isset($_POST['order_closed']) ? '1' : '0');
        $manualWinnerSupplierId = trim((string) ($_POST['manual_winner_supplier_id'] ?? ''));
        $supplierIds = array_map(static fn(array $supplier): string => (string) $supplier['id'], $repo->allSuppliers());
        if ($manualWinnerSupplierId !== '' && !in_array($manualWinnerSupplierId, $supplierIds, true)) {
            $error = 'Manueller Gewinner ist ungültig.';
        } else {
            $repo->saveSetting('manual_winner_supplier_id', $manualWinnerSupplierId);
        }

        if ($error === null) {
            $activePaypalId = trim((string) ($_POST['paypal_link_active_id'] ?? ''));
            $activePaypalUrl = '';
            foreach (paypal_link_options($repo->getSettings()) as $entry) {
                if ($entry['id'] === $activePaypalId) {
                    $activePaypalUrl = $entry['url'];
                    break;
                }
            }
            if ($activePaypalId !== '' && $activePaypalUrl === '') {
                $error = 'Aktiver PayPal-Link ist ungültig.';
            } else {
                $repo->saveSetting('paypal_link_active_id', $activePaypalId);
                $repo->saveSetting('paypal_link', $activePaypalUrl);
                $message = 'Bereich "Aktuelle Bestellung" gespeichert.';
                $state = $service->runtimeState();
            }
        }
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_page_settings')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        foreach (weekday_labels() as $weekdayKey => $_) {
            $repo->saveSetting(
                'voting_end_time_' . $weekdayKey,
                normalized_hhmm((string) ($_POST['voting_end_time_' . $weekdayKey] ?? ''), '16:00')
            );
            $repo->saveSetting(
                'order_end_time_' . $weekdayKey,
                normalized_hhmm((string) ($_POST['order_end_time_' . $weekdayKey] ?? ''), '18:00')
            );
        }
        $repo->saveSetting('daily_reset_time', normalized_hhmm((string) ($_POST['daily_reset_time'] ?? ''), '10:30'));
        $repo->saveSetting('header_subtitle', trim((string) ($_POST['header_subtitle'] ?? '')));
        $repo->saveSetting('manual_winner_supplier_id', trim((string) ($_POST['manual_winner_supplier_id'] ?? '')));
        $repo->saveSetting('reset_daily_note', isset($_POST['reset_daily_note']) ? '1' : '0');

        $paypalLinkIds = $_POST['paypal_link_ids'] ?? [];
        $paypalLinkNames = $_POST['paypal_link_names'] ?? [];
        $paypalLinkUrls = $_POST['paypal_link_urls'] ?? [];
        $paypalLinks = [];
        if (is_array($paypalLinkIds) && is_array($paypalLinkNames) && is_array($paypalLinkUrls)) {
            foreach ($paypalLinkNames as $index => $nameValue) {
                $name = trim((string) $nameValue);
                $url = trim((string) ($paypalLinkUrls[$index] ?? ''));
                if ($name === '' || $url === '') {
                    continue;
                }
                $id = trim((string) ($paypalLinkIds[$index] ?? ''));
                if ($id === '') {
                    $id = 'pp_' . substr(sha1($name . '|' . $url), 0, 12);
                }
                $paypalLinks[] = ['id' => $id, 'name' => $name, 'url' => $url];
            }
        }

        $activePaypalId = trim((string) ($_POST['paypal_link_active_id'] ?? ''));
        $activePaypalUrl = '';
        foreach ($paypalLinks as $entry) {
            if ($entry['id'] === $activePaypalId) {
                $activePaypalUrl = $entry['url'];
                break;
            }
        }
        if ($activePaypalUrl === '' && $paypalLinks !== []) {
            $activePaypalId = $paypalLinks[0]['id'];
            $activePaypalUrl = $paypalLinks[0]['url'];
        }
        $repo->saveSetting('paypal_links', json_encode($paypalLinks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $repo->saveSetting('paypal_link_active_id', $activePaypalId);
        $repo->saveSetting('paypal_link', $activePaypalUrl);

        $message = 'Bereich "Seiten-Einstellungen" gespeichert.';
        $state = $service->runtimeState();
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_category')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $error = 'Kategorie ungültig.';
        } else {
            $repo->upsertCategory((int) ($_POST['id'] ?? 0) ?: null, $name);
            $message = 'Kategorie gespeichert.';
        }
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'delete_category')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $categoryId = (int) ($_POST['id'] ?? 0);
        if ($categoryId <= 0) {
            $error = 'Kategorie-ID ungültig.';
        } elseif (!$repo->deleteCategory($categoryId)) {
            $error = 'Kategorie kann nicht gelöscht werden, solange noch Lieferanten zugeordnet sind.';
        } else {
            $message = 'Kategorie gelöscht.';
        }
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_supplier')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $payload = [
            'id' => (int) ($_POST['id'] ?? 0),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'category_id' => (int) ($_POST['category_id'] ?? 0),
            'menu_url' => trim((string) ($_POST['menu_url'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($payload['name'] === '' || $payload['category_id'] <= 0) {
            $error = 'Lieferant unvollständig.';
        } else {
            $repo->upsertSupplier($payload);
            $message = 'Lieferant gespeichert.';
        }
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'delete_supplier')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $supplierId = (int) ($_POST['id'] ?? 0);
        if ($supplierId <= 0) {
            $error = 'Lieferanten-ID ungültig.';
        } else {
            $repo->deleteSupplier($supplierId);
            $message = 'Lieferant gelöscht.';
        }
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_order_admin')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $orderId = (int) ($_POST['id'] ?? 0);
        $payload = [
            'nickname' => trim((string) ($_POST['nickname'] ?? '')),
            'dish_no' => trim((string) ($_POST['dish_no'] ?? '')),
            'dish_name' => trim((string) ($_POST['dish_name'] ?? '')),
            'dish_size' => trim((string) ($_POST['dish_size'] ?? '')),
            'price' => (float) ($_POST['price'] ?? 0),
            'payment_method' => (string) ($_POST['payment_method'] ?? 'bar'),
            'note' => trim((string) ($_POST['note'] ?? '')),
        ];
        if ($orderId <= 0 || !$repo->findOrderById($orderId)) {
            $error = 'Bestellung nicht gefunden.';
        } else {
            $orderErrors = validate_order_payload($payload, $state['paypal_enabled']);
            if ($orderErrors) {
                $error = implode(' ', $orderErrors);
            } else {
                $repo->updateOrderById($orderId, $payload);
                $message = 'Bestellung aktualisiert.';
            }
        }
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'delete_order_admin')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $orderId = (int) ($_POST['id'] ?? 0);
        if ($orderId <= 0 || !$repo->findOrderById($orderId)) {
            $error = 'Bestellung nicht gefunden.';
        } else {
            $repo->deleteOrderById($orderId);
            $message = 'Bestellung gelöscht.';
        }
    }
}

$settings = $repo->getSettings();
$categories = $repo->categories();
$suppliers = $repo->allSuppliers();
$orders = $repo->orders();
$paypalLinks = paypal_link_options($settings);
$activePaypalId = (string) ($settings['paypal_link_active_id'] ?? '');
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin</title><link rel="stylesheet" href="style.css"></head>
<body><main class="container"><h1>Admin-Bereich</h1>
<p><a href="index.php">Zur Startseite</a><?= $isAdmin ? ' · <a href="print.php" target="_blank" rel="noopener">Druckansicht</a> · <a href="?logout=1">Logout</a>' : '' ?></p>
<?php if ($message): ?><p class="notice success"><?= e($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>

<?php if (!$isAdmin): ?>
<section class="card"><h2>Login</h2>
<form method="post"><input type="hidden" name="action" value="login"><label>Benutzername<input name="username" required></label><label>Passwort<input type="password" name="password" required></label><button>Anmelden</button></form></section>
<?php else: ?>
<section class="card">
    <h2>Bereiche</h2>
    <nav class="admin-sections">
        <?php foreach ($adminSections as $key => $label): ?>
            <a class="admin-section-link <?= $adminSection === $key ? 'active' : '' ?>" href="admin.php?section=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
</section>

<?php if ($adminSection === 'current'): ?>
<section class="card"><h2>Aktuelle Bestellung</h2>
<form method="post"><input type="hidden" name="action" value="save_current_settings"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<label>Heutiger PayPal-Link
    <select name="paypal_link_active_id">
        <option value="">-- Kein Link --</option>
        <?php foreach ($paypalLinks as $entry): ?>
            <option value="<?= e($entry['id']) ?>" <?= ($activePaypalId === $entry['id']) ? 'selected' : '' ?>><?= e($entry['name']) ?></option>
        <?php endforeach; ?>
    </select>
</label>
<p class="muted">Druckansicht: <a href="print.php" target="_blank" rel="noopener">print.php öffnen</a></p>
<label>Tageshinweis<input name="daily_note" maxlength="200" value="<?= e((string) ($settings['daily_note'] ?? '')) ?>"></label>
<label>Manueller Gewinner
    <select name="manual_winner_supplier_id">
        <option value="">-- Automatisch per Abstimmung --</option>
        <?php foreach ($suppliers as $supplier): ?>
            <option value="<?= (int) $supplier['id'] ?>" <?= ((string) ($settings['manual_winner_supplier_id'] ?? '') === (string) $supplier['id']) ? 'selected' : '' ?>>
                #<?= (int) $supplier['id'] ?> - <?= e((string) $supplier['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</label>
<label class="check"><input type="checkbox" name="order_closed" <?= (($settings['order_closed'] ?? '0') === '1') ? 'checked' : '' ?>> Bestellung abgeschlossen</label>
<button>Speichern</button></form>
</section>

<section class="card"><h2>Aktuelle Bestellungen verwalten</h2>
<ul><?php foreach ($orders as $o): ?><li>
    <form method="post">
        <input type="hidden" name="action" value="save_order_admin">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
        #<?= (int) $o['id'] ?>
        <input name="nickname" maxlength="40" required value="<?= e((string) $o['nickname']) ?>" placeholder="Name">
        <input name="dish_no" maxlength="20" value="<?= e((string) $o['dish_no']) ?>" placeholder="Nr.">
        <input name="dish_name" maxlength="120" required value="<?= e((string) $o['dish_name']) ?>" placeholder="Gericht">
        <input name="dish_size" maxlength="40" value="<?= e((string) ($o['dish_size'] ?? '')) ?>" placeholder="Größe (z. B. 30cm)">
        <input type="number" step="0.01" min="0.01" max="999" name="price" required value="<?= e((string) $o['price']) ?>" placeholder="Preis">
        <select name="payment_method">
            <option value="bar" <?= ($o['payment_method'] === 'bar') ? 'selected' : '' ?>>Bar</option>
            <?php if ($state['paypal_enabled']): ?><option value="paypal" <?= ($o['payment_method'] === 'paypal') ? 'selected' : '' ?>>PayPal</option><?php endif; ?>
        </select>
        <input name="note" maxlength="200" value="<?= e((string) $o['note']) ?>" placeholder="Hinweis">
        <button>Ändern</button>
    </form>
    <form method="post" class="inline"><input type="hidden" name="action" value="delete_order_admin"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><button class="danger">Löschen</button></form>
</li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

<?php if ($adminSection === 'suppliers'): ?>
<section class="card"><h2>Kategorien verwalten</h2>
<form method="post"><input type="hidden" name="action" value="save_category"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Name<input name="name" maxlength="80" required></label><button>Speichern</button></form>
<ul><?php foreach ($categories as $c): ?><li>
    <form method="post" class="inline"><input type="hidden" name="action" value="save_category"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
    #<?= (int) $c['id'] ?> <input name="name" maxlength="80" required value="<?= e((string) $c['name']) ?>"><button>Ändern</button></form>
    <form method="post" class="inline"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="danger">Löschen</button></form>
</li><?php endforeach; ?></ul>
</section>

<section class="card"><h2>Lieferanten verwalten</h2>
<form method="post"><input type="hidden" name="action" value="save_supplier"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<label>Name<input name="name" maxlength="120" required></label>
<label>Kategorie<select name="category_id"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option><?php endforeach; ?></select></label>
<label>Speisekarten-Link<input name="menu_url" maxlength="255"></label>
<label>Telefon<input name="phone" maxlength="40"></label>
<label class="check"><input type="checkbox" name="is_active" checked> Aktiv</label>
<button>Speichern</button></form>
<ul><?php foreach ($suppliers as $s): ?><li>
    <form method="post">
        <input type="hidden" name="action" value="save_supplier">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
        #<?= (int) $s['id'] ?>
        <input name="name" maxlength="120" required value="<?= e((string) $s['name']) ?>">
        <select name="category_id"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= ((int) $c['id'] === (int) $s['category_id']) ? 'selected' : '' ?>><?= e((string) $c['name']) ?></option><?php endforeach; ?></select>
        <input name="menu_url" maxlength="255" value="<?= e((string) $s['menu_url']) ?>" placeholder="Speisekarten-Link">
        <input name="phone" maxlength="40" value="<?= e((string) $s['phone']) ?>" placeholder="Telefon">
        <label class="check"><input type="checkbox" name="is_active" <?= ((int) $s['is_active'] === 1) ? 'checked' : '' ?>> Aktiv</label>
        <button>Ändern</button>
    </form>
    <form method="post" class="inline"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="danger">Löschen</button></form>
</li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

<?php if ($adminSection === 'settings'): ?>
<section class="card"><h2>Seiten-Einstellungen</h2>
<form method="post"><input type="hidden" name="action" value="save_page_settings"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<p class="muted">Individuelle Zeiten je Wochentag im Format HH:MM.</p>
<table class="settings-time-table">
    <thead><tr><th>Tag</th><th>Abstimmung endet</th><th>Bestellphase endet</th></tr></thead>
    <tbody>
    <?php foreach (weekday_labels() as $weekdayKey => $weekdayLabel): ?>
    <?php $votingValue = normalized_hhmm((string) ($settings['voting_end_time_' . $weekdayKey] ?? ''), '16:00'); ?>
    <?php $orderValue = normalized_hhmm((string) ($settings['order_end_time_' . $weekdayKey] ?? ''), '18:00'); ?>
    <tr>
        <th scope="row"><?= e($weekdayLabel) ?></th>
        <td><input name="voting_end_time_<?= e($weekdayKey) ?>" value="<?= e($votingValue) ?>" placeholder="HH:MM" pattern="(?:[01]\d|2[0-3]):[0-5]\d" inputmode="numeric"></td>
        <td><input name="order_end_time_<?= e($weekdayKey) ?>" value="<?= e($orderValue) ?>" placeholder="HH:MM" pattern="(?:[01]\d|2[0-3]):[0-5]\d" inputmode="numeric"></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php $resetValue = normalized_hhmm((string) ($settings['daily_reset_time'] ?? ''), '10:30'); ?>
<label>Täglicher Reset (gilt für alle Tage)
    <input name="daily_reset_time" value="<?= e($resetValue) ?>" placeholder="HH:MM" pattern="(?:[01]\d|2[0-3]):[0-5]\d" inputmode="numeric">
</label>
<fieldset>
    <legend>PayPal-Links hinzufügen/ändern</legend>
    <?php foreach ($paypalLinks as $entry): ?>
    <div class="inline">
        <input type="hidden" name="paypal_link_ids[]" value="<?= e($entry['id']) ?>">
        <input name="paypal_link_names[]" maxlength="80" placeholder="Name" value="<?= e($entry['name']) ?>">
        <input name="paypal_link_urls[]" maxlength="255" placeholder="https://paypal.me/..." value="<?= e($entry['url']) ?>">
    </div>
    <?php endforeach; ?>
    <div class="inline">
        <input type="hidden" name="paypal_link_ids[]" value="">
        <input name="paypal_link_names[]" maxlength="80" placeholder="Name">
        <input name="paypal_link_urls[]" maxlength="255" placeholder="https://paypal.me/...">
    </div>
</fieldset>
<label>Zusätzlicher Text unter Website-Titel<input name="header_subtitle" maxlength="200" value="<?= e((string) ($settings['header_subtitle'] ?? '')) ?>"></label>
<label class="check"><input type="checkbox" name="reset_daily_note" <?= (($settings['reset_daily_note'] ?? '1') === '1') ? 'checked' : '' ?>> Tageshinweis beim Reset löschen</label>
<button>Speichern</button></form>
</section>
<?php endif; ?>
<?php endif; ?>

</main></body></html>
