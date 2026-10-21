# Investigação de segurança — PHP e Nginx — 21/10/2026

## Alcance e fontes

Foram examinados logs HTTP disponíveis, auditoria da aplicação, registros de acesso, arquivos persistentes e configurações do PHP e dos dois níveis de Nginx. O relatório completo e os artefatos foram preservados localmente em uma área privada, ignorada pelo Git. A cópia do relatório completo está em `storage/incident-evidence/2026-10-21/relatorio-investigacao-completo.md`; seu SHA-256 é `efaddbbd3b1a5883b02a4ccd47cf464209567d442f6a9cafe8a151cb48bd84bf`. Esse arquivo adicional não integra o manifesto original dos 38 artefatos.

Fontes técnicas: avisos oficiais do PHP [OOM corruption](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) e [stale global state](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp); [avisos oficiais do Nginx](https://nginx.org/en/security_advisories.html); [rastreador de segurança Debian para CVE-2026-97629](https://security-tracker.debian.org/tracker/CVE-2026-97629); [documentação de sessões TLS do Nginx](https://nginx.org/en/docs/http/ngx_http_ssl_module.html).

## Resultado da análise retrospectiva

- As sondagens a caminhos sensíveis encontradas nos logs disponíveis receberam respostas de acesso negado ou não encontrado. Não houve HTTP 5xx no intervalo mais relevante anterior à atualização do PHP, nem sinais de crash ou persistência nos registros e arquivos examinados.
- Os eventos administrativos destacados na auditoria foram confirmados como legítimos pelo usuário em conversa em 21/10/2026.
- Não foi encontrado indício positivo de comprometimento. Os logs anteriores de PHP-FPM e parte dos logs da aplicação não estavam disponíveis; os logs HTTP antigos não guardavam o corpo das requisições. Por essas lacunas, não é possível excluir uma exploração anterior de forma conclusiva.

## Correções aplicadas

- Os cinco serviços PHP ativos foram atualizados para PHP 8.4.27; o pool web também desativa `session.upload_progress.enabled`.
- O Nginx interno foi atualizado para 1.30.6. Somente `/index.php` é encaminhado ao PHP-FPM; outros caminhos PHP recebem 404, e arquivos ocultos sob `/assets/` são bloqueados.
- Nos vhosts TLS do host, `ssl_session_tickets off;` mitigou a configuração afetada pela CVE-2026-97629. O pacote Debian do Nginx ainda precisa ser atualizado quando houver correção disponível. Os demais avisos recentes avaliados dependem de recursos não presentes na configuração efetiva verificada.
- O proxy do host passou a manter logs JSON separados por vhost, sem gravar corpo, cookies, query string ou tokens. A rotação existente mantém esses logs por 14 dias. Após a investigação, o PHP-FPM passou a gravar acessos e erros em arquivos persistentes com rotação diária de 30 cópias, e o Laravel passou a usar arquivos diários com retenção de 30 dias.

`nginx -t`, HTTPS nas duas portas, rotas públicas e protegidas, `docker compose config --quiet` e `system:recovery-check --json` passaram após as alterações. Os containers ficaram em execução; PostgreSQL e Redis permaneceram saudáveis.

## Acompanhamento

1. Atualizar o pacote Nginx do host quando a correção Debian estiver disponível; manter a mitigação de tickets até validar a atualização.
2. Avaliar uma coleta central para os logs dos demais containers e um identificador de requisição comum entre as camadas.
3. Migrar o acesso SSH para chaves antes de considerar a desativação da autenticação por senha.

**Conclusão:** as falhas publicadas do PHP foram corrigidas nos serviços ativos, o Nginx interno foi atualizado e a condição TLS do proxy do host foi mitigada. A investigação não encontrou indício positivo de invasão, mas a cobertura histórica de logs impede uma exclusão definitiva.
