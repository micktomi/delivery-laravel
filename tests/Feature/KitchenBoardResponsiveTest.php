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
 * The board shows one status at a time, picked from a tab bar, as a grid of
 * large cards: one column on a phone, two on a tablet, three from 1024px.
 * (It used to be a four-column kanban, which clipped every card on a phone.)
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
            '/data-kitchen-tab="nea".*?data-kitchen-count[^>]*>\s*2\s*</s',
            $html,
            'The ΝΕΑ tab should show its two orders.'
        );
        $this->assertMatchesRegularExpression(
            '/data-kitchen-tab="ready".*?data-kitchen-count[^>]*>\s*1\s*</s',
            $html,
            'The ΕΤΟΙΜΟ tab should show its one order.'
        );
    }

    public function test_the_card_grid_narrows_at_each_breakpoint(): void
    {
        Order::factory()->status(OrderStatus::Nea)->create();

        $html = $this->board()->html();

        // 1 column on a phone, 2 on a tablet, 3 from 1024px up.
        foreach (['grid-cols-1', 'md:grid-cols-2', 'lg:grid-cols-3'] as $class) {
            $this->assertStringContainsString($class, $html);
        }

        // The tab bar is legitimately a 4-up grid; the card grid must not be
        // multi-column without a breakpoint prefix.
        foreach (['grid-cols-2', 'grid-cols-3', 'grid-cols-4'] as $class) {
            $this->assertDoesNotMatchRegularExpression(
                '/class="[^"]*\bgap-[^"]*(?:class="|\s)'.preg_quote($class, '/').'(?:\s|")/s',
                $html,
                'On the card grid "'.$class.'" must stay behind a breakpoint prefix.'
            );
        }
        $this->assertMatchesRegularExpression(
            '/<nav[^>]*class="[^"]*\bgrid-cols-4\b/s',
            $html,
            'The tab bar itself stays a 4-up grid.'
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
    public function test_desktop_does_not_force_all_status_sections_visible(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString("[data-kitchen-column] {
    display: none;", $css);
        $this->assertStringNotContainsString(
            '@media (min-width: 48rem)',
            $css,
            'Desktop breakpoints must not reveal every status section at once.'
        );
    }

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
        foreach (['md:h-dvh', 'md:overflow-hidden', 'md:flex-1', 'md:min-h-0', 'md:overflow-y-auto'] as $class) {
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

        $this->assertStringContainsString('Καμία παραγγελία', $html);
        $this->assertStringContainsString('py-10 text-center md:py-16', $html);
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
        $this->assertStringContainsString('flex w-full min-w-0 max-w-full max-w-md flex-col bg-white', $html);
        $this->assertStringContainsString('max-w-[1440px] min-w-0 flex-col', $html);
        $this->assertStringContainsString('w-full max-w-lg self-center', $html);
    }

    /** Prep details stay readable while courier and financial data stay off the board. */
    public function test_kitchen_cards_keep_prep_details_and_hide_logistics_and_financials(): void
    {
        $order = Order::factory()->status(OrderStatus::Nea)->create([
            'customer_name' => 'Κωνσταντίνος Παπαδόπουλος-Αναγνωστόπουλος',
            'phone' => '6912345678',
            'address' => 'Λεωφόρος Κωνσταντινουπόλεως 148, Θεσσαλονίκη',
            'floor_bell' => '3ος όροφος · κουδούνι Παπαδόπουλος',
            'notes' => 'Χωρίς ζάχαρη σε όλα τα ροφήματα παρακαλώ πολύ',
            'subtotal' => '91.23',
            'total' => '98.76',
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
            '2× Sandwich Μπαγκέτα Κοτόπουλο Καπνιστό',
            'Χωρίς μαγιονέζα',
            'Ζεστό',
            'Χωρίς ζάχαρη σε όλα τα ροφήματα παρακαλώ πολύ',
        ] as $text) {
            $this->assertStringContainsString(e($text), $html);
        }

        foreach ([
            '6912345678',
            'Λεωφόρος Κωνσταντινουπόλεως 148, Θεσσαλονίκη',
            '3ος όροφος · κουδούνι Παπαδόπουλος',
            'Υποσύνολο',
            'Προς είσπραξη',
            '98.76€',
        ] as $text) {
            $this->assertStringNotContainsString(e($text), $html);
        }

        foreach ([
            'Κωνσταντίνος Παπαδόπουλος-Αναγνωστόπουλος',
            'Sandwich Μπαγκέτα Κοτόπουλο Καπνιστό',
            'Χωρίς μαγιονέζα',
        ] as $text) {
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

        $board->assertSee('Έναρξη');
        $board->assertSee('ΕΤΟΙΜΑΖΕΤΑΙ');
        $board->assertSee('Ακύρωση');
        $board->assertSeeHtml('data-kitchen-card');
        // One large primary action per card, cancel kept as a low-emphasis
        // secondary; both stay at a tablet-friendly 56px touch height.
        $board->assertSeeHtml('min-h-14 min-w-0 grow basis-0');
        $board->assertSeeHtml('min-h-14 shrink-0');
    }

    /**
     * TransitionOrderStatus refuses ΕΤΟΙΜΟ → ΕΦΥΓΕ and ΕΦΥΓΕ → ΟΛΟΚΛΗΡΩΘΗΚΕ
     * from the board (the driver app owns them), so those cards must not
     * offer a button that can only ever fail.
     */
    public function test_driver_owned_stages_show_a_waiting_label_instead_of_an_advance_button(): void
    {
        $ready = Order::factory()->status(OrderStatus::Ready)->create();
        $out = Order::factory()->status(OrderStatus::Out)->create();

        $html = $this->board()->html();

        $this->assertStringNotContainsString("advance({$ready->id}, 'ready')", $html);
        $this->assertStringNotContainsString("advance({$out->id}, 'out')", $html);
        $this->assertStringContainsString('Αναμονή για οδηγό', $html);
        $this->assertStringContainsString('Σε διανομή', $html);
        // Cancelling stays available on every stage.
        $this->assertStringContainsString("cancel({$ready->id})", $html);
        $this->assertStringContainsString("cancel({$out->id})", $html);
    }
}
