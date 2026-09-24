<?php

namespace App\Console\Commands;

use App\Models\InboundRoute;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\BlucomOwner;
use App\Services\NumberNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportWorkingVoip extends Command
{
    protected $signature = 'voip:import-working';

    protected $description = 'Import the current test extension, DID and routes without changing FreeSWITCH';

    public function handle(BlucomOwner $owner, NumberNormalizer $numbers): int
    {
        $tenant = $owner->get();
        $extensionNumber = '1000';
        $did = '982191093464';
        $gatewayName = config('voip.allowed_outbound_gateways.0');

        if ($gatewayName !== 'provider-trunk') {
            $this->error('The approved gateway configuration does not match the working gateway.');

            return self::FAILURE;
        }

        $existing = SipExtension::query()->where('extension', $extensionNumber)->first();
        if ($existing !== null && $existing->tenant_id !== $tenant->id) {
            $this->error('Extension 1000 already belongs to another owner.');

            return self::FAILURE;
        }

        $password = null;
        if ($existing === null) {
            $password = $this->secret('Enter the CURRENT SIP password for extension 1000');
            if (! is_string($password) || $password === '') {
                $this->error('A current SIP password is required.');

                return self::FAILURE;
            }
        }

        $normalized = $numbers->normalizeOrFail($did);
        $existingNumber = SipNumber::query()->where('normalized_number', $normalized)->first();
        if ($existingNumber !== null && $existingNumber->tenant_id !== $tenant->id) {
            $this->error('The DID already belongs to another owner.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($tenant, $extensionNumber, $did, $normalized, $gatewayName, $password): void {
                $gateway = SipGateway::query()->firstOrCreate(
                    ['name' => $gatewayName],
                    ['host' => config('voip.provider_trunk_host'), 'port' => 5060, 'transport' => 'udp', 'profile' => 'external', 'context' => 'public', 'enabled' => true],
                );
                $extension = SipExtension::query()->firstOrCreate(
                    ['extension' => $extensionNumber],
                    ['tenant_id' => $tenant->id, 'password_encrypted' => $password, 'display_name' => 'Zoiper', 'enabled' => true],
                );
                $number = SipNumber::query()->firstOrCreate(
                    ['normalized_number' => $normalized],
                    ['tenant_id' => $tenant->id, 'number' => $did, 'status' => SipNumber::STATUS_ASSIGNED, 'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => true],
                );
                $existingInbound = InboundRoute::query()->where('sip_number_id', $number->id)->first();
                $existingOutbound = OutboundRoute::query()->where('sip_extension_id', $extension->id)->first();
                if (($existingInbound !== null && $existingInbound->destination_id !== $extension->id)
                    || ($existingOutbound !== null && ($existingOutbound->sip_number_id !== $number->id || $existingOutbound->gateway_id !== $gateway->id))) {
                    throw new \RuntimeException('Existing routes differ from the working call paths. Review them manually.');
                }
                InboundRoute::query()->firstOrCreate(
                    ['sip_number_id' => $number->id],
                    ['tenant_id' => $tenant->id, 'destination_type' => 'extension', 'destination_id' => $extension->id, 'enabled' => true],
                );
                OutboundRoute::query()->firstOrCreate(
                    ['sip_extension_id' => $extension->id],
                    ['tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'gateway_id' => $gateway->id, 'enabled' => true],
                );
            });
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Working VoIP records are present. FreeSWITCH was not changed.');

        return self::SUCCESS;
    }
}
