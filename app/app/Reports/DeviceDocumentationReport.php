<?php

namespace App\Reports;

use App\Models\Device;
use App\Support\OperationalLabels;
use Illuminate\Database\Eloquent\Collection;

class DeviceDocumentationReport
{
    /** @return Collection<int, Device> */
    public function devices(?int $siteId): Collection
    {
        return Device::query()
            ->with(['site', 'credentials', 'deviceBackupPolicies.backupPolicy', 'ftpAccount'])
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->orderBy('site_id')
            ->orderBy('name')
            ->get();
    }

    /** @return \Generator<int, list<string>> */
    public function csvRows(Collection $devices): \Generator
    {
        foreach ($devices as $device) {
            $policies = $device->deviceBackupPolicies
                ->map(fn ($association) => $association->backupPolicy->name.' - '.
                    (OperationalLabels::METHODS[$association->backupPolicy->method] ?? $association->backupPolicy->method).
                    ' ('.($association->is_active ? 'ativa' : 'inativa').')')
                ->implode('; ');
            $accesses = $device->credentials->map(fn ($credential) => [
                $credential->name, strtoupper($credential->type), $credential->username,
                (string) ($credential->port ?? ''), $credential->is_active ? 'Ativo' : 'Inativo',
            ]);
            if ($device->ftpAccount) {
                $accesses->push(['FTP de backup', 'FTP', $device->ftpAccount->username, '21',
                    $device->ftpAccount->is_active ? 'Ativo' : 'Inativo']);
            }
            if ($accesses->isEmpty()) {
                $accesses->push(['', '', '', '', '']);
            }

            foreach ($accesses as $access) {
                yield [
                    $device->site->name, (string) ($device->site->code ?? ''), (string) ($device->site->location ?? ''),
                    $device->name, (string) ($device->hostname ?? ''), $device->management_ip,
                    $device->vendor, (string) ($device->model ?? ''), (string) ($device->os_version ?? ''),
                    (string) ($device->device_kind ?? ''), (string) ($device->device_function ?? ''),
                    $device->is_active ? 'Ativo' : 'Inativo', ...$access, $policies,
                ];
            }
        }
    }

    /** @return list<array{heading: string, lines: list<string>}> */
    public function pdfBlocks(Collection $devices, bool $includeSecrets = false): array
    {
        $blocks = [];
        foreach ($devices as $device) {
            $lines = [
                'Site / POP: '.$device->site->name.($device->site->code ? ' ('.$device->site->code.')' : ''),
                'IP de acesso ou MGNT: '.$device->management_ip,
                'Fabricante / modelo: '.implode(' / ', array_filter([$device->vendor, $device->model])),
            ];
            if ($device->hostname) {
                array_splice($lines, 2, 0, ['Hostname: '.$device->hostname]);
            }
            if ($device->os_version) {
                $lines[] = 'Versão: '.$device->os_version;
            }
            $typeAndFunction = array_filter([$device->device_kind, $device->device_function]);
            if ($typeAndFunction !== []) {
                $lines[] = 'Tipo / função: '.implode(' / ', $typeAndFunction);
            }
            foreach ($device->credentials as $credential) {
                $lines[] = 'Acesso '.$credential->name.': '.strtoupper($credential->type);
                if ($credential->username) {
                    $lines[] = 'Usuário: '.$credential->username;
                }
                if ($credential->port) {
                    $lines[] = 'Porta '.strtoupper($credential->type).': '.$credential->port;
                }
                if ($includeSecrets && $credential->secret) {
                    $lines[] = 'Senha: '.$credential->secret;
                }
            }
            if ($device->ftpAccount) {
                $lines[] = 'Acesso FTP de backup: FTP';
                $lines[] = 'Usuário: '.$device->ftpAccount->username;
                $lines[] = 'Porta FTP: 21';
                if ($includeSecrets && $device->ftpAccount->secret) {
                    $lines[] = 'Senha: '.$device->ftpAccount->secret;
                }
            }
            if ($device->credentials->isEmpty() && ! $device->ftpAccount) {
                $lines[] = 'Acessos cadastrados: nenhum';
            }
            $blocks[] = ['heading' => 'Equipamento: '.$device->name, 'lines' => $lines];
        }

        return $blocks;
    }
}
