<?php

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\Device;
use App\Models\FtpAccount;
use App\Models\Site;
use App\Models\User;
use App\Services\DocumentPdfExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceDocumentationReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_omits_secrets_and_pdf_includes_access_passwords_with_requested_layout(): void
    {
        $site = Site::create(['name' => 'Cliente A', 'code' => 'A', 'is_active' => true]);
        $other = Site::create(['name' => 'Cliente B', 'is_active' => true]);
        $device = Device::create([
            'site_id' => $site->id, 'name' => 'Router A',
            'management_ip' => '192.0.2.10', 'vendor' => 'Huawei', 'model' => 'NE8000',
            'device_kind' => 'router', 'device_function' => 'Acesso', 'is_active' => true,
        ]);
        Device::create(['site_id' => $other->id, 'name' => 'Router B', 'management_ip' => '192.0.2.20', 'vendor' => 'Huawei', 'is_active' => true]);
        Device::create(['site_id' => $site->id, 'name' => 'Router C', 'management_ip' => '192.0.2.30', 'vendor' => 'Huawei', 'is_active' => true]);
        $credential = new Credential(['device_id' => $device->id, 'name' => 'SSH principal', 'type' => 'ssh', 'username' => 'backup', 'port' => 4822, 'is_active' => true]);
        $credential->secret = 'never-export-this-secret';
        $credential->save();
        $ftpAccount = new FtpAccount([
            'device_id' => $device->id, 'account_uuid' => (string) Str::uuid(),
            'purpose' => 'backup', 'home_layout' => 'account', 'username' => 'ftp-backup', 'is_active' => true,
        ]);
        $ftpAccount->secret = 'ftp-secret-for-test';
        $ftpAccount->save();
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('reports.documentation', ['site_id' => $site->id]))
            ->assertOk()->assertSee('1 POP com equipamentos · 2 equipamentos no relatório.');

        $csv = $this->get(route('reports.documentation.csv', ['site_id' => $site->id]))
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('Router A', $csv);
        $this->assertStringContainsString('4822', $csv);
        $this->assertStringNotContainsString('Router B', $csv);
        $this->assertStringNotContainsString('never-export-this-secret', $csv);
        $this->assertStringNotContainsString('ftp-secret-for-test', $csv);

        $pdf = $this->get(route('reports.documentation.pdf', ['site_id' => $site->id]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('POPs: 1 | Equipamentos: 2', $pdf);
        $this->assertStringNotContainsString(' | Cont', $pdf);
        $this->assertStringContainsString('Router A', $pdf);
        $this->assertStringContainsString('4822', $pdf);
        $this->assertStringNotContainsString('Router B', $pdf);
        $this->assertStringContainsString('Equipamento: Router A', $pdf);
        $this->assertStringContainsString('Equipamento: Router C', $pdf);
        $this->assertStringContainsString('IP de acesso ou MGNT: 192.0.2.10', $pdf);
        $this->assertStringContainsString('Acesso SSH principal: SSH', $pdf);
        $this->assertStringContainsString('Usu', $pdf);
        $this->assertStringContainsString('Porta SSH: 4822', $pdf);
        $this->assertStringContainsString('Senha: never-export-this-secret', $pdf);
        $this->assertStringContainsString('Acesso FTP de backup: FTP', $pdf);
        $this->assertStringContainsString('Senha: ftp-secret-for-test', $pdf);
        $this->assertStringNotContainsString('Vers', $pdf);
        $this->assertStringNotContainsString('Status:', $pdf);
        $this->assertStringNotContainsString('Pol', $pdf);
        $this->assertStringNotContainsString(' / -', $pdf);
        $this->assertStringNotContainsString('Hostname:', $pdf);
    }

    public function test_pdf_keeps_a_device_and_its_password_on_the_same_page_when_the_block_fits(): void
    {
        $pdf = app(DocumentPdfExporter::class)->render('Documentação', 'Todos os sites', [
            ['heading' => 'Equipamento: primeiro', 'lines' => array_fill(0, 32, 'Detalhe do primeiro equipamento')],
            ['heading' => 'Equipamento: OLT-huawei-IPE', 'lines' => [
                ...array_fill(0, 13, 'Dados da OLT'),
                'Senha: example-only',
            ]],
        ]);

        preg_match_all('/stream\n(.*?)endstream/s', $pdf, $matches);
        $this->assertCount(2, $matches[1]);
        $this->assertStringNotContainsString('OLT-huawei-IPE', $matches[1][0]);
        $this->assertStringContainsString('Equipamento: OLT-huawei-IPE', $matches[1][1]);
        $this->assertStringContainsString('Senha: example-only', $matches[1][1]);
    }
}
