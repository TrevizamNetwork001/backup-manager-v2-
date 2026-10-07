<?php

namespace App\Services;

use App\Models\BackupArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cópia externa simples: cada backup validado vai como documento para um chat/tópico do Telegram,
 * usando o mesmo bot das notificações. Fila própria, com novas tentativas; o backup local nunca é
 * alterado. Só considera backups validados depois de o recurso ser ligado.
 */
class TelegramBackupCopy
{
    private const MAX_ATTEMPTS = 5;

    public const ERRORS = [
        'TELEGRAM_UNAUTHORIZED' => 'Token do bot inválido ou revogado.',
        'TELEGRAM_DESTINATION_REJECTED' => 'Destino recusado: revise o Chat ID, o ID do tópico e se o bot pode enviar arquivos no grupo.',
        'TELEGRAM_RATE_LIMITED' => 'Limite de envio do Telegram.',
        'TELEGRAM_FILE_TOO_LARGE' => 'Arquivo maior que o limite de tamanho do Telegram (50 MB no Bot API público).',
        'TELEGRAM_UNREACHABLE' => 'Telegram inacessível a partir do servidor.',
        'TELEGRAM_HTTP_ERROR' => 'Resposta inesperada do Telegram.',
        'ARTIFACT_UNAVAILABLE' => 'Arquivo local ausente ou alterado; não foi enviado.',
    ];

    public function __construct(
        private readonly NotificationSettings $settings,
        private readonly ArtifactStorage $storage,
        private readonly InstanceTimezone $timezone,
    ) {}

    public function ready(): bool
    {
        $s = $this->settings->get();

        return (bool) ($s->backup_copy_enabled && $s->backup_copy_chat_id && $s->bot_token);
    }

    /** @return array{queued:int,sent:int,failed:int} */
    public function run(int $limit = 5): array
    {
        if (! $this->ready()) {
            return ['queued' => 0, 'sent' => 0, 'failed' => 0];
        }

        return ['queued' => $this->enqueueNew()] + $this->deliver($limit);
    }

    public function enqueueNew(): int
    {
        $since = $this->settings->get()->backup_copy_since;
        if (! $since) {
            return 0;
        }
        $artifacts = BackupArtifact::query()->where('status', 'available')->where('validated_at', '>=', $since)
            ->whereNotIn('id', DB::table('telegram_backup_sends')->select('backup_artifact_id'))
            ->orderBy('id')->limit(200)->get(['id', 'size_bytes']);

        foreach ($artifacts as $artifact) {
            DB::table('telegram_backup_sends')->insertOrIgnore([
                'backup_artifact_id' => $artifact->id, 'status' => 'pending', 'next_attempt_at' => now(),
                'bytes' => $artifact->size_bytes, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $artifacts->count();
    }

    /** @return array{sent:int,failed:int} */
    public function deliver(int $limit = 5): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        if (! $this->ready()) {
            return $result;
        }
        $items = DB::table('telegram_backup_sends')->where('status', 'pending')
            ->where('next_attempt_at', '<=', now())->orderBy('id')->limit($limit)->get();

        foreach ($items as $item) {
            $artifact = BackupArtifact::query()->with('device.site')->find($item->backup_artifact_id);
            $check = $artifact ? $this->storage->verify($artifact) : ['result' => 'missing'];
            if (($check['result'] ?? '') !== 'valid') {
                $this->finish($item, 'failed', 'ARTIFACT_UNAVAILABLE');
                $result['failed']++;

                continue;
            }

            [$sendPath, $sendName, $temporary] = $this->prepare($check['path'], $artifact);
            $retryAfter = null;
            try {
                $error = filesize($sendPath) > (int) config('backup.telegram_max_bytes') ? 'TELEGRAM_FILE_TOO_LARGE'
                    : $this->send($sendPath, $sendName, $this->caption($artifact, $temporary), $retryAfter);
            } finally {
                if ($temporary) {
                    @unlink($sendPath);
                }
            }
            $attempts = $item->attempts + 1;
            if ($error === null) {
                DB::table('telegram_backup_sends')->where('id', $item->id)->update([
                    'status' => 'sent', 'attempts' => $attempts, 'sent_at' => now(), 'error_code' => null, 'updated_at' => now(),
                ]);
                $result['sent']++;
            } elseif ($attempts >= self::MAX_ATTEMPTS || in_array($error, ['TELEGRAM_UNAUTHORIZED', 'TELEGRAM_FILE_TOO_LARGE'], true)) {
                DB::table('telegram_backup_sends')->where('id', $item->id)->update([
                    'status' => 'failed', 'attempts' => $attempts, 'error_code' => $error, 'updated_at' => now(),
                ]);
                $result['failed']++;
            } else {
                $delay = max($retryAfter ?? 0, min(3600, 60 * (2 ** ($attempts - 1))));
                DB::table('telegram_backup_sends')->where('id', $item->id)->update([
                    'attempts' => $attempts, 'error_code' => $error, 'next_attempt_at' => now()->addSeconds($delay), 'updated_at' => now(),
                ]);
            }
        }

        return $result;
    }

    /** Envia um arquivo de texto de teste ao destino configurado. @return string|null código de erro, null = ok */
    public function sendTest(): ?string
    {
        $s = $this->settings->get();
        if (! $s->bot_token || ! $s->backup_copy_chat_id) {
            return 'TELEGRAM_DESTINATION_REJECTED';
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "Teste do Backup Manager V2.\nSe este arquivo chegou, a cópia de backups pelo Telegram está funcionando.\n");
        rewind($stream);
        $when = CarbonImmutable::now($this->timezone->get())->format('d/m/Y H:i');
        $retryAfter = null;
        $error = $this->post($stream, 'arquivo-teste-backup-manager.txt', "🧪 Teste da cópia de backups\n🕒 {$when}", $retryAfter);
        fclose($stream);

        return $error;
    }

    /** Falhas definitivas recentes sem nenhum envio bom depois delas. @return array{count:int,error_code:?string}|null */
    public function failing(): ?array
    {
        $failed = DB::table('telegram_backup_sends')->where('status', 'failed')
            ->where('updated_at', '>=', now()->subDay())->orderByDesc('updated_at')->get(['updated_at', 'error_code']);
        if ($failed->isEmpty()) {
            return null;
        }
        $sentAfter = DB::table('telegram_backup_sends')->where('status', 'sent')
            ->where('sent_at', '>', $failed->first()->updated_at)->exists();

        return $sentAfter ? null : ['count' => $failed->count(), 'error_code' => $failed->first()->error_code];
    }

    public function filename(BackupArtifact $artifact): string
    {
        $device = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $artifact->device?->name) ?: 'equipamento';

        return $device.'__'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $artifact->original_filename);
    }

    private const ALREADY_COMPRESSED = ['zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst'];

    /**
     * Arquivos de texto (.rsc, .cfg...) vão dentro de um .zip; o que já é compactado segue como está
     * (zipar de novo só aumenta). Se o zip não puder ser criado, envia o original.
     *
     * @return array{0:string,1:string,2:bool} caminho a enviar, nome, se é temporário
     */
    private function prepare(string $path, BackupArtifact $artifact): array
    {
        $name = $this->filename($artifact);
        $extension = strtolower(pathinfo($artifact->original_filename, PATHINFO_EXTENSION));
        if (in_array($extension, self::ALREADY_COMPRESSED, true) || ! class_exists(\ZipArchive::class)) {
            return [$path, $name, false];
        }
        $temporary = tempnam(sys_get_temp_dir(), 'tgbk');
        if ($temporary === false) {
            return [$path, $name, false];
        }
        $zip = new \ZipArchive;
        $ok = $zip->open($temporary, \ZipArchive::OVERWRITE) === true
            && $zip->addFile($path, $artifact->original_filename)
            && $zip->setCompressionName($artifact->original_filename, \ZipArchive::CM_DEFLATE)
            && $zip->close();
        if (! $ok) {
            @unlink($temporary);

            return [$path, $name, false];
        }

        return [$temporary, $name.'.zip', true];
    }

    public function caption(BackupArtifact $artifact, bool $zipped = false): string
    {
        $when = $this->timezone->format($artifact->validated_at, 'd/m/Y H:i');
        $lines = ['✅ Backup concluído'];
        if ($site = $artifact->device?->site?->name) {
            $lines[] = '📍 POP: '.e($site);
        }
        $lines[] = '🖥️ Equipamento: '.e($artifact->device?->name ?? '—');
        $lines[] = '📄 Arquivo: '.e($artifact->original_filename);
        $lines[] = '📦 Tamanho: '.$this->size((int) $artifact->size_bytes).($zipped ? ' (enviado em ZIP)' : '');
        $lines[] = "🕒 {$when}";

        return implode("\n", $lines);
    }

    private function send(string $path, string $filename, string $caption, ?int &$retryAfter): ?string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return 'ARTIFACT_UNAVAILABLE';
        }
        try {
            return $this->post($stream, $filename, $caption, $retryAfter);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function post($stream, string $filename, string $caption, ?int &$retryAfter): ?string
    {
        $s = $this->settings->get();
        $retryAfter = null;
        $fields = ['chat_id' => $s->backup_copy_chat_id, 'caption' => $caption, 'parse_mode' => 'HTML'];
        if ($s->backup_copy_thread_id) {
            $fields['message_thread_id'] = (string) $s->backup_copy_thread_id;
        }
        try {
            $response = Http::timeout(120)->attach('document', $stream, $filename)
                ->post(rtrim((string) config('backup.telegram_api_base'), '/').'/bot'.$this->settings->token().'/sendDocument', $fields);
        } catch (Throwable) {
            return 'TELEGRAM_UNREACHABLE';
        }
        if ($response->successful()) {
            return null;
        }
        $retryAfter = (int) ($response->json('parameters.retry_after') ?? 0) ?: null;

        return match ($response->status()) {
            401 => 'TELEGRAM_UNAUTHORIZED',
            413 => 'TELEGRAM_FILE_TOO_LARGE',
            400, 403, 404 => 'TELEGRAM_DESTINATION_REJECTED',
            429 => 'TELEGRAM_RATE_LIMITED',
            default => 'TELEGRAM_HTTP_ERROR',
        };
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.').' MB' : number_format(max($bytes, 1) / 1024, 1, ',', '.').' KB';
    }

    private function finish(object $item, string $status, string $code): void
    {
        DB::table('telegram_backup_sends')->where('id', $item->id)->update([
            'status' => $status, 'attempts' => $item->attempts + 1, 'error_code' => $code, 'updated_at' => now(),
        ]);
    }
}
