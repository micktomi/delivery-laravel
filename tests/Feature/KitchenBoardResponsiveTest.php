<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Livewire\OrderBoard;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The board is a four-column kanban built for a 1280px kitchen tablet. On a
 * phone those four columns used to stay side by side at ~90px each, which
 * clipped every card. Under md the board now shows one status at a time,
 * picked from a tab bar.
 *
 * These assertions guard the hooks the layout hangs off — the per-status
 * markers that app.css targets, and the breakpoint ladder — because the
 * visibility rule lives in CSS and nothing else would notice it breaking.
 */
class KitchenBoardResponsiveTest extends TestCase
{
    use RefreshDatabase;

    private function board()
    {
        return Livewire::actingAs(User::factory()->create())->test(OrderBoard::class);
    }

    public function test_every_column_carries_its_status_marker(): void
    {
        $board = $this->board();

        foreach (['nea', 'preparing', 'ready', 'out'] as $status) {
            $board->assertSeeHtml('data-kitchen-column="'.$status.'"');
        }
    }

    public function test_a_mobile_tab_is_rendered_for_every_column(): void
    {
        $board = $this->board();

        foreach (['nea', 'preparing', 'ready', 'out'] as $status) {
            $board->assertSeeHtml('data-kitchen-tab="'.$status.'"');
            $board->assertSeeHtml("selectStatus('".$status."')");
        }
    }

    /** The tab is the only place the count is visible once a column is off screen. */
    public function test_the_tabs_show_the_order_count_per_status(): void
    {
        Order::factory()->count(2)->status(OrderStatus::Nea)->create();
        Order::factory()->status(OrderStatus::Ready)->create();

        $html = $this->board()->html();

        $this->assertMatchesRegularExpression(
            '/data-kitchen-tab="nea".*?\(2\)/s',
            $html,
            'The ΝΕΑ tab should show its two orders.'
        );
        $this->assertMatchesRegularExpression(
            '/data-kitchen-tab="ready".*?\(1\)/s',
            $html,
            'The ΕΤΟΙΜΟ tab should show its one order.'
        );
    }

    public function test_the_kanban_grid_narrows_at_each_breakpoint(): void
    {
        $html = $this->board()->html();

        // 1 column on a phone, 2 on a tablet, the original 4 from 1280px up.
        foreach (['grid-cols-1', 'md:grid-cols-2', 'xl:grid-cols-4'] as $class) {
            $this->assertStringContainsString($class, $html);
        }

        // Two rows on a tablet, or the wrapped columns would overflow the board.
        foreach (['md:grid-rows-2', 'xl:grid-rows-1'] as $class) {
            $this->assertStringContainsString($class, $html);
        }

        // The tab bar is legitimately a 4-up grid; the kanban must not be.
        $this->assertDoesNotMatchRegularExpression(
            '/grid gap-px[^"]*\sgrid-cols-4/s',
            $html,
            'On the kanban itself grid-cols-4 must stay behind the xl: prefix.'
        );
    }

    /**
     * The regression this guards is subtle and was invisible in a desktop
     * browser: Tailwind v4 emits every breakpoint as media-range syntax
     * (`@media (width>=48rem)`), which engines older than Chrome 104 drop as
     * invalid. On such a phone all four columns rendered stacked, each with
     * its own header and empty shell, because the hiding lived in a
     * `max-width` query that died with them.
     *
     * So: nothing that reserves height may be unprefixed. Whatever a browser
     * with no working media queries applies has to be the phone layout.
     */
    public function test_no_unprefixed_class_pins_the_board_to_the_viewport(): void
    {
        Order::factory()->status(OrderStatus::Nea)->create();

        $html = $this->board()->html();

        foreach (['h-dvh', 'h-screen', 'h-full', 'flex-1', 'overflow-hidden', 'overflow-y-auto', 'grid-rows-1'] as $class) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?:class="|\s)'.preg_quote($class, '/').'(?:\s|")/s',
                $html,
                'On a phone "'.$class.'" would reserve space or trap the scroll; it belongs behind md:.'
            );
        }

        // …and the tablet-and-up board still gets all of them.
        foreach (['md:h-dvh', 'md:overflow-hidden', 'md:flex-1', 'md:h-full', 'md:overflow-y-auto'] as $class) {
            $this->assertStringContainsString($class, $html);
        }
    }

    /** Scenario 5: a phone scrolls the document, so nothing above the board may trap it. */
    public function test_the_page_body_only_traps_the_scroll_from_the_tablet_up(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get('/kitchen')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<body class="([^"]*)"/', $html);
        preg_match('/<body class="([^"]*)"/', $html, $m);
        $classes = preg_split('/\s+/', trim($m[1]));

        $this->assertContains('md:overflow-hidden', $classes);
        $this->assertNotContains(
            'overflow-hidden',
            $classes,
            'An unprefixed overflow-hidden on <body> stops a phone scrolling the board at all.'
        );
    }

    /** The whole column is one element, so hiding it hides label, list and empty state. */
    public function test_the_column_marker_wraps_the_label_and_the_list(): void
    {
        Order::factory()->status(OrderStatus::Nea)->create();

        $html = $this->board()->html();

        preg_match(
            '/<div\s+data-kitchen-column="nea".*?(?=<div\s+data-kitchen-column="preparing")/s',
            $html,
            $m
        );

        $this->assertNotEmpty($m, 'Expected a ΝΕΑ column block.');
        $this->assertStringContainsString('ΝΕΑ', $m[0], 'The label must live inside the column wrapper.');
        $this->assertStringContainsString('Ακύρωση', $m[0], 'The order list must live inside the column wrapper.');
    }

    /** Scenario 2: an empty column must not carry the desktop placeholder height. */
    public function test_an_empty_column_uses_a_shorter_placeholder_on_a_phone(): void
    {
        $html = $this->board()->html();

        $this->assertStringContainsString('h-20 md:h-32', $html);
    }

    /** Scenarios 1 + 3: one long ΝΕΑ order and one ΕΤΟΙΜΟ order, ΕΤΟΙΜΑΖΕΤΑΙ empty. */
    public function test_each_column_renders_independently_of_the_others(): void
    {
        Order::factory()->status(OrderStatus::Nea)->create(['customer_name' => 'Μακρύς Πελάτης']);
        Order::factory()->status(OrderStatus::Ready)->create(['customer_name' => 'Έτοιμος Πελάτης']);

        $board = $this->board();

        $board->assertSee('Μακρύς Πελάτης');
        $board->assertSee('Έτοιμος Πελάτης');

        $html = $board->html();

        // The empty ΕΤΟΙΜΑΖΕΤΑΙ column is still rendered — CSS, not PHP, hides it,
        // so a tab switch never needs a round trip.
        $this->assertStringContainsString('data-kitchen-column="preparing"', $html);
    }

    /**
     * Scenario 4. The tab selection is deliberately not part of the Livewire
     * payload: a poll re-renders identical markup, and the browser keeps the
     * choice on <body>, which morph never touches.
     */
    public function test_a_poll_does_not_carry_or_reset_the_tab_selection(): void
    {
        Order::factory()->status(OrderStatus::Nea)->create();

        $board = $this->board();
        $before = $board->html();

        $board->call('$refresh');
        $after = $board->html();

        foreach (['nea', 'preparing', 'ready', 'out'] as $status) {
            $this->assertStringContainsString('data-kitchen-column="'.$status.'"', $after);
        }

        // Nothing status-selection-shaped is server state, so there is nothing
        // for the morph to snap back.
        $this->assertStringNotContainsString('data-kitchen-status', $before);
        $this->assertStringNotContainsString('data-kitchen-status', $after);
    }

    /**
     * Grid and flex children default to min-width:auto, which is what let the
     * cards push past their column instead of wrapping inside it.
     */
    public function test_columns_and_cards_are_allowed_to_shrink(): void
    {
        Order::factory()->status(OrderStatus::Nea)->create();

        $html = $this->board()->html();

        $this->assertMatchesRegularExpression(
            '/data-kitchen-column="nea"[^>]*class="[^"]*min-w-0/s',
            $html,
            'Each column needs min-w-0 to stay inside its grid track.'
        );
        $this->assertStringContainsString('w-full min-w-0 bg-white', $html);
    }

    /** Long Greek addresses and product names have to wrap, not clip. */
    public function test_customer_and_item_text_is_wrappable(): void
    {
        $order = Order::factory()->status(OrderStatus::Nea)->create([
            'customer_name' => 'Κωνσταντίνος Παπαδόπουλος-Αναγνωστόπουλος',
            'address' => 'Λεωφόρος Κωνσταντινουπόλεως 148, 3ος όροφος, Θεσσαλονίκη',
            'notes' => 'Χωρίς ζάχαρη σε όλα τα ροφήματα παρακαλώ πολύ',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => null,
            'product_name' => 'Sandwich Μπαγκέτα Κοτόπουλο Καπνιστό',
            'base_price' => '3.50',
            'quantity' => 2,
            'selected_options' => [['value' => 'Χωρίς μαγιονέζα']],
            'line_total' => '7.00',
            'notes' => 'Ζεστό',
        ]);

        $html = $this->board()->html();

        foreach ([
            'Κωνσταντίνος Παπαδόπουλος-Αναγνωστόπουλος',
            'Λεωφόρος Κωνσταντινουπόλεως 148, 3ος όροφος, Θεσσαλονίκη',
            'Sandwich Μπαγκέτα Κοτόπουλο Καπνιστό',
            'Χωρίς μαγιονέζα',
        ] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }

        // Every one of those lines is rendered with break-words.
        foreach ([
            'Κωνσταντίνος Παπαδόπουλος-Αναγνωστόπουλος',
            'Λεωφόρος Κωνσταντινουπόλεως 148, 3ος όροφος, Θεσσαλονίκη',
            'Sandwich Μπαγκέτα Κοτόπουλο Καπνιστό',
        ] as $text) {
            // [^<]* rather than \s*: the item line is prefixed with its quantity.
            $this->assertMatchesRegularExpression(
                '/class="[^"]*break-words[^"]*"[^>]*>[^<]*'.preg_quote(e($text), '/').'/s',
                $html,
                'Expected "'.$text.'" to sit in a break-words element.'
            );
        }
    }

    /** The advance and cancel buttons are what the tab layout exists to keep reachable. */
    public function test_action_buttons_stay_inside_the_card(): void
    {
        Order::factory()->status(OrderStatus::Nea)->create();

        $board = $this->board();

        $board->assertSee('ΕΤΟΙΜΑΖΕΤΑΙ');
        $board->assertSee('Ακύρωση');
        // w-full plus horizontal padding, so a long Greek label wraps in place
        // rather than widening the button past the card.
        $board->assertSeeHtml('mt-3 w-full px-2 py-3');
    }
}
