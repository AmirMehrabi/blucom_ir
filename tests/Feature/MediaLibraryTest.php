<?php

namespace Tests\Feature;

use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Models\TenantAudioAsset;
use App\Services\CustomerAccountService;
use App\Services\OutboundCalling\AudioAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_uploads_and_previews_a_clip_and_another_tenant_cannot_access_it(): void
    {
        if (! (new ExecutableFinder)->find('ffmpeg')) {
            $this->markTestSkipped('FFmpeg is required to normalize the upload.');
        }

        Storage::fake('ivr');
        $accounts = app(CustomerAccountService::class);
        $owner = $accounts->createOwner('Owner', '+989120000011', 'Business');
        $other = $accounts->createOwner('Other', '+989120000012', 'Other');
        $samples = str_repeat("\0", 16000);
        $wave = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;
        $url = route('customer.media.index');

        $this->actingAs($owner, 'customer')->get($url)->assertOk()->assertSee('کتابخانه صوتی');
        $item = $this->postJson($url, [
            'name' => 'Welcome',
            'audio' => UploadedFile::fake()->createWithContent('welcome.wav', $wave),
        ])->assertCreated()->assertJsonPath('name', 'Welcome')->json();
        $this->get($item['preview'])->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->assertFileExists(app(AudioAssetService::class)->path($owner->tenant, $item['id']));

        $this->actingAs($other, 'customer')->getJson($url)->assertExactJson([]);
        $this->get($item['preview'])->assertNotFound();
        $this->patchJson($item['preview'], ['action' => 'archive'])->assertNotFound();
    }

    public function test_archived_greeting_can_still_play_and_be_restored_as_part_of_a_published_version(): void
    {
        Storage::fake('ivr');
        $owner = app(CustomerAccountService::class)->createOwner('Owner', '+989120000011', 'Business');
        $extension = SipExtension::factory()->for($owner->tenant)->create(['enabled' => true]);
        $menu = IvrMenu::create(['tenant_id' => $owner->tenant_id, 'name' => 'Main', 'enabled' => true]);
        $assets = collect(['Old greeting', 'New greeting'])->map(function ($name) use ($owner) {
            $id = (string) Str::uuid();
            $key = 'inbound/'.$owner->tenant_id.'/'.$id.'.wav';
            Storage::disk('ivr')->put($key, 'wave');

            return TenantAudioAsset::create(['id' => $id, 'tenant_id' => $owner->tenant_id,
                'storage_key' => $key, 'name' => $name, 'duration_seconds' => 1]);
        });
        $edit = 'https://my.blucom.ir/menus/'.$menu->id;
        $form = [
            'name' => 'Main',
            'intent' => 'publish',
            'choices' => [1 => ['label' => 'Support', 'destination' => 'extension:'.$extension->id]],
            'fallback' => 'extension:'.$extension->id,
        ];

        $this->actingAs($owner, 'customer')->get($edit.'/edit')->assertOk()->assertSee('data-media-choose-greeting', false);
        foreach ($assets as $asset) {
            $this->put($edit, $form + ['greeting_asset_id' => $asset->id])->assertSessionHasNoErrors();
        }
        $this->assertSame($assets[1]->storage_key, $menu->fresh()->published_config['greeting']);
        $this->patch(route('customer.media.update', $assets[0]), ['action' => 'archive'])->assertSessionHasNoErrors();
        $this->getJson(route('customer.media.index'))->assertJsonMissing(['id' => $assets[0]->id]);
        $this->put($edit, $form + ['greeting_asset_id' => $assets[0]->id])->assertSessionHasErrors('greeting_asset_id');
        $this->post($edit.'/restore')->assertSessionHasNoErrors();
        $this->assertSame($assets[0]->storage_key, $menu->fresh()->published_config['greeting']);
        $this->get($edit.'/audio/published')->assertOk()->assertHeader('Content-Type', 'audio/wav');
    }
}
