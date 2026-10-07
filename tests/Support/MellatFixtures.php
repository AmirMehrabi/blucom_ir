<?php

namespace Tests\Support;

use App\Contracts\MellatClient;
use App\Models\PaymentGateway;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\PaymentGatewayService;
use Illuminate\Support\Str;

trait MellatFixtures
{
    use CheckoutFixtures;

    private function paymentFixture(array $responses = ['0,SyntheticCaseRef'], int $transactionLevel = 1, int $amount = 250000): array
    {
        config(['commerce.catalog_enabled' => true, 'commerce.reservation_enabled' => true, 'commerce.checkout_enabled' => true]);
        [$offer, $number, $admin] = $this->offer();
        if ($amount !== 250000) {
            $offers = app(NumberOfferService::class);
            $offers->withdraw($admin, $number->id, $number->inventory_revision, 'Synthetic amount boundary');
            $number = $number->fresh();
            $offer = $offers->publish($admin, $number->id, $number->inventory_revision, $offer->plan_version_id, $amount);
            $number = $number->fresh();
        }
        $buyer = $this->buyer();
        $checkout = app(CheckoutService::class);
        $order = $checkout->reserve($buyer, $offer->id, $checkout->quote($buyer, $offer->id), (string) Str::uuid());
        app(PaymentGatewayService::class)->update($admin, 'mellat', [
            'revision' => 0, 'enabled' => true, 'amount_unit_confirmed' => true,
            'merchant_terminal_id' => '123456', 'merchant_username' => 'synthetic-merchant', 'merchant_password' => 'synthetic-test-only',
        ]);
        $fake = new FakeMellatClient($responses, $transactionLevel);
        $this->app->instance(MellatClient::class, $fake);

        return [$order, $buyer, $number, $admin, $fake, PaymentGateway::query()->where('provider', 'mellat')->firstOrFail()];
    }

    private function callbackPayload($attempt, string $reference = '7654321'): array
    {
        return ['RefId' => $attempt->ref_id, 'ResCode' => '0', 'SaleOrderId' => (string) $attempt->id, 'SaleReferenceId' => $reference];
    }
}
