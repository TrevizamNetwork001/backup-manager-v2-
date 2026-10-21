<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class MikrotikFtpBridge
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function run(array $payload): array
    {
        $process = new Process(['/opt/engine-venv/bin/python', '/engine/mikrotik_ftp_integrate.py']);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout(50);

        try {
            $process->run();
            $result = $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;
        } catch (ProcessTimedOutException) {
            return ['success' => false, 'code' => 'SSH_TIMEOUT'];
        } catch (\Throwable) {
            return ['success' => false, 'code' => 'INTEGRATION_FAILED'];
        }

        return is_array($result) && is_bool($result['success'] ?? null)
            ? $result
            : ['success' => false, 'code' => 'INTEGRATION_FAILED'];
    }
}
