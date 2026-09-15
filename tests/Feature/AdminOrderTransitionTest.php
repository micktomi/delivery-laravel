<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminOrderTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_stale_admin_table_cannot_advance_an_order_another_admin_already_moved(): void
    {
        $order = Order::factory()->create(['display_number' => 7]);

        // Two admins open the orders table while the order is still new.
        $first = Livewire::test(ListOrders::class);
        $stale = Livewire::test(ListOrders::class);

        $this->assertSame('nea', $this->renderedExpectedStatus($first, $order));
        $this->assertSame('nea', $this->renderedExpectedStatus($stale, $order));

        $this->advance($first, $order, $this->renderedExpectedStatus($first, $order))
            ->assertNotified('Κατάσταση ενημερώθηκε');

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);

        // The second table was never redrawn, so its button still carries ΝΕΑ.
        $this->advance($stale, $order, $this->renderedExpectedStatus($stale, $order))
            ->assertNotified('Η παραγγελία #007 είναι ήδη σε κατάσταση ΕΤΟΙΜΑΖΕΤΑΙ. Ο πίνακας ανανεώθηκε.');

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);

        $stale->call('$refresh');

        $this->assertSame('preparing', $this->renderedExpectedStatus($stale, $order));

        $this->advance($stale, $order, $this->renderedExpectedStatus($stale, $order))
            ->assertNotified('Κατάσταση ενημερώθηκε');

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    public static function invalidArguments(): array
    {
        return [
            'no expected status (plain Filament click)' => [[]],
            'unknown status' => [['expected' => 'teleported']],
            'non-string status' => [['expected' => ['nea']]],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_a_missing_or_invalid_expected_status_is_rejected_without_changing_the_order(array $arguments): void
    {
        $order = Order::factory()->create();

        Livewire::test(ListOrders::class)
            ->call('mountTableAction', 'advance', (string) $order->getKey(), $arguments)
            ->assertNotified('Η κατάσταση της παραγγελίας δεν επιβεβαιώθηκε. Ανανεώστε τη λίστα και δοκιμάστε ξανά.');

        $this->assertSame(OrderStatus::Nea, $order->fresh()->status);
    }

    /**
     * Reads the expected status out of the advance button exactly as the
     * table drew it, rather than from the database.
     */
    private function renderedExpectedStatus(Testable $component, Order $order): string
    {
        // Decoded once, as the browser does: a double-escaped handler would not match.
        $html = html_entity_decode($component->html(), ENT_QUOTES | ENT_HTML5);
        $handler = 'x-on:click="$wire.mountTableAction(\'advance\', \''.$order->getKey().'\', { expected: \'';
        $pattern = '/'.preg_quote($handler, '/').'([a-z]+)'.preg_quote('\' })"', '/').'/';

        $this->assertMatchesRegularExpression($pattern, $html, 'The advance button does not carry the rendered status.');
        $this->assertStringNotContainsString('wire:click="mountTableAction(\'advance\'', $html, 'A second, argument-less click handler is still rendered.');
        preg_match($pattern, $html, $matches);

        return $matches[1];
    }

    private function advance(Testable $component, Order $order, string $expected): Testable
    {
        return $component->call('mountTableAction', 'advance', (string) $order->getKey(), ['expected' => $expected]);
    }
}
