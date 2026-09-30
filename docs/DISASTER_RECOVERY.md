# Disaster Recovery — Backup Manager V2

Procedimento para recuperar o **próprio** Backup Manager (control plane), não
os backups de equipamentos que ele gerencia. Ver
[docs/CORE_STATUS.md](CORE_STATUS.md) para o estado geral do produto e
`scripts/system-backup.sh` para como gerar o backup usado aqui.

## O requisito crítico: APP_KEY

Toda credencial de equipamento (`credentials.secret`, `ftp_accounts.secret`)
é armazenada **criptografada** com a `APP_KEY` da instância Laravel ativa no
momento em que foi salva. **Sem a APP_KEY exata, essas credenciais são
irrecuperáveis para sempre** — não há como "resetar" ou recuperar por outro
meio; é criptografia simétrica real, não um hash.

Isso significa que uma recuperação mínima do Backup Manager exige, ao mesmo
tempo:

1. **PostgreSQL** (dump ou volume restaurado) — todo o estado do control
   plane (usuários, equipamentos, políticas, execuções, artifacts, auditoria).
2. **A APP_KEY correspondente** — preservada separadamente, nunca dentro do
   mesmo backup do banco (ver `scripts/system-backup.sh` — o script gera um
   *fingerprint* não reversível da chave para conferência, nunca a chave em
   si).
3. **Os artifacts de backup** (`BACKUP_STORAGE_ROOT`, volume `backups`) — os
   arquivos de configuração dos equipamentos em si.
4. **Configuração essencial** (`.env`, `compose.yml`) — para o stack subir
   com os mesmos parâmetros.

Perder qualquer um dos quatro não impede reinstalar o software, mas perder o
APP_KEY especificamente torna as credenciais de equipamento permanentemente
inacessíveis — o equivalente a perder a senha mestra.

**Onde guardar a APP_KEY**: fora do host que roda o Backup Manager, em um
cofre de senhas/segredos com controle de acesso próprio (ex.: Vault,
1Password, Bitwarden, KeePass com backup independente). Nunca no mesmo
volume/backup do PostgreSQL, nunca em texto plano num repositório Git, nunca
enviada por e-mail sem criptografia adicional.

## Gerar o backup do sistema

No host, com Docker Compose, Python 3 e os contêineres `app` e `postgres`
em execução, rode na raiz do repositório:

```bash
bash scripts/system-backup.sh
```

O script grava um dump PostgreSQL e um manifesto com hash SHA-256 em
`database/backups/`. O PHP da aplicação roda no contêiner `app`; o host não
precisa ter PHP instalado. O script não copia a `APP_KEY` nem o volume de
arquivos de backup dos equipamentos. Preserve os quatro itens exigidos para a
recuperação conforme descrito acima.

## Procedimento de recuperação

### 1. Reinstalar a stack

Clonar/copiar o repositório para o novo host, com `docker`/`docker compose`
disponíveis. Não é necessário (nem recomendado) automatizar isso — siga
[docs/UPGRADE.md](UPGRADE.md) para o processo normal de subida do stack.

### 2. Restaurar a APP_KEY correta

Copiar o `.env` restaurado (ou recriar a partir de `.env.example`) e definir
`APP_KEY` com o valor exato preservado separadamente (passo crítico — ver
seção acima). **Nunca gere uma APP_KEY nova** (`php artisan key:generate`)
antes de restaurar o banco — isso tornaria as credenciais existentes
ilegíveis permanentemente.

Se houver dúvida sobre qual APP_KEY é a correta (múltiplas chaves guardadas,
por exemplo), rode
`docker compose exec app php artisan system:recovery-check --json` **depois** de
colocar a chave candidata no `.env` e compare o campo
`checks[].metadata.fingerprint_sha256_16` (para `check == "app_key"`) contra
o fingerprint registrado no manifesto do backup usado (gerado por
`scripts/system-backup.sh`). Fingerprints iguais confirmam a mesma chave —
sem nunca expor nenhuma das duas chaves.

### 3. Restaurar o PostgreSQL

Com o container `postgres` em pé e vazio:

```bash
cat database/backups/system-backup-<timestamp>.dump | docker compose exec -T postgres sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists'
```

Confirme o `sha256` do arquivo `.dump` contra o `sha256` registrado no
`.manifest.json` correspondente antes de restaurar.

### 4. Restaurar o storage de artifacts

Restaurar o volume `backups` (ou o diretório host que ele mapeia) a partir do
backup físico dos artifacts — este script não duplica esses arquivos; eles
precisam da sua própria estratégia de cópia (ex.: rsync/snapshot de volume),
fora do escopo desta fase.

### 5. Subir os serviços

```bash
docker compose up -d
```

### 6. Verificar migrations

```bash
docker compose exec app php artisan migrate:status
```

Não deve haver nada pendente inesperado além do que já era esperado no
momento do backup.

### 7. Rodar o diagnóstico de recuperação

```bash
docker compose exec app php artisan system:recovery-check
```

Deve reportar `HEALTHY` em `app_key`, `database`, `schema`, `storage` e
`config`. Qualquer `CRITICAL` aqui indica que a recuperação ainda não está
completa — não prossiga até resolver.

### 8. Rodar o health do engine

```bash
docker compose exec app php artisan engine:health
```

`database`/`redis` devem ficar `HEALTHY` rapidamente. `engine`/`driver_registry`
podem levar até ~30s (intervalo do snapshot) depois do primeiro restart real
do container `engine` — ver [docs/ENGINE_HEALTH.md](ENGINE_HEALTH.md). Se
usou `docker compose up -d` (não `restart`) isso já está coberto.

### 9. Validar que as credenciais criptografadas decodificam — sem imprimi-las

**Nunca** rode algo que imprima `credentials.secret` em texto plano para
"testar" a restauração. O teste correto e seguro é operacional: tente um
backup manual real contra um equipamento de teste/homologação cuja
credencial já existia antes do desastre. Se a sessão SSH/FTP autentica,
a APP_KEY restaurada é a correta. Se falhar com um erro de decriptação
(exceção do Laravel ao decodificar o campo `encrypted`), a APP_KEY está
errada — pare e não prossiga tentando "corrigir" credenciais one-by-one;
volte ao passo 2.

### 10. Smoke test

Login administrativo, `/system/health`, listagem de equipamentos e políticas,
uma execução manual de backup contra um equipamento de teste. Nenhum destes
passos deve exigir ver a APP_KEY ou qualquer segredo em texto plano.

## O que este documento NÃO cobre

- Restaurar a configuração de um **equipamento individual** a partir de um
  artifact salvo — isso é "restore para equipamento", explicitamente fora de
  escopo desta fase (ver [docs/CORE_STATUS.md](CORE_STATUS.md)).
- Recuperação do próprio storage de artifacts (passo 4) — depende da
  estratégia de armazenamento física escolhida para o volume `backups`, que
  varia por ambiente.
- Rotação de APP_KEY (trocar a chave ativa reencriptando os dados existentes)
  — não implementado; ver dívida técnica registrada em CORE_STATUS.md.
