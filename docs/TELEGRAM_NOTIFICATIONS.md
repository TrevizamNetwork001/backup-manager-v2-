# Notificações Telegram (V2)

Portado do V1 (`backup_manager/notifications.py`, `docs/NOTIFICATIONS.md` do
Backup Manager Local) em 2026-10-07. Escopo entregue: **alertas + normalização**.
Fora do escopo por decisão do operador: resumos diário/semanal/executivo,
múltiplos destinos, aviso de backup concluído, cópia de arquivos pelo Telegram.

## Arquitetura

```text
EngineHealth::report()['alerts'] ┐
DeviceBackupHealth::rows()       ┴─> NotificationManager::evaluate()
                                        │ (estado em notification_states)
                                        ▼
                                  notification_queue ──> deliver() ──> Telegram sendMessage
```

- Produtores nunca falam com o Telegram. Condições são **calculadas** a partir
  do vocabulário de saúde já existente (`AlertCondition`, `HealthStatus`) e
  entram numa fila persistente.
- `php artisan notifications:run` roda a cada minuto no container `scheduler`
  (`routes/console.php`, `withoutOverlapping`). Avalia e entrega no mesmo passo.
- Tabelas: `notification_settings` (linha única), `notification_queue`,
  `notification_states` (uma linha por condição: ativa?, motivo, último aviso).

## Regras

| Situação | Comportamento |
| --- | --- |
| Condição nova | alerta imediato |
| Persiste | repete só após o cooldown (padrão 60 min, 5–1440) |
| Motivo muda (ex.: atraso → muito atrasado) | alerta imediato, sem esperar cooldown |
| Condição some | aviso "🟢 Normalizado" e estado reiniciado |
| Janela de manutenção | só alertas **críticos** passam; os demais saem depois se ainda ativos |
| Avaliação falha (exceção) | nada é enviado e **nenhuma normalização é inventada** |
| Falha de entrega | até 5 tentativas, espera 1/2/4/8 min (limitada a 1 h); respeita `retry_after` do 429; 401 falha na hora |
| Canal desabilitado | itens automáticos ficam na fila; só a mensagem de **teste** sai |

Condições monitoradas: motor, worker, agendador, fila acumulada, execuções
travadas, armazenamento (80% / crítico), FTP travado, falha de retenção, e
**cada equipamento** com `warning`/`critical` na saúde de backup (falhas
consecutivas, último backup falhou, atrasado, FTP nunca recebido…).
`repeated_device_failures` não gera alerta próprio: já é coberto por
equipamento, com nome.

## Tela (Configurações → Notificações)

- Token do bot: cifrado (`Crypt`), nunca vem no HTML. O botão do **olho** busca o
  token salvo sob demanda em `POST settings/notifications/token/reveal`
  (`throttle:6,1`, `settings.manage`, `Cache-Control: no-store`, auditado como
  `notifications.token_revealed` **sem** o valor). Isto é uma diferença
  consciente em relação ao V1, que nunca devolvia o token.
- Chat ID (`-100…` em supergrupos, ou `@canal`) e **ID do tópico** opcional
  (`message_thread_id`) para supergrupos com tópicos (o V1 usava o tópico 1411).
- Janela de manutenção no fuso da instância, cooldown, botão de teste, histórico
  das últimas 30 mensagens com códigos de erro sanitizados
  (`TELEGRAM_UNAUTHORIZED`, `…_DESTINATION_REJECTED`, `…_RATE_LIMITED`,
  `…_UNREACHABLE`, `…_HTTP_ERROR`). A resposta bruta do Telegram nunca é guardada.
- Texto em HTML do Telegram com todos os campos dinâmicos escapados.

## Comparação com o V1

| Tema | V1 | V2 |
| --- | --- | --- |
| Fila + retry | SQLite, 5 tentativas | igual, em PostgreSQL |
| Alertas de disco cheio / serviço parado | **lacuna** (mapa de eventos incompleto) | cobertos desde o início via `AlertCondition` |
| Duplicidade de mensagens | corrigida depois com chave operacional | não existe fila por evento: cada condição tem estado único |
| Token na tela | nunca reexibido | oculto por padrão, revelável sob demanda e auditado |
| Resumos / destinos / tópicos por equipamento | sim | **adiado** (um destino, um tópico) |

## Erros e acertos da implementação (registro)

- **Teste com `array +`**: `configure(['enabled'=>true] + $extra)` mantinha o
  `true` da esquerda e ignorava `enabled=false`; o teste "canal desabilitado"
  falhava (2 enviados em vez de 1). Era bug do teste, não do envio. Corrigido
  com `array_merge`.
- **`$errors` do Blade**: a variável `errors` colide com o `ViewErrorBag` do
  Laravel; os rótulos de erro foram passados como `errorLabels`.
- **Sem `@stack('scripts')` no layout**: o JS do olho vai inline no fim da
  seção `content` (mesmo padrão de `ftp/show.blade.php`).
- **Permissões do host**: o usuário do agente não acessa o Docker nem o `.env`;
  migrations e testes foram executados pelo operador (root). `settings/edit.blade.php`
  é de root e não foi alterado — o acesso é pelo menu lateral.
- **Supergrupo**: faltava o ID do tópico; adicionado em migration própria
  (`…000002_add_thread_id…`) em vez de editar a primeira, já aplicada.
- **Homologação real**: mensagem de teste recebida no supergrupo/tópico real
  em 2026-10-07 10:00 (fuso da instância).

## Operação

```bash
docker compose exec -T app php artisan notifications:run   # avalia + entrega agora
docker compose exec -T app php artisan test --filter=NotificationTest
```

Ao habilitar o canal pela primeira vez, tudo que já estiver em problema é
avisado de uma vez (não há cursor histórico: o estado vem da saúde atual).
O escopo adiado fica registrado em `docs/IDEIAS_FUTURAS.md`.
