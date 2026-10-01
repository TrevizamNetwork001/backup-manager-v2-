<?php

namespace App\Services;

use App\Models\Device;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class SshCredentialProbe
{
    public function test(Device $device, string $username, string $secret, int $port): array
    {
        $process = new Process(['/opt/engine-venv/bin/python', '/engine/probe_ssh.py']);
        $process->setInput(json_encode([
            'device_id' => $device->id,
            'host' => $device->management_ip,
            'vendor' => $device->vendor,
            'platform' => $device->platform ?? 'network',
            'port' => $port,
            'username' => $username,
            'secret' => $secret,
            'ssh_host_key_algorithm' => $device->ssh_host_key_algorithm,
            'ssh_host_key_fingerprint' => $device->ssh_host_key_fingerprint,
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(70);

        try {
            $process->run();
            $result = $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;
        } catch (ProcessTimedOutException) {
            $result = ['success' => false, 'code' => 'SSH_TIMEOUT'];
        } catch (\Throwable) {
            $result = ['success' => false, 'code' => 'ENGINE_FAILED'];
        }

        if (! is_array($result) || ! is_bool($result['success'] ?? null)) {
            $result = ['success' => false, 'code' => 'ENGINE_FAILED'];
        }

        $observed = $result['observed'] ?? [];
        if (is_array($observed) && is_string($observed['algorithm'] ?? null) && is_string($observed['fingerprint'] ?? null) &&
            preg_match('/\A[a-zA-Z0-9@._+-]{1,100}\z/D', $observed['algorithm'] ?? '') &&
            preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $observed['fingerprint'] ?? '')) {
            Device::query()->whereKey($device->id)->where('management_ip', $device->management_ip)->update([
                'ssh_observed_algorithm' => $observed['algorithm'],
                'ssh_observed_fingerprint' => $observed['fingerprint'],
                'ssh_observed_at' => now(),
            ]);
        }

        return [
            'success' => $result['success'],
            'code' => $result['success'] ? 'ok' : $result['code'] ?? 'ENGINE_FAILED',
            'latency_ms' => $result['latency_ms'] ?? null,
        ];
    }
}
