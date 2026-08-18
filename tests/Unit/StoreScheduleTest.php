<?php

namespace Tests\Unit;

use App\Support\StoreSchedule;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class StoreScheduleTest extends TestCase
{
    public function test_normal_same_day_interval_is_open(): void
    {
        $this->configure([
            'monday' => [['07:00', '15:00']],
        ]);

        $this->assertTrue($this->schedule()->isAcceptingOrders($this->at('2026-08-17 10:00')));
    }

    public function test_multiple_intervals_on_the_same_day_are_evaluated_independently(): void
    {
        $this->configure([
            'monday' => [
                ['07:00', '10:00'],
                ['12:00', '15:00'],
            ],
        ]);

        $this->assertFalse($this->schedule()->isAcceptingOrders($this->at('2026-08-17 11:00')));
        $this->assertTrue($this->schedule()->isAcceptingOrders($this->at('2026-08-17 13:00')));
    }

    public function test_exact_closing_boundary_is_closed(): void
    {
        $this->configure([
            'monday' => [['09:00', '17:00']],
        ]);

        $this->assertFalse($this->schedule()->isAcceptingOrders($this->at('2026-08-17 17:00')));
    }

    public function test_overnight_interval_is_open_before_midnight(): void
    {
        $this->configure([
            'monday' => [['19:00', '01:30']],
        ]);

        $this->assertTrue($this->schedule()->isAcceptingOrders($this->at('2026-08-17 23:45')));
    }

    public function test_overnight_interval_is_open_after_midnight_from_the_previous_day(): void
    {
        $this->configure([
            'monday' => [['19:00', '01:30']],
        ]);

        $this->assertTrue($this->schedule()->isAcceptingOrders($this->at('2026-08-18 01:15')));
        $this->assertFalse($this->schedule()->isAcceptingOrders($this->at('2026-08-18 01:30')));
    }

    public function test_manual_accepting_orders_false_overrides_an_open_schedule(): void
    {
        $this->configure([
            'monday' => [['00:00', '23:59']],
        ], acceptingOrders: false);

        $schedule = $this->schedule();

        $this->assertFalse($schedule->isAcceptingOrders($this->at('2026-08-17 10:00')));
        $this->assertNull($schedule->nextOpeningAt($this->at('2026-08-17 10:00')));
    }

    public function test_next_opening_at_returns_the_next_interval_in_athens(): void
    {
        $this->configure([
            'monday' => [['07:00', '10:00']],
            'tuesday' => [['07:00', '14:00']],
        ]);

        $from = $this->at('2026-08-17 20:00');
        $nextOpening = $this->schedule()->nextOpeningAt($from);

        $this->assertNotNull($nextOpening);
        $this->assertSame('Europe/Athens', $nextOpening->timezoneName);
        $this->assertSame('2026-08-18 07:00:00', $nextOpening->format('Y-m-d H:i:s'));
        $this->assertSame('ανοίγουμε αύριο στις 07:00', $this->schedule()->nextOpeningText($from));
    }

    /**
     * @param  array<string, list<array{0: string, 1: string}>>  $openingHours
     */
    private function configure(array $openingHours, bool $acceptingOrders = true): void
    {
        config()->set('store.accepting_orders', $acceptingOrders);
        config()->set('store.opening_hours', $openingHours);
        config()->set('store.timezone', 'Europe/Athens');
    }

    private function schedule(): StoreSchedule
    {
        return app(StoreSchedule::class);
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Europe/Athens');
    }
}
