# RELEASE-1.1 — checklist final de produção

Data: 28/09/2026. HEAD inicial: `2add72364cddad73d9429d52171992af2e0d8a0d`.
**NOT READY para `v2.0.0`: correções preparadas e homologadas, ativação real e
custódia externa ainda sem confirmação.** Esta seção substitui os gates do
RELEASE-1; não confundir código pronto com deployment aprovado.

Base: [CORE_STATUS.md](CORE_STATUS.md), [PERFORMANCE_BASELINE.md](PERFORMANCE_BASELINE.md),
[DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) e [UPGRADE.md](UPGRADE.md).
Sem feature nova, tuning, tag ou push.

## Blockers e estado atual

| Blocker | Classificação | Evidência / próxima ação |
| --- | --- | --- |
| Migration/índice | **PENDENTE MANUAL** | Única pendente: `2026_09_27_000001_add_missing_index_to_backup_executions_ftp_account_id`; esperada, up/down passaram em SQLite e PostgreSQL isolado; comando limitado por --path preparado abaixo |
| PHP-FPM/storage | **PENDENTE MANUAL** (correção preparada) | Pool candidato UID 65534/grupo 33 passou na sintaxe e leitura/escrita controlada; app real ainda usa UID 33 até recriação autorizada |
| Backend 8081 | **PENDENTE MANUAL** (correção preparada) | Quem publica é o container nginx; Compose agora usa `127.0.0.1:8081:80`; binding real ainda 0.0.0.0/:: até recriação autorizada |
| Redirect V1 | **PENDENTE MANUAL** | Candidato versionado transfere somente porta 80 para URL V2 :8443, preservando V1 :443; URL final e instalação/reload aguardam confirmação |
| Headers/cookies | **RESOLVIDO em código/testes; PENDENTE MANUAL na ativação** | Proxy restrito por subnet, headers normalizados, cookies explicitamente Secure/HttpOnly/Lax e proxy com nosniff/referrer/frame policy/CSP compatível; falta aplicar Compose e proxy do host |
| Backup pré-release | **PENDENTE MANUAL** | Script existente preservado; custom dump sintético, manifesto/hash/tamanho e pg_restore --list passaram; backup real exige autorização |
| APP_KEY/config/artifacts/FTP externos | **PENDENTE EXTERNO** | Chave presente, recovery-check HEALTHY, fingerprint abaixo; operador precisa confirmar cofre/cópias independentes; nada foi copiado automaticamente |

## READY — correções e testes preparados

- PHP-FPM usa `docker/php/storage-pool.conf`: user=nobody (65534),
  group=www-data (33). Mesma identidade dona dos artifacts, grupo do runtime
  Laravel (logs/views/sessões/cache existentes também conferidos como writable).
  Mantém diretórios 0700 e arquivos 0600 existentes e novos, sem
  chmod 777, ACL global ou chown/chmod recursivo de dados reais. App continua
  com código e FTP montados RO, storage/cache Laravel RW e backups RW para
  as ações autorizadas; engine/scheduler não mudam de identidade. Compartilhar
  o UID entre app/engine é intencional: app precisa verificar/download e
  excluir artifacts autorizados; engine precisa publicá-los. Containers e
  mounts continuam sendo a barreira de acesso ao FTP/código.
- Probe no storage real, mas **somente fixtures exclusivas** `.release-1-1-UUID`:
  UID 65534:33 escreveu/leu, engine escreveu/leu, runtime Laravel acessível;
  diretório e arquivos temporários removidos. Nenhum artifact registrado
  foi lido, alterado ou removido nesse probe. Pool real ainda não ativado.
- Backend 8081 limitado ao loopback IPv4 no Compose; proxy do host já usa
  `127.0.0.1:8081`. Nenhuma publicação IPv6 prevista. Postgres/Redis continuam
  sem portas publicadas; sem mudança de firewall ou certificado.
- `TRUSTED_PROXIES` deixa de ser `*`. Compose usa
  `BACKUP_TRUSTED_PROXIES`, default `172.18.0.0/16`, conferido por inspeção
  Docker. Bootstrap admite esse subnet e loopback como fallback; configurar
  a variável se a rede Docker mudar. Confia somente For/Proto/Port,
  não em X-Forwarded-Host ou Forwarded. Referência:
  [Laravel — trusted proxies](https://laravel.com/docs/13.x/requests#configuring-trusted-proxies).
- Proxy candidato `docker/nginx/host-v2.conf` usa Host/HTTPS/port 8443
  canônicos, sobrescreve X-Forwarded-For com o IP observado e remove headers
  Forwarded/X-Forwarded-Host enviados pelo cliente. TLS existente preservado.
- Compose define SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true,
  SESSION_SAME_SITE=lax. Login/logout em HTTPS :8443, sessão segura e CSRF
  válido/missing foram testados no kernel Laravel com bases sintéticas.
- Proxy adiciona X-Content-Type-Options=nosniff,
  Referrer-Policy=strict-origin-when-cross-origin, X-Frame-Options=SAMEORIGIN
  e CSP `frame-ancestors 'self'`. Essa CSP não restringe scripts/estilos
  existentes. HSTS **não habilitado**: é política do host inteiro, não de
  :8443. Enquanto :443 servir V1, upgrade automático do navegador para :443
  pode contornar o redirect V2; a política fica diferida para o cutover do domínio.
- `nginx -t` do candidato com V1 :443 preservado passou. Proxy real isolado
  em loopback 58080/58443 com backend sintético passou: redirect preserva
  path/query; HTTPS/TLS verificado, headers corretos e spoofing descartado.
  Ambos os processos sintéticos foram encerrados; nginx real não recarregado.

## Comandos reais — somente após confirmação explícita

Executar da raiz `/opt/backup-manager-v2`. Não usar um `migrate --force`
sem --path; não executar down/rollback no PostgreSQL real nesta fase.
Nenhum comando mutável desta seção foi executado sem confirmação.

### 1. Custódia e backup, antes de migration/recriação

Confirmar APP_KEY exata em cofre fora do host e cópias protegidas/off-host de
config, artifacts, ftp-data e ftp-db. O dump não inclui esses volumes ou a
chave. Não gerar outra chave nem copiá-la junto do dump.

```bash
umask 077
bash scripts/system-backup.sh
```

O diretório `database/backups/` continua ignorado pelo Git. O script gera
`.dump` custom e `.manifest.json`, SHA-256, tamanho, commit e fingerprint.
A validação sintética também confirmou novos arquivos em modo 0600 sob
umask 077. Dumps antigos 0644/0664 não foram modificados ou removidos.
Após gerar o novo par, validar **esse par específico**, não um dump antigo:

```bash
python3 - <<'PY_VERIFY'
from pathlib import Path
import hashlib, json, subprocess
root = Path('database/backups')
manifest = max(root.glob('system-backup-*.manifest.json'), key=lambda p: p.stat().st_mtime_ns)
record = json.loads(manifest.read_text())
name = record['dump_file']
assert Path(name).name == name and name.startswith('system-backup-') and name.endswith('.dump')
dump = root / name
with dump.open('rb') as stream:
    assert stream.read(5) == b'PGDMP'
assert dump.stat().st_size == record['size_bytes'] > 0
with dump.open('rb') as stream:
    assert hashlib.file_digest(stream, 'sha256').hexdigest() == record['sha256']
assert record['app_key_fingerprint_sha256_16'] == '8f5f636f698c8ac6'
with dump.open('rb') as stream:
    subprocess.run(['docker', 'compose', 'exec', '-T', 'postgres', 'pg_restore', '--list'], stdin=stream, stdout=subprocess.DEVNULL, check=True)
print('Backup custom, manifesto/checksum/tamanho e fingerprint conferidos.')
PY_VERIFY
```

Se a chave/fingerprint mudar, parar e investigar; não editar o manifesto para
aceitar uma chave diferente. Não se executa restore para verificar o dump.

### 2. Somente a migration esperada

```bash
docker compose exec -T app php artisan migrate --database=pgsql --path=database/migrations/2026_09_27_000001_add_missing_index_to_backup_executions_ftp_account_id.php --force --no-interaction
docker compose exec -T app php artisan migrate:status --no-interaction
```

`up()` cria `backup_executions_ftp_account_id_index`; `down()` remove apenas
esse índice. Não muda rows/schema de negócio. CREATE INDEX normal pode
bloquear escritas; escolher janela e revisar atividade antes da autorização.
Reversibilidade e preservação de execução queued foram testadas em ambos os
bancos; --path rollback/up também passaram em PostgreSQL isolado.

### 3. Ativar pool, cookies e binding de backend

```bash
docker compose config --quiet
docker compose up -d --no-deps app nginx
```

Recria somente containers que precisarem da configuração nova; possível
interrupção curta de HTTP. Não reinicia engine/scheduler/FTP/DB/Redis.
Não usar restart como substituto: ele não atualiza env, mounts ou portas.
Sem config cache observado nesta instância; se aparecer cache antes da
aplicação, revisar atualização controlada antes de prosseguir.
Verificar pool por `/proc`/conf, cookies efetivos e probe temporário com o
**usuário real** PHP-FPM, além de download com fixture sintética. Inspecionar
binding 127.0.0.1 e ausência de [::]/0.0.0.0; não alterar firewall.

### 4. Instalar proxy candidato, somente após confirmar URL V2 :8443

Os caminhos reais dos sites são `/etc/nginx/sites-available/backup-manager-v2.conf`
e `/etc/nginx/sites-available/backup-manager-local.conf`, com symlinks já
presentes em sites-enabled. Fazer snapshot protegido dos dois arquivos antes
de editá-los. Instalar `docker/nginx/host-v2.conf` no site V2; remover **somente**
o primeiro bloco de porta 80 do site V1, cujo redirect hoje é:
`return 301 https://backup.trevizamnetwork.com.br$request_uri;`.
O servidor V1 porta 443 deve permanecer byte-for-byte. Não manter dois blocos
com mesmo server_name/porta 80; candidato testado já considera essa remoção.

```bash
sudo python3 - <<'PY_HOST'
from pathlib import Path
import datetime, os, shutil
os.umask(0o077)
v1 = Path('/etc/nginx/sites-available/backup-manager-local.conf')
v2 = Path('/etc/nginx/sites-available/backup-manager-v2.conf')
old = '''server {
    listen 80;
    listen [::]:80;
    server_name backup.trevizamnetwork.com.br;

    return 301 https://backup.trevizamnetwork.com.br$request_uri;
}

'''
text = v1.read_text()
assert text.startswith(old), 'Config V1 mudou: parar e revisar, sem escrever.'
candidate = Path('docker/nginx/host-v2.conf')
assert candidate.is_file()
snapshot = Path('/etc/nginx') / ('release-1-1-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%d%H%M%S'))
snapshot.mkdir(mode=0o700)
for source in [v1, v2]:
    shutil.copyfile(source, snapshot / source.name)
    (snapshot / source.name).chmod(0o600)
shutil.copyfile(candidate, v2)
v1.write_text(text[len(old):])
print('Snapshot:', snapshot)
PY_HOST
sudo nginx -t
sudo systemctl reload nginx
```

Se nginx -t falhar, restaurar os dois snapshots e não recarregar. Validar
HTTP→HTTPS :8443 em uma etapa, login/logout, path/query, URLs/form actions,
Secure/HttpOnly/Lax, headers e ausência de loop. :443 continua servindo V1.
Não emitir/reconfigurar certificados. Se a URL oficial passar a ser :443,
revisar candidato e novos testes antes de qualquer aplicação.

## APP_KEY / health / recheck

Fingerprint atual não reversível: **`8f5f636f698c8ac6`**. Valor da APP_KEY não
impresso/copiado. `system:recovery-check` HEALTHY sob CLI root confirma formato,
DB/schema/storage/config, mas não comprova custódia externa ou acesso do pool
real. Dados reais nunca foram alterados para forçar health HEALTHY.

Recheck atual (antes da ativação): migration ainda pendente;
engine:health/diagnose **UNKNOWN**, 13 HEALTHY e 2 UNKNOWN (worker ocioso,
retention nunca executada); zero WARNING/CRITICAL nativos. Retention continua
desabilitada, thresholds/timeouts/performance inalterados. A leitura de
storage por root não resolve o gate de ativação do pool.
Após operações autorizadas, repetir migrate:status, engine:health,
engine:diagnose, recovery-check, storage/pool, downloads, smoke HTTP/RBAC,
FTP/receipts, reports/CSV e audit. UNKNOWN legítimo permanece UNKNOWN.

## Testes finais

| Verificação | Resultado |
| --- | --- |
| Laravel SQLite completo | 364 testes, 2.218 assertions, passou |
| Laravel PostgreSQL isolado completo | 364 testes, 2.225 assertions, passou |
| Python completo | 103 testes, passou |
| ftp-admin completo | 16 testes, passou, sem skips |
| PureDB integração | Login/chroot/upload sintético/limite/rotação/desativação passaram |
| Migration up/down | SQLite e PostgreSQL; índice e dados conferidos; --path CLI testado no PG isolado |
| php -l / py_compile | 151 PHP / 35 Python, passou |
| Pint / git diff --check | PHP alterado formatado; diff sem erros |
| Secret scan | Zero matches com secrets reais do .env; único marcador de chave privada é fixture truncada conhecida |
| Backup sintético | Custom dump/manifesto/checksum/tamanho/0600 e pg_restore --list passaram |
| Proxy / FPM candidatos | Sintaxe e testes isolados/controlados passaram |

Laboratório `/tmp/bm-release-1-1`, PostgreSQL exclusivo com socket Unix/porta
lógica 55434, sem TCP; data_directory conferido antes de migrations/dump.
Bancos, APP_KEY, caches, views e storage das suítes são sintéticos. PHP CLI
8.4.23 versus produção 8.4.25; Paramiko host 3.5.1 versus engine 4.0.0,
mesmas limitações de equivalência do RELEASE-1. Nenhum hardware usado.
Evidência local: logs/JUnit, migration-up-down.log, backup-harness,
proxy-test/result.json, fpm-test.log e secret-scan.json nesse laboratório.

## WARNINGS / PÓS-RELEASE / V2.1

Mantidos warnings de worker ocioso/retention nunca executada, FTP sem TLS,
faixa passiva de firewall maior que perfil V2, seis containers sem healthcheck
Docker e commit desconhecido em diagnose. Não são convertidos em HEALTHY.
Após release: monitorar capacidade/freshness/receipts e ensaiar DR autorizado
em destino independente. UI restante e dívida P2 ficam depois; V2.1 mantém
escopo futuro do CORE_STATUS, sem novos recursos/tuning nesta fase.

## Git / integridade / decisão

Stage nominal, sem add . / add -A. Alterações preexistentes preservadas e
fora dos commits: AGENTS, trecho MikroTik de CORE_STATUS, DR/UPGRADE/UI,
script de backup, três imagens removidas e IDEIAS_FUTURAS não rastreado.
Corrigir código/config não limpa automaticamente esse worktree.
Código/config/testes: `2ea1451` — `fix: remove bloqueios finais de producao`.
Documentação em commit separado `docs: atualiza checklist final de release`.
**READY somente após ativação técnica comprovada, backup real validado e
confirmação da custódia externa obrigatória. Sem isso: NOT READY, sem tag.**
Confirmado até aqui: nenhum equipamento real alterado; nenhum backup/artifact
real removido; nenhum segredo exposto; nenhuma migration/recriação/reload/
firewall/certificado/restore real executado sem autorização; nenhum push.
