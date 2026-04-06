<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connect($config['db']);
$repo = new AppRepository($pdo, (string) ($config['db']['table_prefix'] ?? 'n3_essen_'));
$service = new AppService($repo);
$state = $service->runtimeState();
$message = null;
$error = null;
$currentAdmin = null;
$adminRole = null;
$isSuperAdmin = false;

function set_admin_flash(string $type, string $text): void
{
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'text' => $text,
    ];
}

function consume_admin_flash(): ?array
{
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    return is_array($flash) ? $flash : null;
}

function redirect_back_to_admin(): void
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? 'admin.php');
    if ($requestUri === '' || str_contains($requestUri, "\n") || str_contains($requestUri, "\r")) {
        $requestUri = 'admin.php';
    }
    header('Location: ' . $requestUri);
    exit;
}

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

$flash = consume_admin_flash();
if ($flash) {
    $flashType = (string) ($flash['type'] ?? '');
    $flashText = (string) ($flash['text'] ?? '');
    if ($flashType === 'success') {
        $message = $flashText;
    } elseif ($flashType === 'error') {
        $error = $flashText;
    }
}

$isAdmin = isset($_SESSION['admin_id']);
if ($isAdmin) {
    $currentAdmin = $repo->findAdminById((int) $_SESSION['admin_id']);
    if (!$currentAdmin) {
        unset($_SESSION['admin_id']);
        session_regenerate_id(true);
        header('Location: admin.php');
        exit;
    }
    $adminRole = (string) ($currentAdmin['role'] ?? 'orga');
    $isSuperAdmin = can_manage_users($adminRole);
    $repo->purgeOldAuditLogs(7);
}

$adminSections = [
    'current' => 'Aktuelle Bestellung',
    'suppliers' => 'Lieferanten',
    'times' => 'Zeiten',
    'paypal' => 'PayPal',
    'general' => 'Seiten-Einstellungen',
    'audit' => 'Audit Log',
    'users' => 'User Verwaltung',
];

if (!$isSuperAdmin) {
    unset($adminSections['general']);
    unset($adminSections['audit']);
    unset($adminSections['users']);
}

$adminSection = (string) ($_GET['section'] ?? 'current');
if ($adminSection === 'settings') {
    $adminSection = 'general';
}
if (!array_key_exists($adminSection, $adminSections)) {
    $adminSection = 'current';
}

$auditActionLabels = [
    'save_current_settings' => 'Aktuelle Bestellung gespeichert',
    'save_time_settings' => 'Zeiten gespeichert',
    'save_paypal_settings' => 'PayPal gespeichert',
    'save_general_settings' => 'Einstellungen gespeichert',
    'save_category' => 'Kategorie gespeichert',
    'delete_category' => 'Kategorie gelöscht',
    'save_supplier' => 'Lieferant gespeichert',
    'delete_supplier' => 'Lieferant gelöscht',
    'save_order_admin' => 'Bestellung aktualisiert',
    'delete_order_admin' => 'Bestellung gelöscht',
    'create_admin_user' => 'Admin/Orga User angelegt',
    'update_admin_user' => 'Admin/Orga User aktualisiert',
    'delete_admin_user' => 'Admin/Orga User gelöscht',
];

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

/**
 * @return array<string, string>
 */
function weekday_short_labels(): array
{
    return [
        'monday' => 'Mo',
        'tuesday' => 'Di',
        'wednesday' => 'Mi',
        'thursday' => 'Do',
        'friday' => 'Fr',
        'saturday' => 'Sa',
        'sunday' => 'So',
    ];
}

/**
 * @return list<string>
 */
function parse_editable_weekdays(string $raw): array
{
    $validKeys = array_keys(weekday_labels());
    $parts = array_filter(array_map('trim', explode(',', $raw)), static fn(string $v): bool => $v !== '');
    $normalized = [];
    foreach ($parts as $part) {
        if (in_array($part, $validKeys, true)) {
            $normalized[$part] = true;
        }
    }
    return array_values(array_keys($normalized));
}

/**
 * @return list<string>
 */
function sanitize_editable_weekday_input($posted): array
{
    if (!is_array($posted)) {
        return [];
    }
    $validKeys = array_keys(weekday_labels());
    $selected = [];
    foreach ($posted as $value) {
        $key = trim((string) $value);
        if (in_array($key, $validKeys, true)) {
            $selected[$key] = true;
        }
    }
    return array_values(array_keys($selected));
}

/**
 * @return list<string>
 */
function effective_editable_weekdays(array $adminUser): array
{
    if ((string) ($adminUser['role'] ?? '') === 'admin') {
        return array_keys(weekday_labels());
    }
    $fromDb = parse_editable_weekdays((string) ($adminUser['editable_weekdays'] ?? ''));
    if ($fromDb === []) {
        return array_keys(weekday_labels());
    }
    return $fromDb;
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

function is_valid_admin_username(string $username): bool
{
    return preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $username) === 1;
}

function can_manage_users(?string $role): bool
{
    return $role === 'admin';
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

/**
 * @param array<string, scalar|null> $details
 */
function record_audit_log(AppRepository $repo, ?array $currentAdmin, string $actionKey, string $targetType, string $targetId, array $details = []): void
{
    if (!$currentAdmin) {
        return;
    }
    $role = (string) ($currentAdmin['role'] ?? 'orga');
    if (!in_array($role, ['admin', 'orga'], true)) {
        return;
    }

    $repo->logAdminAction(
        (int) $currentAdmin['id'],
        (string) $currentAdmin['username'],
        $role,
        $actionKey,
        $targetType,
        $targetId,
        $details
    );
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_current_settings')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $dailyNote = trim((string) ($_POST['daily_note'] ?? ''));
        $repo->saveSetting('daily_note', $dailyNote);
        $repo->saveSetting('reset_daily_note', isset($_POST['reset_daily_note']) ? '1' : '0');
        $manualWinnerSupplierId = trim((string) ($_POST['manual_winner_supplier_id'] ?? ''));
        $supplierIds = array_map(static fn(array $supplier): string => (string) $supplier['id'], $repo->allSuppliers());
        if ($manualWinnerSupplierId !== '' && !in_array($manualWinnerSupplierId, $supplierIds, true)) {
            $error = 'Manueller Gewinner ist ungültig.';
        } else {
            $repo->saveSetting('manual_winner_supplier_id', $manualWinnerSupplierId);
        }

        if ($error === null) {
            $message = 'Bereich "Aktuelle Bestellung" gespeichert.';
            record_audit_log($repo, $currentAdmin, 'save_current_settings', 'settings', 'current', [
                'reset_daily_note' => isset($_POST['reset_daily_note']) ? '1' : '0',
                'manual_winner_supplier_id' => $manualWinnerSupplierId,
            ]);
            set_admin_flash('success', $message);
            redirect_back_to_admin();
        }
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_time_settings')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $editableWeekdays = $currentAdmin ? effective_editable_weekdays($currentAdmin) : [];
        foreach (weekday_labels() as $weekdayKey => $_) {
            if (!in_array($weekdayKey, $editableWeekdays, true)) {
                continue;
            }
            $repo->saveSetting(
                'voting_end_time_' . $weekdayKey,
                normalized_hhmm((string) ($_POST['voting_end_time_' . $weekdayKey] ?? ''), '16:00')
            );
            $repo->saveSetting(
                'order_end_time_' . $weekdayKey,
                normalized_hhmm((string) ($_POST['order_end_time_' . $weekdayKey] ?? ''), '18:00')
            );
            $repo->saveSetting(
                'day_disabled_' . $weekdayKey,
                isset($_POST['day_disabled_' . $weekdayKey]) ? '1' : '0'
            );
        }
        $repo->saveSetting('order_closed', '0');

        $existingPaypalIds = [];
        foreach (paypal_link_options($repo->getSettings()) as $entry) {
            $existingPaypalIds[$entry['id']] = true;
        }
        foreach (weekday_labels() as $weekdayKey => $_) {
            if (!in_array($weekdayKey, $editableWeekdays, true)) {
                continue;
            }
            $selectedPaypalId = trim((string) ($_POST['paypal_link_active_id_' . $weekdayKey] ?? ''));
            if ($selectedPaypalId !== '' && !isset($existingPaypalIds[$selectedPaypalId])) {
                $error = 'PayPal-Account für ' . weekday_labels()[$weekdayKey] . ' ist ungültig.';
                break;
            }
            $repo->saveSetting('paypal_link_active_id_' . $weekdayKey, $selectedPaypalId);
        }

        if ($error !== null) {
            $state = $service->runtimeState();
            $settings = $repo->getSettings();
        } else {
        $message = 'Bereich "Zeiten" gespeichert.';
        $todayWeekday = current_weekday_key();
        record_audit_log($repo, $currentAdmin, 'save_time_settings', 'settings', 'times', [
            'day_disabled_today' => isset($_POST['day_disabled_' . $todayWeekday]) ? '1' : '0',
        ]);
        set_admin_flash('success', $message);
        redirect_back_to_admin();
        }
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_paypal_settings')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $editableWeekdays = $currentAdmin ? effective_editable_weekdays($currentAdmin) : [];

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

        $paypalLinkIdsByKey = [];
        foreach ($paypalLinks as $entry) {
            $paypalLinkIdsByKey[$entry['id']] = true;
        }

        if ($error === null) {
            $fallbackPaypalId = $paypalLinks[0]['id'] ?? '';
            $fallbackPaypalUrl = $paypalLinks[0]['url'] ?? '';
            $repo->saveSetting('paypal_links', json_encode($paypalLinks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $repo->saveSetting('paypal_link_active_id', $fallbackPaypalId);
            $repo->saveSetting('paypal_link', $fallbackPaypalUrl);
            $updatedSettings = $repo->getSettings();
            foreach (weekday_labels() as $weekdayKey => $_) {
                if (!in_array($weekdayKey, $editableWeekdays, true)) {
                    continue;
                }
                $dayActiveId = trim((string) ($updatedSettings['paypal_link_active_id_' . $weekdayKey] ?? ''));
                if ($dayActiveId !== '' && !isset($paypalLinkIdsByKey[$dayActiveId])) {
                    $repo->saveSetting('paypal_link_active_id_' . $weekdayKey, '');
                }
            }

            $message = 'Bereich "PayPal" gespeichert.';
            record_audit_log($repo, $currentAdmin, 'save_paypal_settings', 'settings', 'paypal', [
                'paypal_links_count' => count($paypalLinks),
            ]);
            set_admin_flash('success', $message);
            redirect_back_to_admin();
        }
        $state = $service->runtimeState();
    }
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_general_settings')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } elseif (!$isSuperAdmin) {
        $error = 'Nur Admins dürfen diesen Bereich bearbeiten.';
    } else {
        $repo->saveSetting('daily_reset_time', normalized_hhmm((string) ($_POST['daily_reset_time'] ?? ''), '10:30'));
        $repo->saveSetting('header_subtitle', trim((string) ($_POST['header_subtitle'] ?? '')));
        $repo->saveSetting('day_disabled_notice', trim((string) ($_POST['day_disabled_notice'] ?? '')));
        $message = 'Bereich "Einstellungen" gespeichert.';
        record_audit_log($repo, $currentAdmin, 'save_general_settings', 'settings', 'general', [
            'daily_reset_time' => normalized_hhmm((string) ($_POST['daily_reset_time'] ?? ''), '10:30'),
        ]);
        set_admin_flash('success', $message);
        redirect_back_to_admin();
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
            record_audit_log($repo, $currentAdmin, 'save_category', 'category', (string) ((int) ($_POST['id'] ?? 0)), ['name' => $name]);
            $message = 'Kategorie gespeichert.';
            set_admin_flash('success', $message);
            redirect_back_to_admin();
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
            record_audit_log($repo, $currentAdmin, 'delete_category', 'category', (string) $categoryId);
            $message = 'Kategorie gelöscht.';
            set_admin_flash('success', $message);
            redirect_back_to_admin();
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
            'order_method' => trim((string) ($_POST['order_method'] ?? '')),
            'available_weekdays' => sanitize_supplier_weekday_input($_POST['available_weekdays'] ?? []),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
        if ($payload['name'] === '' || $payload['category_id'] <= 0) {
            $error = 'Lieferant unvollständig.';
        } else {
            $payload['available_weekdays'] = implode(',', $payload['available_weekdays']);
            $repo->upsertSupplier($payload);
            record_audit_log($repo, $currentAdmin, 'save_supplier', 'supplier', (string) $payload['id'], [
                'name' => $payload['name'],
                'category_id' => $payload['category_id'],
                'available_weekdays' => $payload['available_weekdays'],
            ]);
            $message = 'Lieferant gespeichert.';
            set_admin_flash('success', $message);
            redirect_back_to_admin();
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
            record_audit_log($repo, $currentAdmin, 'delete_supplier', 'supplier', (string) $supplierId);
            $message = 'Lieferant gelöscht.';
            set_admin_flash('success', $message);
            redirect_back_to_admin();
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
            'is_paid' => isset($_POST['is_paid']) ? 1 : 0,
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
                record_audit_log($repo, $currentAdmin, 'save_order_admin', 'order', (string) $orderId, [
                    'nickname' => $payload['nickname'],
                    'dish_name' => $payload['dish_name'],
                    'payment_method' => $payload['payment_method'],
                    'is_paid' => $payload['is_paid'],
                ]);
                $message = 'Bestellung aktualisiert.';
                set_admin_flash('success', $message);
                redirect_back_to_admin();
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
            record_audit_log($repo, $currentAdmin, 'delete_order_admin', 'order', (string) $orderId);
            $message = 'Bestellung gelöscht.';
            set_admin_flash('success', $message);
            redirect_back_to_admin();
        }
    }
}

if ($isAdmin && $isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_admin_user')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $userId = (int) ($_POST['id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'orga');
        $editableWeekdays = sanitize_editable_weekday_input($_POST['editable_weekdays'] ?? []);
        $editableWeekdaysDb = $role === 'orga' ? implode(',', $editableWeekdays) : '';

        if (!is_valid_admin_username($username)) {
            $error = 'Benutzername ungültig (3-40 Zeichen, Buchstaben/Zahlen/._-).';
        } elseif (!in_array($role, ['admin', 'orga'], true)) {
            $error = 'Rolle ist ungültig.';
        } elseif ($role === 'orga' && $editableWeekdays === []) {
            $error = 'Für Orga-User muss mindestens ein bearbeitbarer Wochentag ausgewählt werden.';
        } elseif ($userId <= 0 && mb_strlen($password) < 8) {
            $error = 'Passwort muss mindestens 8 Zeichen haben.';
        } else {
            try {
                if ($userId > 0) {
                    $existing = $repo->findAdminById($userId);
                    if (!$existing) {
                        $error = 'User nicht gefunden.';
                    } else {
                        $passwordHash = mb_strlen($password) >= 8 ? password_hash($password, PASSWORD_DEFAULT) : null;
                        $repo->updateAdminUser($userId, $username, $role, $editableWeekdaysDb, $passwordHash);
                        record_audit_log($repo, $currentAdmin, 'update_admin_user', 'admin_user', (string) $userId, [
                            'username' => $username,
                            'role' => $role,
                            'editable_weekdays' => $editableWeekdaysDb,
                            'password_changed' => $passwordHash !== null ? '1' : '0',
                        ]);
                        $message = 'User aktualisiert.';
                        set_admin_flash('success', $message);
                        redirect_back_to_admin();
                    }
                } else {
                    $repo->createAdminUser($username, password_hash($password, PASSWORD_DEFAULT), $role, $editableWeekdaysDb);
                    record_audit_log($repo, $currentAdmin, 'create_admin_user', 'admin_user', $username, [
                        'username' => $username,
                        'role' => $role,
                        'editable_weekdays' => $editableWeekdaysDb,
                    ]);
                    $message = 'User erstellt.';
                    set_admin_flash('success', $message);
                    redirect_back_to_admin();
                }
            } catch (Throwable) {
                $error = 'Benutzername ist bereits vergeben.';
            }
        }
    }
}

if ($isAdmin && $isSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'delete_admin_user')) {
    if (!verify_csrf_token($_POST['csrf'] ?? null)) {
        $error = 'Ungültiges CSRF-Token.';
    } else {
        $userId = (int) ($_POST['id'] ?? 0);
        if ($userId <= 0) {
            $error = 'User-ID ungültig.';
        } elseif ((int) $currentAdmin['id'] === $userId) {
            $error = 'Du kannst deinen eigenen User nicht löschen.';
        } else {
            $allUsers = $repo->allAdminUsers();
            $adminCount = count(array_filter($allUsers, static fn(array $row): bool => (string) ($row['role'] ?? '') === 'admin'));
            $toDelete = $repo->findAdminById($userId);
            if (!$toDelete) {
                $error = 'User nicht gefunden.';
            } elseif ((string) $toDelete['role'] === 'admin' && $adminCount <= 1) {
                $error = 'Mindestens ein Admin muss bestehen bleiben.';
            } else {
                $repo->deleteAdminUser($userId);
                record_audit_log($repo, $currentAdmin, 'delete_admin_user', 'admin_user', (string) $userId, [
                    'username' => (string) $toDelete['username'],
                    'role' => (string) $toDelete['role'],
                ]);
                $message = 'User gelöscht.';
                set_admin_flash('success', $message);
                redirect_back_to_admin();
            }
        }
    }
}

$settings = $repo->getSettings();
$categories = $repo->categories();
$suppliers = $repo->allSuppliers();
$orders = $repo->orders();
$adminUsers = $isSuperAdmin ? $repo->allAdminUsers() : [];
$auditLogs = $isSuperAdmin ? $repo->auditLogsLastDays(7) : [];
$paypalLinks = paypal_link_options($settings);
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin</title><link rel="stylesheet" href="style.css"></head>
<body><main class="container"><h1>Admin-Bereich</h1>
<p><a href="index.php">Zur Startseite</a><?= $isAdmin ? ' · <a href="print.php">Druckansicht</a> · <a href="?logout=1">Logout</a>' : '' ?></p>
<?php if ($isAdmin && $currentAdmin): ?>
<p class="muted">Angemeldet als <strong><?= e((string) $currentAdmin['username']) ?></strong> (<?= e($adminRole === 'admin' ? 'Admin' : 'Orga') ?>)</p>
<?php endif; ?>
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
<?php
$todayWeekday = current_weekday_key();
$todayDayDisabled = (($settings['day_disabled_' . $todayWeekday] ?? '0') === '1') || (($settings['order_closed'] ?? '0') === '1');
if ($todayDayDisabled):
?>
<p class="notice error">Für heute ist die Bestellung deaktiviert.</p>
<?php endif; ?>
<form method="post"><input type="hidden" name="action" value="save_current_settings"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<p class="muted">Druckansicht: <a href="print.php">print.php öffnen</a></p>
<label>Tageshinweis<input name="daily_note" maxlength="200" value="<?= e((string) ($settings['daily_note'] ?? '')) ?>"></label>
<label class="check"><input type="checkbox" name="reset_daily_note" <?= (($settings['reset_daily_note'] ?? '1') === '1') ? 'checked' : '' ?>> Tageshinweis beim Reset löschen</label>
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
<button>Speichern</button></form>
</section>

<section class="card"><h2>Aktuelle Bestellungen verwalten</h2>
<ul class="admin-collapsible-list"><?php foreach ($orders as $o): ?><li>
    <details class="admin-collapsible-item">
        <summary>
            <span>#<?= (int) $o['id'] ?> · <?= e((string) $o['nickname']) ?></span>
            <span><?= e((string) $o['dish_name']) ?> · <?= number_format((float) $o['price'], 2, ',', '.') ?> €</span>
        </summary>
        <div class="admin-collapsible-content">
            <form method="post">
                <input type="hidden" name="action" value="save_order_admin">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <input name="nickname" maxlength="40" required value="<?= e((string) $o['nickname']) ?>" placeholder="Name">
                <input name="dish_no" maxlength="20" value="<?= e((string) $o['dish_no']) ?>" placeholder="Nr.">
                <input name="dish_name" maxlength="120" required value="<?= e((string) $o['dish_name']) ?>" placeholder="Gericht">
                <input name="dish_size" maxlength="40" value="<?= e((string) ($o['dish_size'] ?? '')) ?>" placeholder="Größe (z. B. 30cm)">
                <input type="number" step="0.01" min="0.01" max="999" name="price" required value="<?= e((string) $o['price']) ?>" placeholder="Preis">
                <select name="payment_method">
                    <option value="bar" <?= ($o['payment_method'] === 'bar') ? 'selected' : '' ?>>Bar</option>
                    <?php if ($state['paypal_enabled']): ?><option value="paypal" <?= ($o['payment_method'] === 'paypal') ? 'selected' : '' ?>>PayPal</option><?php endif; ?>
                </select>
                <label class="check"><input type="checkbox" name="is_paid" value="1" <?= ((int) ($o['is_paid'] ?? 0) === 1) ? 'checked' : '' ?>> Bezahlt</label>
                <input name="note" maxlength="200" value="<?= e((string) $o['note']) ?>" placeholder="Hinweis">
                <button>Ändern</button>
            </form>
            <form method="post" class="inline"><input type="hidden" name="action" value="delete_order_admin"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><button class="danger">Löschen</button></form>
        </div>
    </details>
</li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

<?php if ($adminSection === 'suppliers'): ?>
<section class="card"><h2>Kategorien verwalten</h2>
<details class="admin-create-panel">
    <summary>➕ Neue Kategorie</summary>
    <form method="post" class="admin-create-form"><input type="hidden" name="action" value="save_category"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Name<input name="name" maxlength="80" required></label><button>💾 Kategorie speichern</button></form>
</details>
<ul class="admin-collapsible-list"><?php foreach ($categories as $c): ?><li>
    <details class="admin-collapsible-item">
        <summary>
            <span>#<?= (int) $c['id'] ?> · <?= e((string) $c['name']) ?></span>
            <span>Kategorie</span>
        </summary>
        <div class="admin-collapsible-content">
            <form method="post">
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input name="name" maxlength="80" required value="<?= e((string) $c['name']) ?>">
                <button class="secondary">✏️ Ändern</button>
            </form>
            <form method="post" class="inline"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="danger">🗑️ Löschen</button></form>
        </div>
    </details>
</li><?php endforeach; ?></ul>
</section>

<section class="card"><h2>Lieferanten verwalten</h2>
<details class="admin-create-panel">
    <summary>➕ Neuer Lieferant</summary>
    <form method="post" class="admin-create-form"><input type="hidden" name="action" value="save_supplier"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label>Name<input name="name" maxlength="120" required></label>
    <label>Kategorie<select name="category_id"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option><?php endforeach; ?></select></label>
    <label>Speisekarten-Link<input name="menu_url" maxlength="255"></label>
    <label>Bestellverfahren<textarea name="order_method" rows="3" maxlength="1000" placeholder="z. B. telefonisch unter 0123..., per WhatsApp oder über https://..."></textarea></label>
    <fieldset>
        <legend>Verfügbare Wochentage (leer = jeden Tag)</legend>
        <div class="weekday-compact-list">
            <?php foreach (weekday_short_labels() as $weekdayKey => $weekdayLabel): ?>
                <label class="check"><input type="checkbox" name="available_weekdays[]" value="<?= e($weekdayKey) ?>"> <?= e($weekdayLabel) ?></label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <label class="check"><input type="checkbox" name="is_active" checked> Aktiv</label>
    <button>💾 Lieferant speichern</button></form>
</details>
<ul class="admin-collapsible-list"><?php foreach ($suppliers as $s): ?><li>
    <details class="admin-collapsible-item">
        <summary>
            <span>#<?= (int) $s['id'] ?> · <?= e((string) $s['name']) ?></span>
            <span><?= ((int) $s['is_active'] === 1) ? 'Aktiv' : 'Inaktiv' ?></span>
        </summary>
        <div class="admin-collapsible-content">
            <form method="post">
                <input type="hidden" name="action" value="save_supplier">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <input name="name" maxlength="120" required value="<?= e((string) $s['name']) ?>">
                <select name="category_id"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= ((int) $c['id'] === (int) $s['category_id']) ? 'selected' : '' ?>><?= e((string) $c['name']) ?></option><?php endforeach; ?></select>
                <input name="menu_url" maxlength="255" value="<?= e((string) $s['menu_url']) ?>" placeholder="Speisekarten-Link">
                <textarea name="order_method" rows="2" maxlength="1000" placeholder="Bestellverfahren"><?= e((string) ($s['order_method'] ?? '')) ?></textarea>
                <?php $supplierWeekdays = parse_supplier_weekdays((string) ($s['available_weekdays'] ?? '')); ?>
                <fieldset>
                    <legend>Verfügbare Wochentage (leer = jeden Tag)</legend>
                    <div class="weekday-compact-list">
                        <?php foreach (weekday_short_labels() as $weekdayKey => $weekdayLabel): ?>
                            <label class="check"><input type="checkbox" name="available_weekdays[]" value="<?= e($weekdayKey) ?>" <?= in_array($weekdayKey, $supplierWeekdays, true) ? 'checked' : '' ?>> <?= e($weekdayLabel) ?></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <label class="check"><input type="checkbox" name="is_active" <?= ((int) $s['is_active'] === 1) ? 'checked' : '' ?>> Aktiv</label>
                <button>Ändern</button>
            </form>
            <form method="post" class="inline"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="danger">Löschen</button></form>
        </div>
    </details>
</li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

<?php if ($adminSection === 'times'): ?>
<section class="card"><h2>Zeiten</h2>
<form method="post"><input type="hidden" name="action" value="save_time_settings"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<?php $editableWeekdaysCurrent = $currentAdmin ? effective_editable_weekdays($currentAdmin) : array_keys(weekday_labels()); ?>
<?php if (count($editableWeekdaysCurrent) < count(weekday_labels())): ?>
<p class="muted">Du kannst aktuell nur diese Tage bearbeiten: <?= e(implode(', ', array_map(static fn(string $key): string => weekday_labels()[$key] ?? $key, $editableWeekdaysCurrent))) ?>.</p>
<?php endif; ?>
<div class="settings-day-list">
    <?php foreach (weekday_labels() as $weekdayKey => $weekdayLabel): ?>
    <?php $votingValue = normalized_hhmm((string) ($settings['voting_end_time_' . $weekdayKey] ?? ''), '16:00'); ?>
    <?php $orderValue = normalized_hhmm((string) ($settings['order_end_time_' . $weekdayKey] ?? ''), '18:00'); ?>
    <?php $dayPaypalId = trim((string) ($settings['paypal_link_active_id_' . $weekdayKey] ?? '')); ?>
    <?php $canEditDay = in_array($weekdayKey, $editableWeekdaysCurrent, true); ?>
    <details class="admin-collapsible-item settings-day-item">
        <summary>
            <span><?= e($weekdayLabel) ?></span>
            <?php if (!$canEditDay): ?><span class="muted">Nicht bearbeitbar</span><?php endif; ?>
        </summary>
        <div class="admin-collapsible-content settings-day-content">
            <label>Abstimmung endet
                <input type="time" name="voting_end_time_<?= e($weekdayKey) ?>" value="<?= e($votingValue) ?>" step="60" <?= $canEditDay ? '' : 'disabled' ?>>
            </label>
            <label>Bestellphase endet
                <input type="time" name="order_end_time_<?= e($weekdayKey) ?>" value="<?= e($orderValue) ?>" step="60" <?= $canEditDay ? '' : 'disabled' ?>>
            </label>
            <label class="check">
                <input type="checkbox" name="day_disabled_<?= e($weekdayKey) ?>" value="1" <?= (($settings['day_disabled_' . $weekdayKey] ?? '0') === '1') ? 'checked' : '' ?> <?= $canEditDay ? '' : 'disabled' ?>>
                Bestellung für <?= e($weekdayLabel) ?> deaktivieren
            </label>
            <label>PayPal-Account
                <select name="paypal_link_active_id_<?= e($weekdayKey) ?>" <?= $canEditDay ? '' : 'disabled' ?>>
                    <option value="">-- Kein Link --</option>
                    <?php foreach ($paypalLinks as $entry): ?>
                        <option value="<?= e($entry['id']) ?>" <?= ($dayPaypalId === $entry['id']) ? 'selected' : '' ?>><?= e($entry['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </details>
    <?php endforeach; ?>
</div>
<button>Zeiten speichern</button></form>
</section>
<?php endif; ?>

<?php if ($adminSection === 'paypal'): ?>
<section class="card"><h2>PayPal</h2>
<form method="post"><input type="hidden" name="action" value="save_paypal_settings"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<ul class="admin-collapsible-list">
<?php foreach ($paypalLinks as $entry): ?>
    <li>
        <details class="admin-collapsible-item">
            <summary>
                <span><?= e($entry['name']) ?></span>
                <span class="muted"><?= e($entry['url']) ?></span>
            </summary>
            <div class="admin-collapsible-content">
                <input type="hidden" name="paypal_link_ids[]" value="<?= e($entry['id']) ?>">
                <label>Name<input name="paypal_link_names[]" maxlength="80" placeholder="Name" value="<?= e($entry['name']) ?>"></label>
                <label>Link<input name="paypal_link_urls[]" maxlength="255" placeholder="https://paypal.me/..." value="<?= e($entry['url']) ?>"></label>
            </div>
        </details>
    </li>
<?php endforeach; ?>
</ul>
<details class="admin-create-panel">
    <summary>➕ Neuer PayPal-Link</summary>
    <div class="admin-create-form">
        <input type="hidden" name="paypal_link_ids[]" value="">
        <label>Name<input name="paypal_link_names[]" maxlength="80" placeholder="Name"></label>
        <label>Link<input name="paypal_link_urls[]" maxlength="255" placeholder="https://paypal.me/..."></label>
    </div>
</details>
<button>PayPal speichern</button></form>
</section>
<?php endif; ?>

<?php if ($adminSection === 'general' && $isSuperAdmin): ?>
<section class="card"><h2>Seiten-Einstellungen</h2>
<form method="post"><input type="hidden" name="action" value="save_general_settings"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<?php $resetValue = normalized_hhmm((string) ($settings['daily_reset_time'] ?? ''), '10:30'); ?>
<label>Täglicher Reset (gilt für alle Tage)
    <input type="time" name="daily_reset_time" value="<?= e($resetValue) ?>" step="60">
</label>
<label>Zusätzlicher Text unter Website-Titel<input name="header_subtitle" maxlength="200" value="<?= e((string) ($settings['header_subtitle'] ?? '')) ?>"></label>
<label>Hinweistext bei deaktivierten Bestellungen<input name="day_disabled_notice" maxlength="250" value="<?= e((string) ($settings['day_disabled_notice'] ?? 'Bestellungen sind heute deaktiviert.')) ?>"></label>
<button>Einstellungen speichern</button></form>
</section>
<?php endif; ?>

<?php if ($adminSection === 'users' && $isSuperAdmin): ?>
<section class="card"><h2>User Verwaltung</h2>
<p class="muted">Nur Admins können User anlegen, bearbeiten und löschen.</p>
<details class="admin-create-panel">
    <summary>➕ Neuer User</summary>
    <form method="post" class="admin-create-form">
        <input type="hidden" name="action" value="save_admin_user">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Benutzername<input name="username" maxlength="40" required placeholder="z. B. orga_team"></label>
        <label>Passwort (mind. 8 Zeichen)<input type="password" name="password" minlength="8" required></label>
        <label>Rolle
            <select name="role">
                <option value="orga">Orga</option>
                <option value="admin">Admin</option>
            </select>
        </label>
        <fieldset>
            <legend>Bearbeitbare Tage (nur für Orga)</legend>
            <div class="weekday-compact-list">
                <?php foreach (weekday_short_labels() as $weekdayKey => $weekdayLabel): ?>
                    <label class="check"><input type="checkbox" name="editable_weekdays[]" value="<?= e($weekdayKey) ?>" checked> <?= e($weekdayLabel) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <button>Neuen User anlegen</button>
    </form>
</details>
<ul class="admin-collapsible-list">
    <?php foreach ($adminUsers as $adminUser): ?>
    <?php $userEditableWeekdays = effective_editable_weekdays($adminUser); ?>
    <li>
        <details class="admin-collapsible-item">
            <summary>
                <span>#<?= (int) $adminUser['id'] ?> · <?= e((string) $adminUser['username']) ?></span>
                <span><?= ((string) $adminUser['role'] === 'admin') ? 'Admin' : 'Orga' ?></span>
            </summary>
            <div class="admin-collapsible-content">
                <form method="post">
                    <input type="hidden" name="action" value="save_admin_user">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $adminUser['id'] ?>">
                    <label>Benutzername<input name="username" maxlength="40" required value="<?= e((string) $adminUser['username']) ?>"></label>
                    <label>Rolle
                        <select name="role">
                            <option value="orga" <?= ((string) $adminUser['role'] === 'orga') ? 'selected' : '' ?>>Orga</option>
                            <option value="admin" <?= ((string) $adminUser['role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </label>
                    <label>Neues Passwort (optional)<input type="password" name="password" minlength="8" placeholder="Neues Passwort (optional)"></label>
                    <fieldset>
                        <legend>Bearbeitbare Tage (nur für Orga)</legend>
                        <div class="weekday-compact-list">
                            <?php foreach (weekday_short_labels() as $weekdayKey => $weekdayLabel): ?>
                                <label class="check"><input type="checkbox" name="editable_weekdays[]" value="<?= e($weekdayKey) ?>" <?= in_array($weekdayKey, $userEditableWeekdays, true) ? 'checked' : '' ?>> <?= e($weekdayLabel) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <button>Speichern</button>
                </form>
                <form method="post" class="inline"><input type="hidden" name="action" value="delete_admin_user"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $adminUser['id'] ?>"><button class="danger" <?= ((int) $adminUser['id'] === (int) $currentAdmin['id']) ? 'disabled' : '' ?>>Löschen</button></form>
            </div>
        </details>
    </li>
    <?php endforeach; ?>
</ul>
</section>
<?php endif; ?>

<?php if ($adminSection === 'audit' && $isSuperAdmin): ?>
<section class="card"><h2>Audit Log (letzte 7 Tage)</h2>
<?php if ($auditLogs === []): ?>
    <p class="muted">Keine Einträge vorhanden.</p>
<?php else: ?>
    <table>
        <thead>
            <tr><th>Zeit</th><th>Benutzer</th><th>Rolle</th><th>Aktion</th><th>Ziel</th><th>Details</th></tr>
        </thead>
        <tbody>
        <?php foreach ($auditLogs as $log): ?>
            <tr>
                <td><?= e((string) $log['created_at']) ?></td>
                <td><?= e((string) $log['actor_username']) ?></td>
                <td><?= e((string) $log['actor_role']) ?></td>
                <td><?= e($auditActionLabels[(string) $log['action_key']] ?? (string) $log['action_key']) ?></td>
                <td><?= e((string) $log['target_type']) ?><?= ((string) $log['target_id'] !== '') ? ' #' . e((string) $log['target_id']) : '' ?></td>
                <td><code><?= e((string) $log['details_json']) ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</section>
<?php endif; ?>
<?php endif; ?>

</main></body></html>
