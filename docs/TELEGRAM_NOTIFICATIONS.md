# Notificações Telegram (V2)

Portado do V1 (`backup_manager/notifications.py`, `docs/NOTIFICATIONS.md` do
Backup Manager Local) em 2026-10-07. Escopo entregue: **alertas + normalização**
e **resumos diário/semanal**. Fora do escopo por decisão do operador: resumo
executivo, múltiplos destinos/tópicos, aviso de backup concluído, cópia de
arquivos pelo Telegram.

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
| 3 ou mais alertas novos na mesma rodada | **uma única mensagem** "N alertas novos" com a lista (até 15 linhas + "… e mais N"); idem para normalizações. O estado e o cooldown continuam por condição |
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

## Resumos diário e semanal

Serviço `NotificationSummary`; agendamento em `NotificationManager::summaries()`,
chamado a cada minuto por `notifications:run` (só com o canal habilitado).

- **Diário:** dia civil anterior completo (00:00–00:00, fuso da instância).
- **Semanal:** os 7 dias civis anteriores ao dia do envio; só sai no dia da
  semana configurado (0 = segunda … 6 = domingo).
- **Idempotência:** chave por período (`summary:daily:AAAA-MM-DD`,
  `summary:weekly:AAAA-MM-DD`) consultada na fila antes de enfileirar; reinício
  ou scheduler parado não duplica. Se o scheduler voltar depois do horário no
  mesmo dia, o resumo sai ao voltar (catch-up no próprio dia).
- **Conteúdo:** backups concluídos (com quantos por FTP), falhas
  (`failed`/`timed_out`), arquivos FTP rejeitados (`ftp_received_files` em
  `quarantined`), backups removidos por retenção (`backup_artifacts.deleted_at`)
  e equipamentos que precisam de atenção **agora** (até 10, com motivo).
- Botões "Prévia do resumo diário/semanal" enfileiram uma amostra com dados
  reais (`kind=test`), sem consumir a chave do período.
- Ao ativar depois do horário configurado, o resumo do período anterior sai logo.

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
- **Enxurrada ao habilitar**: na primeira homologação real chegaram 5 alertas
  seguidos ("Envio FTP muito atrasado", um por equipamento). Isso motivou o
  agrupamento (≥ 3 → uma mensagem). Esses alertas refletem **atraso desde o
  último arquivo recebido** (intervalo esperado + tolerância), não "erro de
  hoje"; equipamentos que enviam menos vezes que o intervalo cadastrado devem
  ter `expected_ftp_interval_hours` ajustado.
- **500 em `/backup-health`** (não era do Telegram): view de outra sessão passava
  string a `InstanceTimezone::format()`, que só aceitava `CarbonInterface`.
  Corrigido aceitando string (UTC) — commit `030cf49`.
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
