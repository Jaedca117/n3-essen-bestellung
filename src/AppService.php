<?php

declare(strict_types=1);

final class AppService
{
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
        $weekday = current_weekday_key();
        $votingEnd = new DateTimeImmutable($today . ' ' . $this->timeSettingForDay($settings, 'voting_end_time_' . $weekday, '16:00:00'));
        $orderEnd = new DateTimeImmutable($today . ' ' . $this->timeSettingForDay($settings, 'order_end_time_' . $weekday, '18:00:00'));

        $dayDisabledByWeekday = ($settings['day_disabled_' . $weekday] ?? '0') === '1';
        $dayDisabled = $dayDisabledByWeekday;

        if ($now < $votingEnd) {
            $phase = 'voting';
        } elseif (!$dayDisabled && $now < $orderEnd) {
            $phase = 'ordering';
        } else {
            $phase = 'closed';
        }

        $activePaypalLink = $this->activePaypalLinkForWeekday($settings, $weekday);
        $settings['paypal_link'] = $activePaypalLink['url'] ?? '';

        return [
            'settings' => $settings,
            'phase' => $phase,
            'day_disabled' => $dayDisabled,
            'now' => $now,
            'voting_end' => $votingEnd,
            'order_end' => $orderEnd,
            'paypal_enabled' => isset($activePaypalLink['url']) && trim((string) $activePaypalLink['url']) !== '',
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
            'supplier_rating' => [8, 180],
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

    private function timeSettingForDay(array $settings, string $dayKey, string $fallback): string
    {
        $dayValue = trim((string) ($settings[$dayKey] ?? ''));
        if ($dayValue !== '') {
            return strlen($dayValue) === 5 ? ($dayValue . ':00') : $dayValue;
        }
        return $fallback;
    }

    /**
     * @return array{id:string,name:string,url:string}|null
     */
    public function activePaypalLinkForWeekday(array $settings, string $weekday): ?array
    {
        $paypalLinks = $this->paypalLinkOptions($settings);
        if ($paypalLinks === []) {
            return null;
        }

        $dayKey = 'paypal_link_active_id_' . $weekday;
        if (array_key_exists($dayKey, $settings)) {
            $dayActiveId = trim((string) $settings[$dayKey]);
            if ($dayActiveId === '') {
                return null;
            }
            foreach ($paypalLinks as $entry) {
                if ($entry['id'] === $dayActiveId) {
                    return $entry;
                }
            }
            return null;
        }

        return null;
    }

    /**
     * @return list<array{id:string,name:string,url:string}>
     */
    public function paypalLinkOptions(array $settings): array
    {
        $raw = (string) ($settings['paypal_links'] ?? '');
        if ($raw === '') {
            return [];
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
}
