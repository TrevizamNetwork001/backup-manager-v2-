# Upgrade — Backup Manager V2

Procedimento para atualizar uma instância já em produção para um commit mais
recente. Não existe (nem está planejado) um auto-updater — este é um processo
manual, deliberadamente simples.

## Antes de atualizar

1. **Backup do banco**: `bash scripts/system-backup.sh` (gera dump +
   manifesto com checksum em `database/backups/`, ver
   [docs/DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)). O host precisa de
   Docker Compose e Python 3; PHP só é necessário dentro do contêiner `app`.
2. **Preservar a APP_KEY**: confirme que o valor atual de `APP_KEY` no `.env`
   está salvo em um lugar seguro e separado (cofre de segredos) — nunca gerar
   uma nova chave neste processo.
3. **Conferir `.env` contra `.env.example`**: novas variáveis introduzidas
   por uma fase (ex.: `BACKUP_ENGINE_HEALTH_SNAPSHOT_PATH`,
   `BACKUP_ENGINE_MAX_ATTEMPTS`, `HEALTH_*`) têm defaults seguros no código,
   mas revise o diff de `.env.example` entre a versão atual e a nova para
   decidir se algum precisa de um valor específico neste ambiente.
4. **Confirmar `APP_ENV=production` e `APP_DEBUG=false`** no `.env` real —
   isso não é reafirmado automaticamente pelo processo de upgrade.

## Procedimento

```bash
# 1. Backup (ver acima)
bash scripts/system-backup.sh

# 2. Atualizar o código (pull/checkout do commit desejado)
git pull   # ou o equivalente do seu fluxo de deploy

# 3. Reconstruir as imagens SE o Dockerfile mudou (composer.json/requirements.txt novos, etc.)
#    — se só código de app/engine mudou (bind mount), não é necessário.
docker compose build

# 4. Recriar os containers cuja configuração (compose.yml) mudou —
#    "restart" NÃO relê compose.yml, apenas reinicia com a config antiga.
#    "up -d" recria o que precisar e deixa o resto intocado.
docker compose up -d

# 5. Aplicar migrations pendentes
docker compose exec app php artisan migrate:status   # revisar antes
docker compose exec app php artisan migrate --force

# 6. Verificar saúde
docker compose exec app php artisan system:recovery-check
docker compose exec app php artisan engine:health
```

## Por que `up -d`, não `restart`

Lição registrada durante o STABILIZATION-1: `docker compose restart <serviço>`
reinicia o container com a configuração (env vars, volumes) **com a qual ele
foi criado**, ignorando mudanças feitas depois no `compose.yml`. Uma fase que
adiciona um volume ou variável de ambiente novos (como o ENGINE-3 fez com
`engine-health`/`BACKUP_ENGINE_HEALTH_SNAPSHOT_PATH`) só entra em vigor com
`docker compose up -d`, que recria o container quando sua config mudou.
`restart` continua correto para "só preciso que o processo releia o código
em disco" (bind mounts), mas não para mudanças de `compose.yml`.

## Rollback

Se o upgrade falhar de forma irrecuperável rapidamente:

1. `docker compose down` (não remove volumes).
2. `git checkout <commit-anterior>`.
3. Se uma migration nova já foi aplicada e precisa ser desfeita:
   `docker compose exec app php artisan migrate:rollback --step=1 --force`
   (revise `down()` da migration específica antes — nem toda migration desta
   fase precisa ser revertida se o rollback é só de código).
4. `docker compose up -d`.
5. Se a migration já tiver corrompido dados de forma não reversível por
   `down()`, restaurar o dump gerado no passo 1 do upgrade
   (ver [docs/DISASTER_RECOVERY.md](DISASTER_RECOVERY.md), passo 3).

Nunca restaurar um dump de banco mais novo que o código que vai rodar sobre
ele (schema à frente do código causa erros de coluna/tabela inexistente do
ponto de vista do código antigo).

## O que este processo garante e o que não garante

Garante: nenhuma migration aplicada sem revisão prévia do `migrate:status`,
backup verificável antes de qualquer mudança de schema, verificação de saúde
pós-upgrade antes de considerar concluído.

Não garante (fora de escopo desta fase): zero-downtime, upgrade automático
multi-nó, rollback automático de schema com perda de dados zero garantida se
a nova migration já rodou em produção com dados reais incompatíveis com o
schema antigo.
