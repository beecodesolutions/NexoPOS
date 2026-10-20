<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentType;
use App\Models\Register;
use App\Models\RegisterHistory;
use App\Services\CashRegistersService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CashRegistersServicePaymentTest extends TestCase
{
    use DatabaseTransactions;

    private Register $register;

    private PaymentType $cashPayment;

    private PaymentType $bankPayment;

    private PaymentType $cardPayment;

    private CashRegistersService $service;

    private int $nextPaymentId = 100000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app( CashRegistersService::class );
        $this->cashPayment = $this->createPaymentType( OrderPayment::PAYMENT_CASH );
        $this->bankPayment = $this->createPaymentType( OrderPayment::PAYMENT_BANK );
        $this->cardPayment = $this->createPaymentType( 'card-payment' );

        $this->register = new Register;
        $this->register->forceFill( [
            'name' => 'Payment test register',
            'status' => Register::STATUS_OPENED,
            'author_id' => 1,
            'balance' => 100,
        ] );
        $this->register->saveQuietly();

        $this->createHistory( [
            'action' => RegisterHistory::ACTION_OPENING,
            'value' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
        ], now()->subMinute() );
    }

    public function test_cash_payment_changes_balance_but_bank_payment_only_keeps_history(): void
    {
        $cashOrder = $this->createOrder();
        $bankOrder = $this->createOrder();
        $cardOrder = $this->createOrder();

        $cashPayment = $this->createOrderPayment( $cashOrder, OrderPayment::PAYMENT_CASH, 40 );
        $bankPayment = $this->createOrderPayment( $bankOrder, OrderPayment::PAYMENT_BANK, 60 );
        $cardPayment = $this->createOrderPayment( $cardOrder, 'card-payment', 25 );

        $this->service->saveOrderPayment( $cashPayment );
        $this->assertSame( 140.0, (float) $this->register->refresh()->balance );

        $this->service->saveOrderPayment( $bankPayment );
        $this->service->saveOrderPayment( $cardPayment );
        $this->assertSame( 140.0, (float) $this->register->refresh()->balance );

        $cashHistory = RegisterHistory::where( 'payment_id', $cashPayment->id )->firstOrFail();
        $bankHistory = RegisterHistory::where( 'payment_id', $bankPayment->id )->firstOrFail();

        $this->assertSame( 100.0, (float) $cashHistory->balance_before );
        $this->assertSame( 140.0, (float) $cashHistory->balance_after );
        $this->assertSame( 140.0, (float) $bankHistory->balance_before );
        $this->assertSame( 140.0, (float) $bankHistory->balance_after );
        $this->assertSame( 40.0, (float) $cashHistory->value );
        $this->assertSame( 60.0, (float) $bankHistory->value );
        $this->assertSame( 25.0, (float) RegisterHistory::where( 'payment_id', $cardPayment->id )->value( 'value' ) );

        $this->service->refreshCashRegister( $this->register->refresh() );

        $this->assertSame( 140.0, (float) $this->register->refresh()->balance );
        $this->assertSame( 3, RegisterHistory::where( 'register_id', $this->register->id )
            ->where( 'action', RegisterHistory::ACTION_ORDER_PAYMENT )->count() );
        $this->assertSame( 60.0, (float) RegisterHistory::where( 'payment_id', $bankPayment->id )->value( 'value' ) );
    }

    public function test_deleting_bank_payment_history_refreshes_cash_balance(): void
    {
        $cashOrder = $this->createOrder();
        $bankOrder = $this->createOrder();
        $cashPayment = $this->createOrderPayment( $cashOrder, OrderPayment::PAYMENT_CASH, 40 );
        $bankPayment = $this->createOrderPayment( $bankOrder, OrderPayment::PAYMENT_BANK, 60 );

        $this->service->saveOrderPayment( $cashPayment );
        $this->service->saveOrderPayment( $bankPayment );

        $this->service->deleteRegisterHistoryUsingOrder( $bankOrder );

        $this->assertSame( 140.0, (float) $this->register->refresh()->balance );
        $historyTable = ( new RegisterHistory )->getTable();
        $this->assertDatabaseHas( $historyTable, [
            'register_id' => $this->register->id,
            'payment_id' => $cashPayment->id,
        ] );
        $this->assertDatabaseMissing( $historyTable, [
            'register_id' => $this->register->id,
            'payment_id' => $bankPayment->id,
        ] );
    }

    public function test_closed_register_does_not_apply_bank_payment_to_balance(): void
    {
        $this->register->status = Register::STATUS_CLOSED;
        $this->register->saveQuietly();

        $order = $this->createOrder();
        $payment = $this->createOrderPayment( $order, OrderPayment::PAYMENT_BANK, 60 );

        $this->service->saveOrderPayment( $payment );

        $this->assertSame( 100.0, (float) $this->register->refresh()->balance );
        $this->assertSame( 100.0, (float) RegisterHistory::where( 'payment_id', $payment->id )->value( 'balance_after' ) );
    }

    private function createPaymentType( string $identifier ): PaymentType
    {
        $existingPaymentType = PaymentType::identifier( $identifier )->first();

        if ( $existingPaymentType instanceof PaymentType ) {
            return $existingPaymentType;
        }

        $paymentType = new PaymentType;
        $paymentType->forceFill( [
            'label' => $identifier,
            'identifier' => $identifier,
            'author_id' => 1,
            'active' => true,
            'readonly' => true,
        ] );
        $paymentType->saveQuietly();

        return $paymentType;
    }

    private function createOrder(): Order
    {
        $order = new Order;
        $order->forceFill( [
            'code' => 'PAYMENT-' . uniqid(),
            'type' => Order::TYPE_TAKEAWAY,
            'payment_status' => Order::PAYMENT_PAID,
            'customer_id' => 1,
            'author_id' => 1,
            'register_id' => $this->register->id,
        ] );
        $order->saveQuietly();

        return $order;
    }

    private function createOrderPayment( Order $order, string $identifier, float $value ): OrderPayment
    {
        $id = $this->nextPaymentId++;
        $payment = new OrderPayment;
        $payment->setRawAttributes( [
            'id' => $id,
            'order_id' => $order->id,
            'identifier' => $identifier,
            'value' => $value,
            'author_id' => 1,
        ], true );
        $payment->setRelation( 'order', $order );
        $payment->setRelation( 'type', match ( $identifier ) {
            OrderPayment::PAYMENT_CASH => $this->cashPayment,
            OrderPayment::PAYMENT_BANK => $this->bankPayment,
            default => $this->cardPayment,
        } );

        return $payment;
    }

    private function createHistory( array $attributes, Carbon $createdAt ): RegisterHistory
    {
        $history = new RegisterHistory;
        $history->forceFill( array_merge( [
            'register_id' => $this->register->id,
            'author_id' => 1,
        ], $attributes ) );
        $history->created_at = $createdAt;
        $history->updated_at = $createdAt;
        $history->saveQuietly();

        return $history;
    }
}
