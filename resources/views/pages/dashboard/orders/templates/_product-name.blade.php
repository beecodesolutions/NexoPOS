<span class="">{{ $product->name }} (x{{ $product->quantity }})</span>
@if ( ($showProductUnitPrice ?? false) && is_numeric( $product->unit_price ) )
<br>
<span class="receipt-unit-price text-gray-600"><span class="receipt-unit-price-amount">{{ ns()->currency->define( $product->unit_price ) }}</span> / {{ $product->unit?->name ?? $product->unit_name }}</span>
@endif
@if ( ($showProductUnit ?? true) && ns()->option->get( 'ns_invoice_show_product_unit', 'yes' ) !== 'no' )
<br>
<span class="text-xs text-gray-600">{{ $product->unit->name }}</span>
@endif
