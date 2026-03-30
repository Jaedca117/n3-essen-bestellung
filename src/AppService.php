<?php

declare(strict_types=1);

final class AppService
{
    private const WEEKDAY_KEYS = [
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
        7 => 'sunday',
    ];

    public function __construct(private readonly AppRepository $repo)
    {
    }

    public function runtimeState(): array
    {
        $settings = $this->repo->getSettings();
        $this->runResetIfDue($settings);
        $settings = $this->repo->getSettings();

        $now = new DateTimeImmutable('now');
        $today = $now->format('Y-m-d');
        $weekday = self::WEEKDAY_KEYS[(int) $now->format('N')] ?? 'monday';
        $votingEnd = new DateTimeImmutable($today . ' ' . $this->timeSettingForDay($settings, 'voting_end_time', $weekday, '16:00:00'));
        $orderEnd = new DateTimeImmutable($today . ' ' . $this->timeSettingForDay($settings, 'order_end_time', $weekday, '18:00:00'));

        $orderClosed = ($settings['order_closed'] ?? '0') === '1';

        if ($now < $votingEnd) {
            $phase = 'voting';
        } elseif (!$orderClosed && $now < $orderEnd) {
            $phase = 'ordering';
        } else {
            $phase = 'closed';
        }

        return [
            'settings' => $settings,
            'phase' => $phase,
            'now' => $now,
            'voting_end' => $votingEnd,
            'order_end' => $orderEnd,
            'paypal_enabled' => trim((string) ($settings['paypal_link'] ?? '')) !== '',
        ];
    }

    public function runResetIfDue(array $settings): void
    {
        $resetTime = (string) ($settings['daily_reset_time'] ?? '10:30:00');
        $lastResetAt = trim((string) ($settings['last_reset_at'] ?? ''));

        $now = new DateTimeImmutable('now');
        $todayResetMoment = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $resetTime);

        $lastResetDay = $lastResetAt !== '' ? (new DateTimeImmutable($lastResetAt))->format('Y-m-d') : '1970-01-01';
        $needsReset = $now >= $todayResetMoment && $lastResetDay !== $now->format('Y-m-d');

        if ($needsReset) {
            $this->repo->resetDaily((($settings['reset_daily_note'] ?? '1') === '1'));
        }
    }

    public function canProceed(string $action, string $ip): bool
    {
        $limits = [
            'vote' => [10, 120],
            'order_create' => [6, 180],
            'order_update' => [10, 180],
            'admin_login' => [10, 300],
        ];
        [$max, $window] = $limits[$action] ?? [6, 120];
        $result = $this->repo->upsertRateLimit($action, $ip, $window);
        return (int) $result['request_count'] <= $max;
    }

    public function winner(array $settings): ?array
    {
        $manualId = (int) ($settings['manual_winner_supplier_id'] ?? 0);
        $results = $this->repo->voteResults();
        if ($manualId > 0) {
            foreach ($results as $r) {
                if ((int) $r['id'] === $manualId) {
                    return $r;
                }
            }
        }
        return $this->repo->winner();
    }

    private function timeSettingForDay(array $settings, string $baseKey, string $weekday, string $fallback): string
    {
        $dayValue = trim((string) ($settings[$baseKey . '_' . $weekday] ?? ''));
        if ($dayValue !== '') {
            return strlen($dayValue) === 5 ? ($dayValue . ':00') : $dayValue;
        }

        $value = trim((string) ($settings[$baseKey] ?? $fallback));
        if ($value === '') {
            return $fallback;
        }
        return strlen($value) === 5 ? ($value . ':00') : $value;
    }
}
