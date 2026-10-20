<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\CoreService;
use App\Services\CurrencyService;
use App\Services\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class ReceiptTemplateTest extends TestCase
{
    private CoreService $core;

    protected function setUp(): void
    {
        parent::setUp();

        $options = new class extends Options
        {
            public string $showSubtotal = 'yes';

            public function __construct() {}

            public function get( string|array|null $key = null, mixed $default = null )
            {
                return match ( $key ) {
                    'ns_invoice_merge_similar_products' => 'no',
                    'ns_invoice_show_subtotal' => $this->showSubtotal,
                    'ns_invoice_show_payment_rows', 'ns_invoice_show_change_due' => 'yes',
                    'ns_invoice_display_tax_breakdown' => 'no',
                    'ns_invoice_receipt_footer' => '',
                    'ns_invoice_receipt_logo' => '',
                    'ns_store_name' => 'NexoPOS',
                    default => $default,
                };
            }
        };

        $this->core = ( new \ReflectionClass( CoreService::class ) )->newInstanceWithoutConstructor();
        $this->core->option = $options;
        $this->core->math = new \App\Services\MathService;
        $currency = new CurrencyService( 0, [
            'currency_iso' => 'MXN',
            'currency_symbol' => '$',
            'currency_position' => 'before',
            'prefered_currency' => 'symbol',
            'decimal_precision' => 2,
            'decimal_separator' => '.',
            'thousand_separator' => ',',
        ] );
        $this->core->currency = $currency;
        $this->app->instance( CurrencyService::class, $currency );
        $this->app->instance( CoreService::class, $this->core );
    }

    public function test_cash_paid_receipt_keeps_paid_and_change(): void
    {
        $html = $this->renderReceipt( [ [ 'identifier' => 'cash-payment', 'value' => 225 ] ], Order::PAYMENT_PAID, 5 );

        $this->assertStringContainsString( 'Payment Method: Efectivo', $html );
        $this->assertStringContainsString( 'Paid', $html );
        $this->assertStringContainsString( 'Change', $html );
        $this->assertStringContainsString( '$ 225.00', $html );
        $this->assertStringNotContainsString( 'Unidad', $html );
        $this->assertStringNotContainsString( 'Unit Price', $html );
    }

    public function test_equal_subtotal_and_total_are_hidden(): void
    {
        $html = $this->renderReceipt(
            [ [ 'identifier' => 'cash-payment', 'value' => 225 ] ],
            Order::PAYMENT_PAID,
            subtotal: '225.00',
        );

        $this->assertStringNotContainsString( 'Sub Total', $html );
    }

    public function test_different_subtotal_and_total_are_shown(): void
    {
        $html = $this->renderReceipt(
            [ [ 'identifier' => 'cash-payment', 'value' => 225 ] ],
            Order::PAYMENT_PAID,
            subtotal: 200,
        );

        $this->assertStringContainsString( 'Sub Total', $html );
    }

    public function test_subtotal_option_can_still_hide_different_values(): void
    {
        $this->core->option->showSubtotal = 'no';
        $html = $this->renderReceipt(
            [ [ 'identifier' => 'cash-payment', 'value' => 225 ] ],
            Order::PAYMENT_PAID,
            subtotal: 200,
        );

        $this->assertStringNotContainsString( 'Sub Total', $html );
    }

    public function test_multiple_counted_products_show_the_configured_unit_price(): void
    {
        $html = $this->renderReceipt(
            [ [ 'identifier' => 'cash-payment', 'value' => 75 ] ],
            Order::PAYMENT_PAID,
            0,
            [],
            quantity: 3,
            unitPrice: 25,
        );

        $this->assertStringContainsString( '$ 25.00', $html );
        $this->assertStringContainsString( '/ Unidad', $html );
        $this->assertStringContainsString( '(x3)', $html );
    }

    public function test_fractional_products_show_the_configured_unit_price(): void
    {
        $html = $this->renderReceipt(
            [ [ 'identifier' => 'cash-payment', 'value' => 100 ] ],
            Order::PAYMENT_PAID,
            0,
            [],
            quantity: 0.25,
            unitPrice: 400,
            unitName: 'kg',
        );

        $this->assertStringContainsString( '$ 400.00', $html );
        $this->assertStringContainsString( '/ kg', $html );
        $this->assertStringContainsString( '(x0.25)', $html );
    }

    public function test_non_cash_paid_receipt_omits_paid_and_change_amounts(): void
    {
        $html = $this->renderReceipt( [ [ 'identifier' => 'bank-payment', 'value' => 225 ] ], Order::PAYMENT_PAID );

        $this->assertStringContainsString( 'Payment Method: Tarjeta', $html );
        $this->assertStringNotContainsString( '>Paid<', $html );
        $this->assertStringNotContainsString( '>Change<', $html );
    }

    public function test_partial_non_cash_receipt_keeps_paid_and_due(): void
    {
        $html = $this->renderReceipt( [ [ 'identifier' => 'bank-payment', 'value' => 100 ] ], Order::PAYMENT_PARTIALLY, -125 );

        $this->assertStringContainsString( 'Paid', $html );
        $this->assertStringContainsString( 'Due', $html );
    }

    public function test_mixed_receipt_keeps_payment_amounts(): void
    {
        $html = $this->renderReceipt( [
            [ 'identifier' => 'cash-payment', 'value' => 100 ],
            [ 'identifier' => 'bank-payment', 'value' => 125 ],
        ], Order::PAYMENT_PAID, 0 );

        $paymentSection = $this->paymentSection( $html );

        $this->assertStringNotContainsString( 'receipt-payment-method', $paymentSection );
        $this->assertSame( 4, substr_count( $paymentSection, 'text-right' ) );
    }

    public function test_refund_receipt_keeps_refunded_row(): void
    {
        $html = $this->renderReceipt(
            [ [ 'identifier' => 'bank-payment', 'value' => 225 ] ],
            Order::PAYMENT_REFUNDED,
            0,
            [ (object) [ 'total' => 25 ] ]
        );

        $this->assertStringContainsString( 'Refunded', $html );
        $this->assertStringContainsString( '$ -25.00', $html );
    }

    public function test_receipt_metadata_uses_full_width_for_column_a_only(): void
    {
        $html = $this->renderReceipt(
            [ [ 'identifier' => 'cash-payment', 'value' => 225 ] ],
            Order::PAYMENT_PAID,
            columnA: "<strong>Cliente</strong>\nCarr. Tepoztlán–San Juan km 1.9",
        );
        $this->assertStringContainsString( 'px-2 w-full', $html );
        $this->assertStringNotContainsString( 'px-2 w-1/2', $html );
        $this->assertStringContainsString( '<strong>Cliente</strong><br />', $html );
        $this->assertStringContainsString( 'Carr. Tepoztlán–San Juan km 1.9', $html );
    }

    public function test_receipt_metadata_uses_full_width_for_column_b_only(): void
    {
        $html = $this->renderReceipt(
            [ [ 'identifier' => 'cash-payment', 'value' => 225 ] ],
            Order::PAYMENT_PAID,
            columnB: "<span>Dirección</span>\nSan Juan",
        );

        $this->assertStringContainsString( 'px-2 w-full', $html );
        $this->assertStringNotContainsString( 'px-2 w-1/2', $html );
        $this->assertStringContainsString( '<span>Dirección</span><br />', $html );
        $this->assertStringContainsString( 'San Juan', $html );
    }

    public function test_receipt_metadata_keeps_half_width_for_two_columns(): void
    {
        $html = $this->renderReceipt(
            [ [ 'identifier' => 'cash-payment', 'value' => 225 ] ],
            Order::PAYMENT_PAID,
            columnA: '<strong>A</strong>',
            columnB: '<span>B</span>',
        );

        $this->assertSame( 2, substr_count( $html, 'px-2 w-1/2' ) );
        $this->assertStringNotContainsString( 'px-2 w-full', $html );
        $this->assertStringContainsString( '<strong>A</strong>', $html );
        $this->assertStringContainsString( '<span>B</span>', $html );
    }

    public function test_receipt_metadata_omits_empty_columns(): void
    {
        $html = $this->renderReceipt( [ [ 'identifier' => 'cash-payment', 'value' => 225 ] ], Order::PAYMENT_PAID );

        $this->assertStringNotContainsString( 'px-2 w-full', $html );
        $this->assertStringNotContainsString( 'px-2 w-1/2', $html );
    }

    private function renderReceipt(
        array $payments,
        string $status,
        float $change = 0,
        array $refunds = [],
        float $quantity = 1,
        float $unitPrice = 225,
        string $unitName = 'Unidad',
        float|int|string|null $subtotal = null,
        string $columnA = '',
        string $columnB = '',
    ): string
    {
        request()->merge( [ 'dash-visibility' => 'disabled' ] );

        $product = (object) [
            'name' => 'Café',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
            'unit' => (object) [ 'name' => $unitName ],
        ];
        $total = $unitPrice * $quantity;

        $order = new class( $product ) extends Order
        {
            public function __construct( private object $product ) {}

            public function getCombinedProductsAttribute(): Collection
            {
                return collect( [ $this->product ] );
            }
        };
        $order->payment_status = $status;
        $order->change = $change;
        $order->tendered = $total;
        $order->total = $total;
        $order->subtotal = $subtotal ?? $total;
        $order->discount = 0;
        $order->total_coupons = 0;
        $order->tax_value = 0;
        $order->products_tax_value = 0;
        $order->shipping = 0;
        $order->taxes = collect();
        $order->setRelation( 'settings', collect() );
        $order->setRelation( 'payments', collect( $payments ) );
        $order->setRelation( 'refund', collect( $refunds ) );
        $order->note_visibility = 'hidden';
        $order->tax_type = 'exclusive';

        return View::make( 'pages.dashboard.orders.templates._receipt', [
            'order' => $order,
            'ordersService' => new class( $columnA, $columnB )
            {
                public function __construct( private string $columnA, private string $columnB ) {}

                public function orderTemplateMapping( string $key, Order $order ): string
                {
                    return $key === 'ns_invoice_receipt_column_a' ? $this->columnA : $this->columnB;
                }
            },
            'paymentTypes' => [
                'cash-payment' => 'Efectivo',
                'bank-payment' => 'Tarjeta',
            ],
        ] )->render();
    }

    private function paymentSection( string $html ): string
    {
        preg_match( '/<tbody class="receipt-payment-section">(.*?)<\/tbody>/s', $html, $matches );

        return $matches[1] ?? '';
    }
}
