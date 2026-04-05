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
    $validKeys = array_values(weekday_keys_by_iso_number());
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
function sanitize_supplier_weekday_input($posted): array
{
    if (!is_array($posted)) {
        return [];
    }

    $validKeys = array_values(weekday_keys_by_iso_number());
    $selected = [];
    foreach ($posted as $value) {
        $key = trim((string) $value);
        if (in_array($key, $validKeys, true)) {
            $selected[$key] = true;
        }
    }

    return array_values(array_keys($selected));
}

function supplier_available_on_weekday(array $supplier, string $weekdayKey): bool
{
    $weekdays = parse_supplier_weekdays((string) ($supplier['available_weekdays'] ?? ''));
    if ($weekdays === []) {
        return true;
    }
    return in_array($weekdayKey, $weekdays, true);
}
