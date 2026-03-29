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

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_settings')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $allowed = ['voting_end_time','order_end_time','daily_reset_time','paypal_link','daily_note','order_closed','reset_daily_note','manual_winner_supplier_id'];
        foreach ($allowed as $key) {
            $value = trim((string) ($_POST[$key] ?? ''));
            if ($key === 'order_closed' || $key === 'reset_daily_note') {
                $value = isset($_POST[$key]) ? '1' : '0';
            }
            $repo->saveSetting($key, $value);
        }
        $message = 'Einstellungen gespeichert.';
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

$settings = $repo->getSettings();
$categories = $repo->categories();
$suppliers = $repo->suppliers();
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin</title><link rel="stylesheet" href="style.css"></head>
<body><main class="container"><h1>Admin-Bereich</h1>
<p><a href="index.php">Zur Startseite</a><?= $isAdmin ? ' · <a href="?logout=1">Logout</a>' : '' ?></p>
<?php if ($message): ?><p class="notice success"><?= e($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="notice error"><?= e($error) ?></p><?php endif; ?>

<?php if (!$isAdmin): ?>
<section class="card"><h2>Login</h2>
<form method="post"><input type="hidden" name="action" value="login"><label>Benutzername<input name="username" required></label><label>Passwort<input type="password" name="password" required></label><button>Anmelden</button></form></section>
<?php else: ?>
<section class="card"><h2>Einstellungen</h2>
<form method="post"><input type="hidden" name="action" value="save_settings"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<label>Abstimmung endet (HH:MM:SS)<input name="voting_end_time" value="<?= e((string) ($settings['voting_end_time'] ?? '16:00:00')) ?>"></label>
<label>Bestellphase endet (HH:MM:SS)<input name="order_end_time" value="<?= e((string) ($settings['order_end_time'] ?? '18:00:00')) ?>"></label>
<label>Täglicher Reset (HH:MM:SS)<input name="daily_reset_time" value="<?= e((string) ($settings['daily_reset_time'] ?? '10:30:00')) ?>"></label>
<label>PayPal-Link<input name="paypal_link" value="<?= e((string) ($settings['paypal_link'] ?? '')) ?>"></label>
<label>Tageshinweis<input name="daily_note" maxlength="200" value="<?= e((string) ($settings['daily_note'] ?? '')) ?>"></label>
<label>Manueller Gewinner (Lieferanten-ID)<input name="manual_winner_supplier_id" value="<?= e((string) ($settings['manual_winner_supplier_id'] ?? '')) ?>"></label>
<label class="check"><input type="checkbox" name="order_closed" <?= (($settings['order_closed'] ?? '0') === '1') ? 'checked' : '' ?>> Bestellung abgeschlossen</label>
<label class="check"><input type="checkbox" name="reset_daily_note" <?= (($settings['reset_daily_note'] ?? '1') === '1') ? 'checked' : '' ?>> Tageshinweis beim Reset löschen</label>
<button>Speichern</button></form>
</section>

<section class="card"><h2>Kategorie hinzufügen</h2>
<form method="post"><input type="hidden" name="action" value="save_category"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Name<input name="name" maxlength="80" required></label><button>Speichern</button></form>
<ul><?php foreach ($categories as $c): ?><li>#<?= (int) $c['id'] ?> - <?= e((string) $c['name']) ?></li><?php endforeach; ?></ul>
</section>

<section class="card"><h2>Lieferant hinzufügen</h2>
<form method="post"><input type="hidden" name="action" value="save_supplier"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<label>Name<input name="name" maxlength="120" required></label>
<label>Kategorie<select name="category_id"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option><?php endforeach; ?></select></label>
<label>Speisekarten-Link<input name="menu_url" maxlength="255"></label>
<label>Telefon<input name="phone" maxlength="40"></label>
<label class="check"><input type="checkbox" name="is_active" checked> Aktiv</label>
<button>Speichern</button></form>
<ul><?php foreach ($suppliers as $s): ?><li>#<?= (int) $s['id'] ?> - <?= e((string) $s['name']) ?> (<?= e((string) ($s['category_name'] ?? '-')) ?>)</li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

</main></body></html>
