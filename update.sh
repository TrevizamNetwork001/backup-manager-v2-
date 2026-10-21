#!/usr/bin/env bash
# Update an installed instance to a locally available, reviewed release tag.
set -euo pipefail
umask 077

PROJECT=/opt/backup-manager-v2
TAG=''
PREFLIGHT=false

usage() {
    echo 'Uso: sudo bash update.sh --tag vMAJOR.MINOR.PATCH [--preflight]' >&2
}

while (($#)); do
    case "$1" in
        --tag)
            if (($# < 2)); then usage; exit 2; fi
            TAG=$2
            shift 2 ;;
        --preflight) PREFLIGHT=true; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage; exit 2 ;;
    esac
done

if [[ ! "$TAG" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
    usage
    exit 2
fi
if ((EUID != 0)); then echo 'Execute como root.' >&2; exit 1; fi
if [[ "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" != "$PROJECT" ]]; then
    echo "O checkout precisa estar em $PROJECT." >&2; exit 1
fi
cd "$PROJECT"
for program in git docker python3; do
    if ! command -v "$program" >/dev/null 2>&1; then echo "Falta $program." >&2; exit 1; fi
done
docker compose version >/dev/null
if [[ ! -f .env || ! -f app/.env ]]; then
    echo 'Instalação incompleta: arquivos .env ausentes.' >&2; exit 1
fi
if [[ -n "$(git status --porcelain --untracked-files=all)" ]]; then
    echo 'Checkout contém alterações locais. Preserve-as antes de atualizar.' >&2; exit 1
fi
if ! git show-ref --verify --quiet "refs/tags/$TAG"; then
    echo "Tag $TAG ausente. Busque e revise a release antes de executar este script." >&2; exit 1
fi
TARGET_SHA=$(git rev-parse "refs/tags/$TAG^{commit}")
CURRENT_SHA=$(git rev-parse HEAD)
TARGET_VERSION=$(git show "$TARGET_SHA:VERSION" 2>/dev/null | tr -d '\r\n')
if [[ "$TARGET_VERSION" != "${TAG#v}" ]]; then
    echo "VERSION da tag $TAG não corresponde à versão solicitada." >&2; exit 1
fi
if [[ "$CURRENT_SHA" == "$TARGET_SHA" ]]; then
    echo "A versão $TAG já está instalada."
    exit 0
fi
if ! git merge-base --is-ancestor "$CURRENT_SHA" "$TARGET_SHA"; then
    echo 'A release não descende do commit instalado. Revise a mudança manualmente.' >&2; exit 1
fi
docker compose config --quiet
if [[ "$PREFLIGHT" == true ]]; then
    echo "Pré-verificação aprovada: $CURRENT_SHA → $TARGET_SHA ($TAG). Nenhuma alteração feita."
    exit 0
fi
if [[ ! -t 0 || ! -t 1 ]]; then
    echo 'Execute a atualização em um terminal interativo.' >&2; exit 1
fi

restore_code() {
    echo "Falha na atualização. Restaurando código e containers do commit $CURRENT_SHA." >&2
    git -c advice.detachedHead=false switch --detach "$CURRENT_SHA" || return 1
    docker compose build || return 1
    docker run --rm -e COMPOSER_ALLOW_SUPERUSER=1 -v "$PROJECT/app:/var/www/html" -w /var/www/html \
        --entrypoint composer backup-manager-v2-app install --no-dev --no-interaction --prefer-dist --optimize-autoloader || return 1
    docker compose up -d || return 1
    docker compose exec -T app php artisan system:recovery-check
}

on_error() {
    local status=$?
    trap - ERR
    if [[ "$SWITCHED" == true ]]; then
        if ! restore_code; then
            echo 'Retorno automático incompleto. Consulte o backup e o estado da instância antes de agir.' >&2
        fi
        if [[ "$MIGRATIONS_STARTED" == true ]]; then
            echo 'Migrations podem ter sido aplicadas. O retorno de código não desfaz o schema; revise-o antes de operar.' >&2
        fi
    fi
    exit "$status"
}

SWITCHED=false
MIGRATIONS_STARTED=false
trap on_error ERR

echo 'Criando backup do banco antes da atualização.'
bash scripts/system-backup.sh
echo 'Confirme a custódia externa da APP_KEY e dos artefatos; o dump não os contém.'
read -r -p 'Para continuar com a instalação da release, digite ATUALIZAR: ' CONFIRMATION
if [[ "$CONFIRMATION" != ATUALIZAR ]]; then
    echo 'Atualização cancelada após o backup.'
    exit 1
fi

SWITCHED=true
git -c advice.detachedHead=false switch --detach "$TARGET_SHA"
docker compose config --quiet
docker compose build
docker run --rm -e COMPOSER_ALLOW_SUPERUSER=1 -v "$PROJECT/app:/var/www/html" -w /var/www/html \
    --entrypoint composer backup-manager-v2-app install --no-dev --no-interaction --prefer-dist --optimize-autoloader
docker compose up -d
MIGRATIONS_STARTED=true
docker compose exec -T app php artisan migrate --force --no-interaction
docker compose exec -T app php artisan system:recovery-check
docker compose exec -T app php artisan engine:health
trap - ERR
echo "Atualização para $TAG concluída ($TARGET_SHA)."
