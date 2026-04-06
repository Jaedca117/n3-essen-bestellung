<?php

declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }

    return (string) $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}


/**
 * @return array<int,string>
 */
function weekday_keys_by_iso_number(): array
{
    return [
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
        7 => 'sunday',
    ];
}

/**
 * @return list<string>
 */
function weekday_keys(): array
{
    return array_values(weekday_keys_by_iso_number());
}

/**
 * @return array<string,string>
 */
function weekday_labels_de(): array
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
 * @return array<string,string>
 */
function weekday_short_labels_de(): array
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
function parse_weekday_csv(string $raw): array
{
    $validKeys = weekday_keys();
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
function sanitize_weekday_input($posted): array
{
    if (!is_array($posted)) {
        return [];
    }

    $validKeys = weekday_keys();
    $selected = [];
    foreach ($posted as $value) {
        $key = trim((string) $value);
        if (in_array($key, $validKeys, true)) {
            $selected[$key] = true;
        }
    }
    return array_values(array_keys($selected));
}

function current_weekday_key(): string
{
    $isoWeekday = (int) (new DateTimeImmutable('now'))->format('N');
    $map = weekday_keys_by_iso_number();
    return $map[$isoWeekday] ?? 'monday';
}

/**
 * @return list<string>
 */
function parse_supplier_weekdays(string $raw): array
{
    return parse_weekday_csv($raw);
}

/**
 * @return list<string>
 */
function sanitize_supplier_weekday_input($posted): array
{
    return sanitize_weekday_input($posted);
}

function supplier_available_on_weekday(array $supplier, string $weekdayKey): bool
{
    $weekdays = parse_supplier_weekdays((string) ($supplier['available_weekdays'] ?? ''));
    if ($weekdays === []) {
        return true;
    }
    return in_array($weekdayKey, $weekdays, true);
}

/**
 * @param array{
 *   nickname:string,
 *   dish_no:string,
 *   dish_name:string,
 *   dish_size:string,
 *   price:float,
 *   payment_method:string,
 *   note:string
 * } $payload
 * @return list<string>
 */
function validate_order_payload(array $payload, bool $paypalEnabled): array
{
    $errors = [];
    if (mb_strlen($payload['nickname']) < 2 || mb_strlen($payload['nickname']) > 40) {
        $errors[] = 'Name muss 2-40 Zeichen haben.';
    }
    if (mb_strlen($payload['dish_no']) > 20) {
        $errors[] = 'Essensnummer ist zu lang.';
    }
    if (mb_strlen($payload['dish_name']) < 2 || mb_strlen($payload['dish_name']) > 120) {
        $errors[] = 'Gericht muss 2-120 Zeichen haben.';
    }
    if (mb_strlen($payload['dish_size']) > 40) {
        $errors[] = 'Größe darf höchstens 40 Zeichen haben.';
    }
    if ($payload['price'] <= 0 || $payload['price'] > 999) {
        $errors[] = 'Preis muss zwischen 0,01 und 999 liegen.';
    }
    if (!in_array($payload['payment_method'], ['bar', 'paypal'], true)) {
        $errors[] = 'Ungültige Zahlungsart.';
    }
    if (!$paypalEnabled && $payload['payment_method'] === 'paypal') {
        $errors[] = 'PayPal ist heute nicht verfügbar.';
    }
    if (mb_strlen($payload['note']) > 200) {
        $errors[] = 'Bemerkung darf höchstens 200 Zeichen haben.';
    }
    return $errors;
}
