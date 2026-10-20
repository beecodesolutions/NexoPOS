<?php
use App\Models\Order;
use App\Models\OrderPayment;
use App\Classes\Hook;
use Illuminate\Support\Facades\View;

$prefered_price     =   $order->settings?->where( 'key', 'ns_pos_prefered_price' )->first()?->value;
$pos_vat          =   $order->settings?->where( 'key', 'ns_pos_vat' )->first()?->value;
$payments          =   collect( $order->payments );
$hasCashPayment    =   $payments->contains( fn ( $payment ) => ( $payment[ 'identifier' ] ?? null ) === OrderPayment::PAYMENT_CASH );
$hasNonCashPayment =   $payments->contains( fn ( $payment ) => ( $payment[ 'identifier' ] ?? null ) !== OrderPayment::PAYMENT_CASH );
$showPaymentAmounts =  $hasCashPayment && $hasNonCashPayment;
$showPaidAndChange  =  $hasCashPayment || $order->payment_status === Order::PAYMENT_PARTIALLY;
$showSubtotal       =  ns()->option->get( 'ns_invoice_show_subtotal', 'yes' ) === 'yes'
    && ns()->currency->define( $order->subtotal )->toFloat() !== ns()->currency->define( $order->total )->toFloat();
$receiptColumnA     = (string) $ordersService->orderTemplateMapping( 'ns_invoice_receipt_column_a', $order );
$receiptColumnB     = (string) $ordersService->orderTemplateMapping( 'ns_invoice_receipt_column_b', $order );
$receiptColumnClass = trim( $receiptColumnA ) !== '' && trim( $receiptColumnB ) !== '' ? 'w-1/2' : 'w-full';
?>
<style>
    #nexopos-sale-receipt .receipt-payment-section tr:first-child td {
        border-top: 1px solid #1f2937;
        padding-top: 3mm;
    }

    #nexopos-sale-receipt .receipt-payment-method {
        width: auto !important;
        text-align: left !important;
    }

    #nexopos-sale-receipt .receipt-unit-price {
        display: inline-block;
        font-size: 0.75em;
        line-height: 1.1;
        white-space: nowrap;
    }

    #nexopos-sale-receipt .receipt-unit-price-amount {
        white-space: nowrap;
    }
</style>
@if ( request()->query( 'dash-visibility' ) === 'disabled' )
<style media="print">
    @@page {
        size: auto;
        margin: 0;
    }

    body {
        margin: 0 !important;
    }

    #nexopos-sale-receipt {
        width: 44mm;
        margin: 0;
        font: 10px/1.2 Arial, sans-serif;
        text-align: left;
    }

    #nexopos-sale-receipt .text-sm,
    #nexopos-sale-receipt td {
        font-size: 10px;
        line-height: 1.2;
    }

    #nexopos-sale-receipt .receipt-sheet {
        width: 44mm;
        max-width: 44mm;
        margin: 0;
        padding: 1mm 0;
        box-shadow: none;
    }

    #nexopos-sale-receipt .receipt-header h3 {
        font-size: 13px;
        line-height: 1.2;
    }

    #nexopos-sale-receipt .receipt-header {
        justify-content: flex-start;
    }

    #nexopos-sale-receipt .receipt-header img {
        max-width: 100%;
        height: auto;
    }

    #nexopos-sale-receipt .receipt-meta {
        padding: 1mm 0;
    }

    #nexopos-sale-receipt .receipt-columns {
        margin: 0;
    }

    #nexopos-sale-receipt .receipt-columns > div {
        padding: 0 1mm;
    }

    #nexopos-sale-receipt table {
        table-layout: fixed;
        border-collapse: collapse;
    }

    #nexopos-sale-receipt td {
        padding: 0.75mm 0.5mm;
        overflow-wrap: anywhere;
        vertical-align: top;
    }

    #nexopos-sale-receipt td:last-child {
        width: 16mm;
        text-align: right;
    }

    #nexopos-sale-receipt .receipt-payment-section tr:first-child td {
        padding-top: 3mm;
    }

    #nexopos-sale-receipt .receipt-unit-price {
        font-size: 8px;
    }

    #nexopos-sale-receipt .receipt-total-row td {
        font-weight: 700;
    }

    #nexopos-sale-receipt .receipt-note,
    #nexopos-sale-receipt .receipt-footer {
        padding: 2mm 0 1mm;
        text-align: left;
    }
</style>
@endif
<div id="nexopos-sale-receipt" class="w-full h-full">
    <div class="receipt-sheet w-full md:w-1/2 lg:w-1/3 shadow-lg bg-white p-2 mx-auto">
        <div class="receipt-header flex items-center justify-center">
            @if ( empty( ns()->option->get( 'ns_invoice_receipt_logo' ) ) )
            <h3 class="text-3xl font-bold">{{ ns()->option->get( 'ns_store_name' ) }}</h3>
            @else
            <img src="{{ ns()->option->get( 'ns_invoice_receipt_logo' ) }}" alt="{{ ns()->option->get( 'ns_store_name' ) }}">
            @endif
        </div>
        <div class="receipt-meta p-2 border-b border-gray-700">
            <div class="receipt-columns flex flex-wrap -mx-2 text-sm">
                @if ( trim( $receiptColumnA ) !== '' )
                <div class="px-2 {{ $receiptColumnClass }}">
                    {!! nl2br( $receiptColumnA ) !!}
                </div>
                @endif
                @if ( trim( $receiptColumnB ) !== '' )
                <div class="px-2 {{ $receiptColumnClass }}">
                    {!! nl2br( $receiptColumnB ) !!}
                </div>
                @endif
            </div>
        </div>
        <div class="table w-full">
            <table class="w-full">
                <thead>
                    <tr class="font-semibold">
                        <td colspan="2" class="p-2 border-b border-gray-800">{{ __( 'Product' ) }}</td>
                        <td class="p-2 border-b border-gray-800 text-right">{{ __( 'Total' ) }}</td>
                    </tr>
                </thead>
                <tbody class="text-sm">
                    @foreach( Hook::filter( 'ns-receipt-products', $order->combinedProducts ) as $product )
                    <tr>
                        <td colspan="2" class="p-2 border-b border-gray-700">
                            <?php $productName  =   View::make( 'pages.dashboard.orders.templates._product-name', [
                                'product' => $product,
                                'showProductUnit' => false,
                                'showProductUnitPrice' => is_numeric( $product->quantity ) && (float) $product->quantity !== 1.0,
                            ] );?>
                            <?php echo Hook::filter( 'ns-receipt-product-name', $productName->render(), $product );?>
                        </td>
                        <td class="p-2 border-b border-gray-800 text-right">{{ ns()->currency->define( $product->total_price ) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tbody>
                    @if( $pos_vat === 'products_vat' )
                        @if( $prefered_price === 'net_prices' )
                        <tr>
                            <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">{{ __( 'Product Taxes' ) }}</td>
                            <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->products_tax_value ) }}</td>
                        </tr>
                        @else
                        <tr>
                            <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">{{ __( 'Product Taxes (Included)' ) }}</td>
                            <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->products_tax_value ) }}</td>
                        </tr>
                        @endif
                    @endif
                    @if ( $showSubtotal )
                    <tr>
                        <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">{{ __( 'Sub Total' ) }}</td>
                        <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->subtotal ) }}</td>
                    </tr>
                    @endif
                    @if ( $order->discount > 0 )
                    <tr>
                        <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">
                            <span>{{ __( 'Discount' ) }}</span>
                            @if ( $order->discount_type === 'percentage' )
                            <span>({{ $order->discount_percentage }}%)</span>
                            @endif
                        </td>
                        <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->discount ) }}</td>
                    </tr>
                    @endif
                    @if ( $order->total_coupons > 0 )
                    <tr>
                        <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">
                            <span>{{ __( 'Coupons' ) }}</span>
                        </td>
                        <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->total_coupons ) }}</td>
                    </tr>
                    @endif
                    @if ( ns()->option->get( 'ns_invoice_display_tax_breakdown' ) === 'yes' ) 
                        @foreach( $order->taxes as $tax )
                        <tr>
                            <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">
                                <span>{{ $tax->tax_name }} — {{ $order->tax_type === 'inclusive' ? __( 'Inclusive' ) : __( 'Exclusive' ) }}</span>
                            </td>
                            <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $tax->tax_value ) }}</td>
                        </tr>
                        @endforeach
                        @if ( $order->products_tax_value > 0 )
                        <tr>
                            <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">
                                <span>{{ $order->tax_type === 'inclusive' ? __( 'Inclusive Product Taxes' ) : __( 'Exclusive Product Taxes' ) }}</span>
                            </td>
                            <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->products_tax_value ) }}</td>
                        </tr>
                        @endif
                    @else                     
                        @if ( $order->tax_value > 0 )
                        <tr>
                            <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">
                                <span>{{ $order->tax_group?->name ?? __( 'Unassigned Tax Group' ) }} ({{ $order->tax_type === 'inclusive' ? __( 'Inclusive' ) : '' }})</span>
                            </td>
                            <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->tax_value ) }}</td>
                        </tr>
                        @endif
                    @endif
                    @if ( $order->shipping > 0 )
                    <tr>
                        <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">{{ __( 'Shipping' ) }}</td>
                        <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->shipping ) }}</td>
                    </tr>
                    @endif
                    <tr class="receipt-total-row">
                        <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">{{ __( 'Total' ) }}</td>
                        <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->total ) }}</td>
                    </tr>
                </tbody>
                <tbody class="receipt-payment-section">
                    @if ( ns()->option->get( 'ns_invoice_show_payment_rows', 'yes' ) === 'yes' )
                    @foreach( $payments as $payment )
                    <tr>
                        @if ( $showPaymentAmounts )
                        <td class="p-2 border-b border-gray-800 text-sm font-semibold" colspan="2">{{ $paymentTypes[ $payment[ 'identifier' ] ] ?? __( 'Unknown Payment' ) }}</td>
                        <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $payment[ 'value' ] ) }}</td>
                        @else
                        <td colspan="3" class="receipt-payment-method p-2 border-b border-gray-800 text-sm font-semibold">{{ __( 'Payment Method' ) }}: {{ $paymentTypes[ $payment[ 'identifier' ] ] ?? __( 'Unknown Payment' ) }}</td>
                        @endif
                    </tr>
                    @endforeach
                    @endif
                    @if ( $showPaidAndChange )
                    <tr>
                        <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">{{ __( 'Paid' ) }}</td>
                        <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->tendered ) }}</td>
                    </tr>
                    @endif
                    @if ( in_array( $order->payment_status, [ 'refunded', 'partially_refunded' ]) )
                        @foreach( $order->refund as $refund )
                        <tr>
                            <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">{{ __( 'Refunded' ) }}</td>
                            <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( - $refund->total ) }}</td>
                        </tr>
                        @endforeach
                    @endif
                    @if ( ns()->option->get( 'ns_invoice_show_change_due', 'yes' ) !== 'no' )
                    @switch( $order->payment_status )
                        @case( Order::PAYMENT_PAID )
                        @if ( $hasCashPayment )
                        <tr>
                            <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">{{ __( 'Change' ) }}</td>
                            <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( $order->change ) }}</td>
                        </tr>
                        @endif
                        @break
                        @case( Order::PAYMENT_PARTIALLY )
                        <tr>
                            <td colspan="2" class="p-2 border-b border-gray-800 text-sm font-semibold">{{ __( 'Due' ) }}</td>
                            <td class="p-2 border-b border-gray-800 text-sm text-right">{{ ns()->currency->define( abs( $order->change ) ) }}</td>
                        </tr>
                        @break
                    @endswitch
                    @endif
                </tbody>
            </table>
            @if( $order->note_visibility === 'visible' )
            <div class="receipt-note pt-6 pb-4 text-center text-gray-800 text-sm">
                <strong>{{ __( 'Note: ' ) }}</strong> {{ $order->note }}
            </div>
            @endif
            <div class="receipt-footer pt-6 pb-4 text-center text-gray-800 text-sm">
                {{ ns()->option->get( 'ns_invoice_receipt_footer' ) }}
            </div>
        </div>
    </div>
</div>
@includeWhen( request()->query( 'autoprint' ) === 'true', '/pages/dashboard/orders/templates/_autoprint' )
