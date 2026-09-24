<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function insertEvent(array $overrides = []): int
    {
        $metadata = $overrides['metadata'] ?? ['foo' => 'bar'];
        unset($overrides['metadata']);

        return DB::table('audit_events')->insertGetId(array_merge([
            'actor_user_id' => null,
            'action' => 'ftp.account.delete',
            'resource_type' => 'ftp_account',
            'resource_id' => '1',
            'resource_label' => 'olt_teste',
            'result' => 'success',
            'ip_address' => '45.10.20.30',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ], $overrides));
    }

    // AUTORIZAÇÃO

    public function test_admin_can_access_audit_index(): void
    {
        $this->admin();
        $this->insertEvent();

        $this->get(route('audit.index'))->assertOk()->assertSeeText('Auditoria');
    }

    public function test_non_admin_is_blocked_from_audit_index(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get(route('audit.index'))->assertForbidden();
    }

    public function test_non_admin_is_blocked_from_audit_show(): void
    {
        $id = $this->insertEvent();
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get(route('audit.show', $id))->assertForbidden();
    }

    // LISTAGEM

    public function test_events_are_listed_in_descending_order(): void
    {
        $this->admin();
        $older = $this->insertEvent(['action' => 'ftp.account.create', 'created_at' => now()->subDay()]);
        $newer = $this->insertEvent(['action' => 'ftp.account.delete', 'created_at' => now()]);

        $response = $this->get(route('audit.index'));
        $response->assertOk();
        $ids = $response->viewData('events')->pluck('id')->all();
        $this->assertSame([$newer, $older], $ids);
    }

    public function test_index_paginates_at_fifty_per_page(): void
    {
        $this->admin();
        for ($i = 0; $i < 55; $i++) {
            $this->insertEvent(['resource_id' => (string) $i]);
        }

        $response = $this->get(route('audit.index'));
        $response->assertOk();
        $events = $response->viewData('events');
        $this->assertSame(50, $events->count());
        $this->assertSame(55, $events->total());
    }

    public function test_null_actor_is_presented_as_system(): void
    {
        $this->admin();
        $this->insertEvent(['actor_user_id' => null]);

        $this->get(route('audit.index'))->assertOk()->assertSeeText('Sistema');
    }

    // FILTROS

    public function test_filters_by_actor_user(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['is_admin' => true]);
        $mine = $this->insertEvent(['actor_user_id' => $admin->id, 'resource_id' => 'mine']);
        $this->insertEvent(['actor_user_id' => $other->id, 'resource_id' => 'other']);

        $response = $this->get(route('audit.index', ['actor' => $admin->id]));
        $ids = $response->viewData('events')->pluck('id')->all();
        $this->assertSame([$mine], $ids);
    }

    public function test_filters_by_system_actor(): void
    {
        $this->admin();
        $system = $this->insertEvent(['actor_user_id' => null, 'resource_id' => 'system']);
        $this->insertEvent(['actor_user_id' => User::factory()->create()->id, 'resource_id' => 'human']);

        $response = $this->get(route('audit.index', ['actor' => 'system']));
        $ids = $response->viewData('events')->pluck('id')->all();
        $this->assertSame([$system], $ids);
    }

    public function test_filters_by_action(): void
    {
        $this->admin();
        $match = $this->insertEvent(['action' => 'ftp.account.password_rotated']);
        $this->insertEvent(['action' => 'ftp.account.delete']);

        $response = $this->get(route('audit.index', ['action' => 'ftp.account.password_rotated']));
        $ids = $response->viewData('events')->pluck('id')->all();
        $this->assertSame([$match], $ids);
    }

    public function test_filters_by_resource_type(): void
    {
        $this->admin();
        $match = $this->insertEvent(['resource_type' => 'backup_execution', 'action' => 'backup.content_analyzed']);
        $this->insertEvent(['resource_type' => 'ftp_account']);

        $response = $this->get(route('audit.index', ['resource_type' => 'backup_execution']));
        $ids = $response->viewData('events')->pluck('id')->all();
        $this->assertSame([$match], $ids);
    }

    public function test_filters_by_result(): void
    {
        $this->admin();
        $match = $this->insertEvent(['result' => 'failed']);
        $this->insertEvent(['result' => 'success']);

        $response = $this->get(route('audit.index', ['result' => 'failed']));
        $ids = $response->viewData('events')->pluck('id')->all();
        $this->assertSame([$match], $ids);
    }

    public function test_filters_by_custom_date_range(): void
    {
        $this->admin();
        $inside = $this->insertEvent(['created_at' => Carbon::parse('2026-01-15 12:00:00')]);
        $this->insertEvent(['created_at' => Carbon::parse('2026-03-01 12:00:00')]);

        $response = $this->get(route('audit.index', [
            'period' => 'custom', 'date_from' => '2026-01-01', 'date_to' => '2026-01-31',
        ]));
        $ids = $response->viewData('events')->pluck('id')->all();
        $this->assertSame([$inside], $ids);
    }

    public function test_filters_by_search_term_on_resource_label(): void
    {
        $this->admin();
        $match = $this->insertEvent(['resource_label' => 'olt_teste']);
        $this->insertEvent(['resource_label' => 'outra_conta']);

        $response = $this->get(route('audit.index', ['q' => 'olt_teste']));
        $ids = $response->viewData('events')->pluck('id')->all();
        $this->assertSame([$match], $ids);
    }

    // DETALHE

    public function test_show_renders_event_with_labels(): void
    {
        $this->admin();
        $id = $this->insertEvent([
            'action' => 'ftp.account.delete_with_data',
            'resource_type' => 'ftp_account',
            'resource_label' => 'olt_teste',
            'result' => 'success',
            'metadata' => ['device_name' => 'OLT-huawei-IPE', 'mode' => 'ftp_data', 'files_removed' => 8, 'bytes_removed' => 5_900_000, 'preserved' => true],
        ]);

        $response = $this->get(route('audit.show', $id));
        $response->assertOk();
        $response->assertSeeText('Excluir conta FTP + dados');
        $response->assertSeeText('ftp.account.delete_with_data');
        $response->assertSeeText('OLT-huawei-IPE');
        $response->assertSeeText('Conta + dados FTP');
        $response->assertSeeText('Sim');
    }

    // SEGREDOS

    public function test_password_is_redacted_in_metadata(): void
    {
        $this->admin();
        $id = $this->insertEvent(['metadata' => ['password' => 'SuperSecret123!', 'username' => 'olt_teste']]);

        $response = $this->get(route('audit.show', $id));
        $response->assertOk();
        $response->assertDontSee('SuperSecret123!');
        $response->assertSeeText('[REDACTED]');
        $response->assertSeeText('olt_teste');
    }

    public function test_token_is_redacted_in_metadata(): void
    {
        $this->admin();
        $id = $this->insertEvent(['metadata' => ['api_token' => 'abc123secretvalue']]);

        $response = $this->get(route('audit.show', $id));
        $response->assertDontSee('abc123secretvalue');
    }

    public function test_nested_secret_is_redacted(): void
    {
        $this->admin();
        $id = $this->insertEvent(['metadata' => ['report' => ['credentials' => ['ssh_password' => 'HiddenPass1!'], 'status' => 'ok']]]);

        $response = $this->get(route('audit.show', $id));
        $response->assertDontSee('HiddenPass1!');
        $response->assertSeeText('ok');
    }

    public function test_authorization_header_is_redacted(): void
    {
        $this->admin();
        $id = $this->insertEvent(['metadata' => ['authorization' => 'Bearer abc.def.ghi']]);

        $response = $this->get(route('audit.show', $id));
        $response->assertDontSee('Bearer abc.def.ghi');
    }

    public function test_normal_fields_remain_visible(): void
    {
        $this->admin();
        $id = $this->insertEvent(['metadata' => ['device_id' => 42, 'purpose' => 'backup']]);

        $response = $this->get(route('audit.show', $id));
        $response->assertSeeText('42');
        $response->assertSeeText('backup');
    }

    // ROBUSTEZ

    public function test_unknown_action_is_shown_safely(): void
    {
        $this->admin();
        $id = $this->insertEvent(['action' => 'some.unknown.action']);

        $this->get(route('audit.show', $id))->assertOk()->assertSeeText('some.unknown.action');
    }

    public function test_unknown_result_is_shown_safely(): void
    {
        $this->admin();
        $id = $this->insertEvent(['result' => 'strange_result']);

        $this->get(route('audit.show', $id))->assertOk()->assertSeeText('strange_result');
    }

    public function test_unknown_resource_type_is_shown_safely(): void
    {
        $this->admin();
        $id = $this->insertEvent(['resource_type' => 'something_new']);

        $this->get(route('audit.show', $id))->assertOk()->assertSeeText('something_new');
    }

    public function test_empty_metadata_does_not_break_the_page(): void
    {
        $this->admin();
        $id = $this->insertEvent(['metadata' => []]);

        $this->get(route('audit.show', $id))->assertOk()->assertSeeText('Nenhum detalhe adicional.');
    }

    public function test_deeply_nested_metadata_renders_without_error(): void
    {
        $this->admin();
        $id = $this->insertEvent(['metadata' => [
            'report' => [
                'status' => 'ok',
                'blockers' => [['code' => 'x', 'detail' => 'y'], ['code' => 'z', 'detail' => 'w']],
                'nested' => ['level2' => ['level3' => 'deep_value']],
            ],
        ]]);

        $this->get(route('audit.show', $id))->assertOk()->assertSeeText('deep_value');
    }

    public function test_removed_user_is_shown_as_system(): void
    {
        $this->admin();
        $ghost = User::factory()->create();
        $id = $this->insertEvent(['actor_user_id' => $ghost->id]);
        $ghost->delete();

        $this->get(route('audit.show', $id))->assertOk()->assertSeeText('Sistema');
    }

    // ITEM 14 — operações FTP administrativas devem emitir audit_events globais

    public function test_ftp_account_lifecycle_emits_global_audit_events(): void
    {
        $admin = $this->admin();
        $site = \App\Models\Site::firstOrCreate(['name' => 'Lab'], ['is_active' => true]);
        $device = \App\Models\Device::create(['site_id' => $site->id, 'name' => 'OLT-audit', 'management_ip' => '192.0.2.50',
            'vendor' => 'Huawei', 'platform' => 'olt', 'is_active' => true]);

        $this->post(route('ftp.store'), ['device_id' => $device->id, 'username' => 'oltaudit',
            'password' => 'ValidPassword123!', 'password_confirmation' => 'ValidPassword123!'])->assertOk();
        $account = $device->ftpAccount()->firstOrFail();
        $this->assertDatabaseHas('audit_events', [
            'action' => 'ftp.account.create', 'resource_type' => 'ftp_account', 'resource_id' => (string) $account->id,
        ]);

        $this->post(route('ftp.rotate', $account), ['password' => 'AnotherPass123!', 'password_confirmation' => 'AnotherPass123!'])->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'ftp.account.password_rotated', 'resource_id' => (string) $account->id]);

        $this->patch(route('ftp.status', $account), ['is_active' => false]);
        $this->assertDatabaseHas('audit_events', ['action' => 'ftp.account.disable', 'resource_id' => (string) $account->id]);

        $events = DB::table('audit_events')->where('resource_id', (string) $account->id)->get();
        $this->assertStringNotContainsString('AnotherPass123!', json_encode($events));
        $this->assertStringNotContainsString('ValidPassword123!', json_encode($events));
    }
}
