# Revisão geral e pendências — 21/10/2026

Esta revisão complementa [a investigação de segurança](INVESTIGACAO_SEGURANCA_2026-10-21.md) e [o registro de alterações do dia](CHANGES_2026-10-21.md). O alcance foi a configuração efetiva de sessão, o módulo de usuários, as versões ativas, o estado conhecido do FTP MikroTik e os testes automatizados. Ela não é uma auditoria integral de cada linha do repositório nem substitui uma análise externa de invasão.

## Corrigido nesta rodada

| Área | Problema e correção |
| --- | --- |
| Sessões de usuários | Desativar uma conta ou redefinir sua senha não encerrava as sessões existentes. Agora essas operações apagam as sessões no banco e revogam o token de lembrar acesso. A redefinição também remove tokens antigos de recuperação de senha. |
| Usuários, ações | Os textos Editar, Ativar/Desativar e Excluir foram trocados por botões de ícone com nome acessível e dica ao passar o cursor. Editar usa um lápis sobre uma folha; o componente contém uma única definição de cada ícone. |
| Usuários, cadastro | O cadastro tem um único campo de senha e exibe, durante a digitação, os requisitos de 8 caracteres, letra, número e caractere especial. O servidor exige os mesmos requisitos. A redefinição administrativa mantém mínimo de 10 caracteres e também exige letra, número e caractere especial. |
| Usuários, textos | “Papel” foi substituído por “Perfil de acesso” nas telas de criação, listagem e edição. |
| Usuários, alinhamento | Os campos Perfil de acesso e Senha no modal de cadastro passaram a alinhar rótulo e controle pelo topo; a lista de requisitos da senha pode crescer sem esticar o campo ao lado. |
| Perfis de acesso | O cadastro e a edição passam a oferecer somente Administrador e Operador. Operador reúne as funções operacionais e a consulta de auditoria. A migração converte os perfis antigos para Operador, registra a alteração e exige novo login dessas contas. |
| Instalação | O `install.sh` já inclui as imagens atuais, migrações, acesso por IP em HTTPS e criação da conta inicial. A mensagem final do `--resume` foi corrigida para não anunciar a senha padrão depois que houver usuários configurados. A instalação completa em host limpo ainda não foi executada nesta instância. |
| Testes de equipamentos | Três expectativas antigas foram atualizadas para os nomes de políticas efetivamente mostrados na lista de equipamentos. |

## Proteções confirmadas

- Configuração efetiva: `APP_DEBUG=false`, sessões em banco, cookie de sessão com `Secure=true`, `HttpOnly=true` e `SameSite=lax`. O cookie XSRF é legível pelo navegador para o envio do token CSRF, como previsto no fluxo da aplicação. Resposta HTTPS de `/login` confirmou os atributos de cookie e os cabeçalhos `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options` e CSP de `frame-ancestors`.
- O proxy do host usa TLS 1.2/1.3 e `ssl_session_tickets off`; o Nginx interno está em 1.30.6 e o PHP ativo em 8.4.27, conforme a investigação anterior. O host continua com o pacote Nginx Debian `1.26.3-3+deb13u9`.
- `composer audit --locked` não encontrou avisos de vulnerabilidade para as dependências PHP travadas em `composer.lock` na data da consulta. Isso não prova ausência de falhas desconhecidas.
- O MK-POP-VARAN tem conta FTP provisionada e integração concluída após a correção do incidente `invalid_account`; o arquivo de teste posterior gerou execução bem-sucedida e artefato válido. O recibo antigo em quarentena foi preservado como evidência. O agendamento diário está configurado às 23:00 no MikroTik.

## Verificação

- A migração de perfis foi aplicada à instância: 2 contas Administrador, 2 Operador e nenhuma conta nos perfis antigos. Antes da migração havia 1 conta Auditor e nenhuma Somente leitura ou Operador. A conta convertida teve sessões e token de lembrar acesso revogados.
- Laravel: após a unificação, 521 testes aprovados, 1 ignorado e 3446 asserções. Os 22 testes de gestão de usuários passaram com 104 asserções, incluindo a senha mínima válida e quatro formatos inválidos. Pint passou nos arquivos PHP alterados. O aviso de cache de resultados do PHPUnit decorre do sistema de arquivos de leitura do contêiner e não afetou o resultado dos testes.
- Engine Python: 131 testes aprovados.
- FTP Python: 21 testes de `test_admin.py` aprovados. O teste de integração adicional não concluiu porque o servidor de teste não conseguiu reservar portas de dados (`425 All reserved TCP ports are busy`); este resultado não indica falha confirmada do receptor em produção.
- `git diff --check` passou; o cache de views foi limpo depois da alteração do ícone.

## Pendências e limites

1. **Nginx do host:** acompanhar a atualização do pacote Debian aplicável à CVE-2026-97629. A mitigação `ssl_session_tickets off` permanece ativa. Verificar os [avisos oficiais do Nginx](https://nginx.org/en/security_advisories.html) e o [rastreador Debian](https://security-tracker.debian.org/tracker/CVE-2026-97629) antes de instalar.
2. **Backup periódico:** conferir o primeiro envio das 23:00 de MK-BORDA-01 e MK-POP-VARAN; os testes manuais bem-sucedidos não provam o disparo automático futuro.
3. **Operação e recuperação:** concluir a coleta central dos logs dos demais contêineres, o uso de um ID de requisição entre as camadas, a guarda externa da chave e dos backups de recuperação e um exercício de restauração. Os logs históricos incompletos impedem excluir exploração anterior de forma definitiva.
4. **FTP:** o protocolo de envio dos equipamentos continua sem TLS; limitar origens no firewall e credenciais individuais continua necessário até uma alternativa compatível ser homologada. A validação da ZTE por FTP permanece pendente.
5. **HSTS:** o proxy deixa esse cabeçalho desativado de forma deliberada enquanto o domínio compartilhado e o acesso inicial por IP não tiverem desenho HTTPS definitivo. Decidir isso no projeto de implantação, sem ativar HSTS no domínio compartilhado por engano.
6. **Dependências JavaScript:** não há lockfile npm/yarn/pnpm versionado neste checkout; portanto, esta revisão não afirma auditoria reproduzível do conjunto JS instalado.

Referência de boas práticas de sessão: [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).
