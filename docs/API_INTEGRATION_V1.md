# API de integração v1

**Estado em 21/10/2026:** o código foi enviado ao GitHub no commit `389c657`, mas a API permanece desativada na instância (`INTEGRATION_API_ENABLED=false`, configuração padrão). A migração da tabela de tokens ainda está pendente, nenhum token foi emitido e os endpoints não foram liberados para uso. A Provider Ops Suite não foi alterada.

## Acesso e operação

- Base: `/api/v1`. Todas as rotas aceitam apenas `GET` e respondem JSON. O login web e os jobs continuam independentes.
- Envie `Authorization: Bearer <token>`. Cookies de sessão e token na URL não autenticam. O valor é gerado aleatoriamente, mostrado uma vez e guardado no banco apenas como SHA-256. O único escopo aceito é `read`; tokens expirados ou revogados são recusados.
- A API exige HTTPS e um IP de origem presente em `INTEGRATION_API_ALLOWED_IPS` (IPv4/IPv6 ou CIDR), configurado em `app/.env`. A lista vazia nega todas as origens. A identificação do IP depende da configuração correta de proxies confiáveis; aplique a mesma restrição no proxy/firewall de borda.
- `INTEGRATION_API_ENABLED=false` é o padrão e responde 404. A ativação não existe no painel: é feita em `app/.env`, após migração, configuração da lista de IPs e emissão de um token de leitura. O comando de emissão é `php artisan integration:token-issue "Provider Ops Suite" --days=90`; guarde a saída uma única vez em cofre. Revogue pelo ID com `php artisan integration:token-revoke <id>`. Esses comandos não foram executados na instância.
- O limite é 30 requisições por minuto por IP e 120 por minuto por hash do token apresentado. A aplicação audita leituras atendidas e recusas de autenticação, HTTPS, IP e escopo sem gravar o valor do token, cabeçalhos, parâmetros da URL ou conteúdo retornado. Bloqueios pelo limite de requisições ocorrem antes desse registro e devem ser observados no log de acesso do proxy.

## Contratos

Datas de execução e de saúde usam ISO 8601 com fuso. Campos de data podem ser `null`. Listas seguem `data`, `links` e `meta` da paginação Laravel; `page` começa em 1, `per_page` aceita 1 a 100 e vale 25 por padrão. Filtros inválidos respondem 422. Campos não listados aqui não integram o contrato v1.

| Rota | Resposta `data` | Filtros |
| --- | --- | --- |
| `GET /api/v1/health` | `status`, `checked_at` | Nenhum. `status` é o estado geral do serviço de saúde existente; detalhes internos dos checks não são expostos. |
| `GET /api/v1/summary` | `sites`, `devices`, `active_device_health` (`healthy`, `warning`, `critical`, `unknown`), `backups_today` (`succeeded`, `failed`, `timed_out`, `pending_or_running`), `generated_at` | Nenhum. O dia segue o fuso configurado na instância. |
| `GET /api/v1/devices` | Itens com `id`, `name`, `site_id`, `vendor`, `model`, `backup_status`, `backup_status_reason`, `policy_names`, `last_backup_at`, `last_success_at` | `site_id`, `vendor`, `status`, `page`, `per_page`. Lista apenas equipamentos ativos, usando a classificação do relatório existente. |
| `GET /api/v1/backups/recent` | Itens com `id`, `device` (`id`, `name`, `site_id`), `policy` (`id`, `name`, `method`), `status`, `origin`, `error_code`, `artifact_size_bytes`, `created_at`, `finished_at` | `period`, `site_id`, `device_id`, `page`, `per_page`. Apenas execuções `succeeded`, ordenadas pelo ID mais recente. |
| `GET /api/v1/failures` | Mesmo formato de item das execuções | Mesmos filtros. Apenas estados terminais `failed` e `timed_out`, ordenados pelo ID mais recente. |

`period` aceita `today`, `7d`, `30d` ou `90d`; omitido, não há corte por data. A API nunca inclui senha, usuário de credencial, token, mensagem de erro livre, caminho/arquivo armazenado, hash do arquivo ou conteúdo do backup. A projeção é explícita nos recursos JSON.

## Erros e auditoria

Respostas de autenticação/autorização usam `{"error":{"code":"...","message":"..."}}`: 401 `unauthorized`; 403 `https_required`, `ip_not_allowed` ou `forbidden`; 404 quando a API está desligada; 429 ao exceder o limite. Respostas da API usam `Cache-Control: private, no-store` quando passam pelo middleware de auditoria. Os eventos `api.integration.read`, `api.integration.token.issued` e `api.integration.token.revoked` ficam em `audit_events`; a auditoria guarda apenas ID/nome do token, rota, status e IP.

## Ativação futura

O envio do código ao GitHub não ativa a API. Após autorização específica para abrir a integração na instância:

1. Confirmar HTTPS, o IP real recebido pela aplicação atrás do proxy e o IP de origem da Provider Ops Suite; limitar o acesso também no proxy/firewall.
2. Aplicar a migração pendente da tabela `integration_tokens` e verificar o resultado.
3. Definir `INTEGRATION_API_ALLOWED_IPS` e `INTEGRATION_API_ENABLED=true` em `app/.env`; atualizar o cache de configuração da aplicação, se estiver em uso.
4. Emitir um token `read`, armazená-lo no cofre da integração e testar autenticação, paginação, limites e revogação sem expor o valor em logs ou URL.

Os 12 testes de integração, autorização e relatórios passaram com 233 verificações antes do commit `389c657`. A migração de produção, a emissão de token e a ativação continuam pendentes.
