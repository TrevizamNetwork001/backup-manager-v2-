<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_devices(): void
    {
        $this->get('/devices')
            ->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_devices(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/devices')
            ->assertOk()
            ->assertSee('Equipamentos');
    }

    public function test_authenticated_user_can_create_device(): void
    {
        $user = User::factory()->create();

        $site = Site::create([
            'name' => 'POP Principal',
            'code' => 'POP-01',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->post('/devices', [
                'site_id' => $site->id,
                'name' => 'CCR Borda',
                'hostname' => 'mk-borda-01',
                'management_ip' => '192.0.2.10',
                'vendor' => 'MikroTik',
                'model' => 'CCR2004',
                'os_version' => 'RouterOS 7',
                'notes' => 'Equipamento de teste',
                'is_active' => '1',
            ]);

        $response->assertRedirect(route('devices.index'));

        $this->assertDatabaseHas('devices', [
            'site_id' => $site->id,
            'name' => 'CCR Borda',
            'management_ip' => '192.0.2.10',
            'vendor' => 'MikroTik',
            'is_active' => true,
        ]);
    }

    public function test_management_ip_must_be_unique(): void
    {
        $user = User::factory()->create();

        $site = Site::create([
            'name' => 'POP Principal',
            'is_active' => true,
        ]);

        Device::create([
            'site_id' => $site->id,
            'name' => 'Device 1',
            'management_ip' => '192.0.2.10',
            'vendor' => 'MikroTik',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/devices/create')
            ->post('/devices', [
                'site_id' => $site->id,
                'name' => 'Device 2',
                'management_ip' => '192.0.2.10',
                'vendor' => 'Huawei',
                'is_active' => '1',
            ]);

        $response
            ->assertRedirect('/devices/create')
            ->assertSessionHasErrors('management_ip');
    }

    public function test_device_requires_valid_ip(): void
    {
        $user = User::factory()->create();

        $site = Site::create([
            'name' => 'POP Principal',
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/devices/create')
            ->post('/devices', [
                'site_id' => $site->id,
                'name' => 'Device inválido',
                'management_ip' => '999.999.999.999',
                'vendor' => 'Generic',
                'is_active' => '1',
            ]);

        $response
            ->assertRedirect('/devices/create')
            ->assertSessionHasErrors('management_ip');
    }

    public function test_authenticated_user_can_update_device(): void
    {
        $user = User::factory()->create();

        $site = Site::create([
            'name' => 'POP Principal',
            'is_active' => true,
        ]);

        $device = Device::create([
            'site_id' => $site->id,
            'name' => 'Device Antigo',
            'management_ip' => '192.0.2.10',
            'vendor' => 'MikroTik',
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->put("/devices/{$device->id}", [
                'site_id' => $site->id,
                'name' => 'Device Atualizado',
                'management_ip' => '192.0.2.11',
                'vendor' => 'Huawei',
                'model' => 'NE40',
                'is_active' => '0',
            ])
            ->assertRedirect(route('devices.index'));

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'name' => 'Device Atualizado',
            'management_ip' => '192.0.2.11',
            'vendor' => 'Huawei',
            'is_active' => false,
        ]);
    }

    public function test_authenticated_user_can_delete_device(): void
    {
        // Destroy is admin-only (devices.delete) since ADMIN-3.
        $user = User::factory()->admin()->create();

        $site = Site::create([
            'name' => 'POP Principal',
            'is_active' => true,
        ]);

        $device = Device::create([
            'site_id' => $site->id,
            'name' => 'Device Temporário',
            'management_ip' => '192.0.2.20',
            'vendor' => 'Generic',
            'is_active' => true,
        ]);

        $this
            ->actingAs($user)
            ->delete("/devices/{$device->id}")
            ->assertRedirect(route('devices.index'));

        $this->assertDatabaseMissing('devices', [
            'id' => $device->id,
        ]);
    }

    public function test_ip_change_discards_observation_and_empty_trust_is_rejected(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $device = Device::create(['site_id' => $site->id, 'name' => 'MK', 'management_ip' => '192.0.2.10',
            'vendor' => 'MikroTik', 'is_active' => true]);
        $device->ssh_observed_algorithm = 'ssh-rsa';
        $device->ssh_observed_fingerprint = 'SHA256:'.str_repeat('A', 43);
        $device->ssh_observed_at = now();
        $device->save();
        $this->actingAs(User::factory()->create())->put(route('devices.update', $device), [
            'site_id' => $site->id, 'name' => 'MK', 'management_ip' => '192.0.2.11',
            'vendor' => 'MikroTik', 'is_active' => '1',
        ])->assertRedirect(route('devices.index'));
        $this->assertNull($device->fresh()->ssh_observed_fingerprint);
        $this->post(route('devices.ssh-host-key.trust', $device), ['ssh_host_key_fingerprint' => 'SHA256:'.str_repeat('B', 43)])
            ->assertSessionHasErrors('ssh_host_key');
        $this->assertNull($device->fresh()->ssh_host_key_fingerprint);
    }

    private function html(string $content): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$content, LIBXML_NONET);

        return new \DOMXPath($document);
    }

    public function test_vendor_select_includes_supported_catalog_and_keeps_model_and_type_fields(): void
    {
        $this->actingAs(User::factory()->operator()->create());
        $expected = ['A10 Networks', 'C-DATA', 'Cisco', 'Datacom', 'FiberHome', 'Hillstone', 'Huawei', 'Intelbras',
            'Juniper', 'MikroTik', 'Parks', 'Ubiquiti', 'VSOL', 'ZTE'];
        foreach (['devices.create', 'devices.index'] as $route) {
            $html = $this->html($this->get(route($route))->assertOk()->getContent());
            $options = $html->query('//select[@name="vendor"][@required]/option');
            $values = [];
            foreach ($options as $option) {
                $values[] = $option->getAttribute('value');
            }
            $this->assertSame(['', ...$expected], $values);
            $this->assertSame(0, $html->query('//input[@name="vendor"]')->length);
            $this->assertSame(1, $html->query('//input[@name="model"][@type="text"]')->length);
            $this->assertSame(5, $html->query('//select[@name="device_kind"]/option')->length);
            $this->assertSame('Firewall', $html->evaluate('string(//select[@name="device_kind"]/option[@value="firewall"])'));
            $this->assertSame(1, $html->query('//input[@name="device_function"][@type="text"][not(@list)]')->length);
        }
    }

    public function test_creation_normalizes_catalog_vendors_and_rejects_arbitrary_values(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $this->actingAs(User::factory()->operator()->create());
        $data = ['site_id' => $site->id, 'name' => 'Router', 'management_ip' => '192.0.2.10',
            'model' => 'Modelo livre sem catálogo', 'is_active' => 1];
        $cases = array_merge(Device::VENDORS, ['HUAWEI', 'huawei', 'Mikrotik', 'mikrotik', ' MiKroTik ', ' a10 networks ', 'HILLSTONE']);
        foreach ($cases as $index => $vendor) {
            $ip = '192.0.2.'.(10 + $index);
            $this->post(route('devices.store'), array_replace($data, ['vendor' => $vendor, 'management_ip' => $ip]))
                ->assertRedirect(route('devices.index'))->assertSessionHasNoErrors();
            $this->assertDatabaseHas('devices', ['management_ip' => $ip, 'vendor' => Device::normalizeVendor($vendor),
                'model' => $data['model']]);
        }
        foreach (['Acme Legacy', '', ['Huawei']] as $vendor) {
            $this->post(route('devices.store'), array_replace($data, ['vendor' => $vendor, 'management_ip' => '192.0.2.100']))
                ->assertSessionHasErrors('vendor');
            $this->assertDatabaseMissing('devices', ['management_ip' => '192.0.2.100']);
        }
    }

    public function test_known_legacy_vendor_is_selected_and_normalized_only_when_saved(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $this->actingAs(User::factory()->operator()->create());
        $device = Device::create(['site_id' => $site->id, 'name' => 'Router', 'management_ip' => '192.0.2.10',
            'vendor' => ' hUaWeI ', 'platform' => 'olt', 'is_active' => true]);
        $html = $this->html($this->get(route('devices.edit', $device))->assertOk()->getContent());
        $this->assertSame('Huawei', $html->evaluate('string(//select[@name="vendor"]/option[@selected]/@value)'));
        $this->assertSame(15, $html->query('//select[@name="vendor"]/option')->length);
        $this->assertSame(' hUaWeI ', $device->fresh()->vendor);
        $this->put(route('devices.update', $device), ['site_id' => $site->id, 'name' => 'Router atualizado',
            'management_ip' => $device->management_ip, 'vendor' => 'HUAWEI', 'platform' => 'olt', 'is_active' => 1])
            ->assertRedirect(route('devices.index'))->assertSessionHasNoErrors();
        $this->assertSame('Huawei', $device->fresh()->vendor);
        $this->assertSame('olt', $device->fresh()->platform);
    }

    public function test_unknown_legacy_vendor_can_be_kept_or_replaced_but_not_reused_for_creation(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $this->actingAs(User::factory()->operator()->create());
        $device = Device::create(['site_id' => $site->id, 'name' => 'Legacy', 'management_ip' => '192.0.2.10',
            'vendor' => 'Acme Legacy', 'is_active' => true]);
        $data = ['site_id' => $site->id, 'name' => 'Legacy atualizado', 'management_ip' => $device->management_ip,
            'vendor' => 'Acme Legacy', 'model' => 'Modelo legado', 'is_active' => 1];
        $html = $this->html($this->get(route('devices.edit', $device))->assertOk()->getContent());
        $this->assertSame('Acme Legacy', $html->evaluate('string(//select[@name="vendor"]/option[@selected]/@value)'));
        $this->assertSame('Acme Legacy (legado)', $html->evaluate('string(//select[@name="vendor"]/option[@selected])'));
        $this->put(route('devices.update', $device), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Acme Legacy', $device->fresh()->vendor);
        $this->assertSame('Modelo legado', $device->fresh()->model);
        $this->put(route('devices.update', $device), array_replace($data, ['vendor' => 'Outra marca']))->assertSessionHasErrors('vendor');
        $this->assertSame('Acme Legacy', $device->fresh()->vendor);
        $this->post(route('devices.store'), array_replace($data, ['management_ip' => '192.0.2.11']))->assertSessionHasErrors('vendor');

        $this->from(route('devices.edit', $device))->put(route('devices.update', $device), array_replace($data, ['name' => '']))
            ->assertRedirect(route('devices.edit', $device))->assertSessionHasErrors('name');
        $retry = $this->html($this->get(route('devices.edit', $device))->assertOk()->getContent());
        $this->assertSame('Acme Legacy', $retry->evaluate('string(//select[@name="vendor"]/option[@selected]/@value)'));

        $this->put(route('devices.update', $device), array_replace($data, ['vendor' => ' mikrotik ']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('MikroTik', $device->fresh()->vendor);
        $this->put(route('devices.update', $device), $data)->assertSessionHasErrors('vendor');
    }

    public function test_vendor_listing_and_search_preserve_existing_records_without_leaking_legacy_options(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $vendors = ['HUAWEI', 'huawei', 'Mikrotik', 'MikroTik', 'Acme Legacy'];
        foreach ($vendors as $index => $vendor) {
            Device::create(['site_id' => $site->id, 'name' => 'Router '.$index,
                'management_ip' => '192.0.2.'.(10 + $index), 'vendor' => $vendor, 'is_active' => true]);
        }
        $this->actingAs(User::factory()->operator()->create());
        $html = $this->html($this->get(route('devices.index'))->assertOk()->getContent());
        foreach ($vendors as $index => $vendor) {
            $row = '//tr[@data-list-row="devices-rows"][contains(@data-search, "router '.$index.' ")]';
            $this->assertSame(Device::normalizeVendor($vendor), $html->evaluate('string('.$row.'/td[@data-label="Fabricante / Modelo"]//span[@class="entity-cell__title"])'));
            $this->assertStringContainsString(mb_strtolower($vendor), $html->evaluate('string('.$row.'/@data-search)'));
            $this->assertSame($vendor, Device::where('name', 'Router '.$index)->value('vendor'));
        }
        $this->assertSame(0, $html->query('//dialog//select[@name="vendor"]/option[@value="Acme Legacy"]')->length);
    }

    public function test_create_modal_does_not_inherit_the_last_listed_device(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        Device::create(['site_id' => $site->id, 'name' => 'Existing router', 'hostname' => 'existing-host',
            'management_ip' => '192.0.2.10', 'vendor' => 'MikroTik', 'is_active' => true]);
        $this->actingAs(User::factory()->operator()->create());
        $response = $this->get(route('devices.index'))->assertOk();
        $html = $this->html($response->getContent());
        $form = '//dialog[@id="device-create-dialog"]//form';
        $this->assertSame('POST', $html->evaluate('string('.$form.'/@method)'));
        $this->assertSame(route('devices.store'), $html->evaluate('string('.$form.'/@action)'));
        $this->assertSame(0, $html->query($form.'//input[@name="_method"]')->length);
        foreach (['name', 'management_ip'] as $field) {
            $this->assertSame('', $html->evaluate('string('.$form.'//input[@name="'.$field.'"]/@value)'));
        }
        $this->assertSame(1, $html->query($form.'//input[@name="name"][@required]')->length);
        $this->assertSame(0, $html->query($form.'//input[@name="hostname"]')->length);
        $this->assertSame('', $html->evaluate('string('.$form.'//select[@name="device_kind"]/option[@selected]/@value)'));
        $this->assertSame(1, $html->query($form.'//select[@name="device_kind"]/option[@selected]')->length);
        $this->assertSame('Cadastrar equipamento', trim($html->evaluate('string('.$form.'//button[@type="submit"])')));
    }

    public function test_device_kind_and_function_are_saved_and_edit_uses_the_create_modal_layout(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $this->actingAs(User::factory()->operator()->create());
        $data = ['site_id' => $site->id, 'name' => 'NE8000 M8', 'management_ip' => '192.0.2.10',
            'vendor' => 'Huawei', 'model' => 'NE8000 M8', 'device_kind' => 'router',
            'device_function' => 'BGP', 'is_active' => 1];

        $this->post(route('devices.store'), $data)->assertRedirect(route('devices.index'))->assertSessionHasNoErrors();
        $device = Device::where('name', 'NE8000 M8')->firstOrFail();
        $this->assertSame('network', $device->platform);
        $this->assertSame('router', $device->device_kind);
        $this->assertSame('BGP', $device->device_function);

        $html = $this->html($this->get(route('devices.edit', $device))->assertOk()->getContent());
        $form = '//dialog[@id="device-edit-dialog"]//form';
        $this->assertSame(1, $html->query($form.'/div[contains(@class, "form-create-modal__body")]')->length);
        $this->assertSame('PUT', $html->evaluate('string('.$form.'//input[@name="_method"]/@value)'));
        $this->assertSame('router', $html->evaluate('string('.$form.'//select[@name="device_kind"]/option[@selected]/@value)'));
        $this->assertSame('BGP', $html->evaluate('string('.$form.'//input[@name="device_function"]/@value)'));
        $this->assertSame(0, $html->query($form.'//input[@name="hostname"]')->length);
        $this->assertSame(route('devices.index'), $html->evaluate('string('.$form.'//a[@data-close-device-edit][normalize-space()="Cancelar"]/@href)'));
        $this->assertSame(route('devices.index'), $html->evaluate('string(//dialog[@id="device-edit-dialog"]//a[@aria-label="Fechar"]/@href)'));
        $this->assertSame(0, $html->query($form.'//button[@data-show-device-settings]')->length);

        $this->put(route('devices.update', $device), array_replace($data, ['name' => 'NE8000 M4', 'device_function' => 'BNG']))
            ->assertRedirect(route('devices.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', ['id' => $device->id, 'name' => 'NE8000 M4',
            'device_kind' => 'router', 'device_function' => 'BNG', 'platform' => 'network']);

        $this->post(route('devices.store'), array_replace($data, ['management_ip' => '192.0.2.11',
            'device_kind' => 'olt', 'device_function' => 'Acesso']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', ['management_ip' => '192.0.2.11', 'device_kind' => 'olt',
            'device_function' => 'Acesso', 'platform' => 'olt']);
        $this->post(route('devices.store'), array_replace($data, ['management_ip' => '192.0.2.13',
            'device_kind' => 'firewall', 'device_function' => 'Firewall']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', ['management_ip' => '192.0.2.13', 'device_kind' => 'firewall',
            'platform' => 'network']);
        $this->post(route('devices.store'), array_replace($data, ['management_ip' => '192.0.2.12',
            'device_kind' => 'invalid']))->assertSessionHasErrors('device_kind');
    }

    public function test_equipment_actions_keep_only_compatible_ftp_in_a_small_modal(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $huawei = Device::create(['site_id' => $site->id, 'name' => 'Huawei Switch',
            'management_ip' => '192.0.2.20', 'vendor' => 'Huawei', 'platform' => 'network', 'is_active' => true]);
        $vsol = Device::create(['site_id' => $site->id, 'name' => 'VSOL V1600GT',
            'management_ip' => '192.0.2.21', 'vendor' => 'VSOL', 'model' => 'V1600GT', 'platform' => 'olt', 'is_active' => true]);
        $vsol->ssh_observed_algorithm = 'ssh-rsa';
        $vsol->ssh_observed_fingerprint = 'SHA256:'.str_repeat('A', 43);
        $vsol->save();
        $this->actingAs(User::factory()->operator()->create());

        $html = $this->html($this->get(route('devices.index'))->assertOk()->getContent());
        $this->assertSame(1, $html->query('//button[@data-open-device-access="'.$huawei->id.'"]')->length);
        $this->assertSame(1, $html->query('//dialog[@id="device-access-dialog-'.$huawei->id.'"][contains(@class, "form-create-modal")]')->length);
        $this->assertSame(1, $html->query('//dialog[@id="device-access-dialog-'.$huawei->id.'"]//div[contains(@class, "form-create-modal__body")]')->length);
        $this->assertSame(0, $html->query('//button[@data-open-device-access="'.$vsol->id.'"]')->length);
        $ftp = $this->html($this->get(route('devices.edit', [$huawei, 'olt_wizard' => 1]))->assertOk()->getContent());
        $this->assertSame(1, $ftp->query('//dialog[@id="olt-wizard"][contains(@class, "device-ftp-wizard")]')->length);
        $this->assertSame(1, $ftp->query('//dialog[@id="olt-wizard"]//button[@id="close-olt-wizard"][contains(@class, "modal__close")]')->length);
        $this->assertSame(route('devices.edit', [$huawei, 'olt_wizard' => 1]),
            $html->evaluate('string(//dialog[@id="device-access-dialog-'.$huawei->id.'"]//a[contains(., "Abrir assistente FTP")]/@href)'));
        $this->assertSame(0, $html->query('//dialog[@id="device-access-dialog-'.$vsol->id.'"]')->length);
    }

    public function test_optional_hostname_is_preserved_when_the_equipment_is_renamed(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $this->actingAs(User::factory()->operator()->create());
        $data = ['site_id' => $site->id, 'name' => 'Router A', 'hostname' => 'Router A',
            'management_ip' => '192.0.2.10', 'vendor' => 'MikroTik', 'is_active' => 1];
        $this->post(route('devices.store'), $data)->assertRedirect(route('devices.index'));
        $device = Device::firstOrFail();
        $this->assertSame('Router A', $device->hostname);
        $edit = $this->html($this->get(route('devices.edit', $device))->assertOk()->getContent());
        $this->assertSame(0, $edit->query('//input[@name="hostname"]')->length);

        $update = $data;
        unset($update['hostname']);
        $this->put(route('devices.update', $device), array_replace($update, ['name' => 'Router B']))->assertRedirect();
        $this->assertSame('Router A', $device->fresh()->hostname);
        $this->get(route('devices.index'))->assertOk()->assertSee('Router A · 192.0.2.10');

        $this->post(route('devices.store'), array_replace($data, ['name' => 'Router C', 'hostname' => '', 'management_ip' => '192.0.2.11']))->assertRedirect();
        $this->assertNull(Device::where('name', 'Router C')->firstOrFail()->hostname);
        $this->post(route('devices.store'), array_replace($data, ['name' => ' ', 'management_ip' => '192.0.2.12']))->assertSessionHasErrors('name');
    }

    public function test_legacy_devices_show_only_a_distinct_hostname_and_keep_search_metadata(): void
    {
        $site = Site::create(['name' => 'POP', 'is_active' => true]);
        $cases = [
            ['name' => 'Router A', 'hostname' => 'Router A', 'secondary' => '192.0.2.10'],
            ['name' => 'Router B', 'hostname' => '  router b  ', 'secondary' => '192.0.2.11'],
            ['name' => 'Router C', 'hostname' => null, 'secondary' => '192.0.2.12'],
            ['name' => 'Router D', 'hostname' => 'mk-d.example', 'secondary' => 'mk-d.example · 192.0.2.13'],
        ];
        foreach ($cases as $index => $case) {
            Device::create(['site_id' => $site->id, 'name' => $case['name'], 'hostname' => $case['hostname'],
                'management_ip' => '192.0.2.'.(10 + $index), 'vendor' => 'MikroTik', 'is_active' => true]);
        }
        $this->actingAs(User::factory()->viewer()->create());
        $html = $this->html($this->get(route('devices.index'))->assertOk()->getContent());
        foreach ($cases as $case) {
            $cell = '//tr[@data-list-row="devices-rows"]/td[@data-label="Equipamento"][.//span[@class="entity-cell__title" and text()="'.$case['name'].'"]]';
            $this->assertSame(1, $html->query($cell)->length);
            $this->assertSame($case['secondary'], trim($html->evaluate('string('.$cell.'//span[@class="entity-cell__meta tech-value"])')));
            $search = $html->evaluate('string('.$cell.'/../@data-search)');
            $this->assertStringContainsString(mb_strtolower($case['name']), $search);
            $this->assertStringContainsString(Device::where('name', $case['name'])->value('management_ip'), $search);
            if ($case['hostname']) {
                $this->assertStringContainsString(mb_strtolower(trim($case['hostname'])), $search);
            }
            $this->assertSame($case['hostname'], Device::where('name', $case['name'])->value('hostname'));
        }
        $this->assertSame('devices-rows', $html->evaluate('string(//input[@data-list-search]/@data-list-search)'));
    }
}
