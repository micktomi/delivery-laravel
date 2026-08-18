<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class StoreSchedule
{
    /**
     * @var array<int, string>
     */
    private const GREEK_WEEKDAYS = [
        1 => 'Δευτέρα',
        2 => 'Τρίτη',
        3 => 'Τετάρτη',
        4 => 'Πέμπτη',
        5 => 'Παρασκευή',
        6 => 'Σάββατο',
        7 => 'Κυριακή',
    ];

    public function isAcceptingOrders(?CarbonInterface $at = null): bool
    {
        if (! $this->manualOverrideAllowsOrders()) {
            return false;
        }

        $schedule = $this->openingHours();

        // No configured schedule preserves the existing always-open behaviour.
        if ($schedule === null) {
            return true;
        }

        $at = $this->localTime($at);

        foreach ([$at->subDay()->startOfDay(), $at->startOfDay()] as $day) {
            foreach ($this->intervalsFor($day, $schedule) as [$opensAt, $closesAt]) {
                if ($at->greaterThanOrEqualTo($opensAt) && $at->lessThan($closesAt)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function nextOpeningAt(?CarbonInterface $from = null): ?CarbonImmutable
    {
        if (! $this->manualOverrideAllowsOrders()) {
            return null;
        }

        $schedule = $this->openingHours();

        if ($schedule === null) {
            return null;
        }

        $from = $this->localTime($from);

        if ($this->isAcceptingOrders($from)) {
            return null;
        }

        $nextOpening = null;

        // Eight calendar days include the same weekday in the following week.
        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $from->startOfDay()->addDays($offset);

            foreach ($this->intervalsFor($day, $schedule) as [$opensAt]) {
                if ($opensAt->lessThanOrEqualTo($from)) {
                    continue;
                }

                if ($nextOpening === null || $opensAt->lessThan($nextOpening)) {
                    $nextOpening = $opensAt;
                }
            }
        }

        return $nextOpening;
    }

    public function nextOpeningText(?CarbonInterface $from = null): ?string
    {
        $from = $this->localTime($from);
        $nextOpening = $this->nextOpeningAt($from);

        if ($nextOpening === null) {
            return null;
        }

        $day = match (true) {
            $nextOpening->isSameDay($from) => 'σήμερα',
            $nextOpening->isSameDay($from->addDay()) => 'αύριο',
            default => self::GREEK_WEEKDAYS[$nextOpening->dayOfWeekIso],
        };

        return 'ανοίγουμε '.$day.' στις '.$nextOpening->format('H:i');
    }

    public function closedMessage(): ?string
    {
        $message = trim((string) config('store.closed_message', ''));

        return $message === '' ? null : $message;
    }

    public function closedCheckoutMessage(?CarbonInterface $at = null): string
    {
        $message = $this->closedMessage()
            ?? 'Το κατάστημα είναι κλειστό και δεν δέχεται παραγγελίες αυτή τη στιγμή.';
        $nextOpening = $this->nextOpeningText($at);

        return $nextOpening === null ? $message : $message.' — '.$nextOpening.'.';
    }

    private function manualOverrideAllowsOrders(): bool
    {
        return filter_var(
            config('store.accepting_orders', true),
            FILTER_VALIDATE_BOOL,
        );
    }

    /**
     * Null means that scheduling is not configured. An invalid or explicitly
     * empty JSON schedule is an empty schedule and therefore fails closed.
     *
     * @return array<string, mixed>|null
     */
    private function openingHours(): ?array
    {
        $openingHours = config('store.opening_hours');

        if ($openingHours === null || $openingHours === '') {
            return null;
        }

        if (is_string($openingHours)) {
            $openingHours = json_decode($openingHours, true);
        }

        if (! is_array($openingHours)) {
            return [];
        }

        $normalized = [];

        foreach ($openingHours as $weekday => $intervals) {
            $normalized[strtolower((string) $weekday)] = $intervals;
        }

        return $normalized;
    }

    private function localTime(?CarbonInterface $at): CarbonImmutable
    {
        $timezone = (string) config('store.timezone', 'Europe/Athens');

        return $at === null
            ? CarbonImmutable::now($timezone)
            : CarbonImmutable::instance($at)->setTimezone($timezone);
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function intervalsFor(CarbonImmutable $day, array $schedule): array
    {
        $weekday = strtolower($day->englishDayOfWeek);
        $configuredIntervals = $schedule[$weekday] ?? [];

        if (! is_array($configuredIntervals)) {
            return [];
        }

        $intervals = [];

        foreach ($configuredIntervals as $configuredInterval) {
            if (! is_array($configuredInterval)) {
                continue;
            }

            $open = (string) ($configuredInterval['open'] ?? $configuredInterval[0] ?? '');
            $close = (string) ($configuredInterval['close'] ?? $configuredInterval[1] ?? '');

            if (! $this->isValidTime($open) || ! $this->isValidTime($close) || $open === $close) {
                continue;
            }

            $opensAt = $day->setTime(
                (int) substr($open, 0, 2),
                (int) substr($open, 3, 2),
            );
            $closesAt = $day->setTime(
                (int) substr($close, 0, 2),
                (int) substr($close, 3, 2),
            );

            if ($closesAt->lessThan($opensAt)) {
                $closesAt = $closesAt->addDay();
            }

            $intervals[] = [$opensAt, $closesAt];
        }

        return $intervals;
    }

    private function isValidTime(string $time): bool
    {
        return preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/D', $time) === 1;
    }
}
