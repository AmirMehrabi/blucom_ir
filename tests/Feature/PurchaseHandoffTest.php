<?php

namespace Tests\Feature;

use App\Services\Commerce\MellatPaymentService;
use App\Services\CustomerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MellatFixtures;
use Tests\TestCase;

class PurchaseHandoffTest extends TestCase
{
    use MellatFixtures, RefreshDatabase;

    public function test_bank_handoff_resumes_exact_purchase_then_line_without_new_bank_operations(): void
    {
        [$order, $buyer, $number, , $fake] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payment = app(MellatPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $url = 'https://my.blucom.ir/payments/'.$payment->public_id.'/return';
        $this->actingAs($buyer, 'customer')->get($url)->assertRedirect('https://my.blucom.ir/orders/'.$order->public_id);
        $this->assertCount(1, $fake->calls);
        $this->post('https://my.blucom.ir/payments/mellat/callback/'.$payment->public_id, $this->callbackPayload($payment))->assertOk()->assertSee($url);
        $this->get($url)->assertRedirect('https://my.blucom.ir/lines/'.$number->id);
        $this->assertCount(3, $fake->calls);
        $other = app(CustomerAccountService::class)->createOwner('Other', '+989120001211', 'Other business');
        $this->actingAs($other, 'customer')->get($url)->assertNotFound();
    }
}
