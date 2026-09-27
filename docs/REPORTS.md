# Relatórios e exportação CSV

## Entrega

`/reports` reúne execuções, equipamentos, artifacts, falhas e contas FTP. As
rotas exigem autenticação, conta ativa e as permissões `reports.view` ou
`reports.export`. A navegação e a exportação usam as mesmas consultas e
filtros; listagens de execuções, equipamentos e artifacts paginam 25 itens.

- **Execuções:** período, site, equipamento, vendor, política, status, método
  e código de erro. Exibe resumo de status e duração.
- **Equipamentos:** saúde calculada pelo mesmo `DeviceBackupHealth` usado no
  dashboard, com filtros de site, vendor, saúde, política e frescor.
- **Artifacts:** período, site, equipamento, status e faixa de tamanho.
- **Falhas:** somente execuções terminais com `error_code`; agrupa por código
  e por equipamento. A classificação retryable usa os códigos do engine.
- **FTP:** contas de backup e file server, estado e contagens de recebimento,
  armazenamento, quarentena e processamento pendente.

`ReportPeriod` resolve datas no fuso configurado da instância e converte os
limites para UTC antes da consulta. `InstanceTimezone` guarda o fuso por
request. Datas exportadas também são formatadas nesse fuso.

## CSV e auditoria

`CsvExporter::stream()` escreve BOM UTF-8, cabeçalho e linhas diretamente em
`php://output`. Aceita `iterable`, inclusive gerador ou `LazyCollection`, sem
montar o CSV inteiro em memória. Campos cujo primeiro caractere é `=`, `+`,
`-` ou `@` recebem apóstrofo para neutralizar fórmulas em planilhas. Cada
relatório exporta apenas colunas operacionais; senhas, segredos de credenciais
e conteúdo de configuração não fazem parte dos dados exportados.

O callback `onComplete(int $rowCount)` roda depois de escrever e fechar o
stream com sucesso. Só então é criado `report.exported`, com tipo, filtros,
formato e contagem real de linhas. Exportação vazia registra zero. Exceção
durante a geração ou escrita não cria evento de sucesso com contagem parcial.
O evento nunca guarda o CSV ou segredos de credenciais. A resposta HTTP já
pode ter enviado linhas antes de uma exceção tardia; o cliente deve tratar
download interrompido como falha.

As consultas de execuções e artifacts usam iteração preguiçosa. Equipamentos,
falhas e FTP usam coleções ou agregações existentes para calcular o relatório;
essas consultas ainda podem consumir memória proporcional ao conjunto de
linhas/contas. O *exportador* não adiciona outra cópia integral do CSV.

## Comparação com V1

As consultas e a formatação foram centralizadas em classes próprias. O V1
montava o arquivo CSV inteiro antes de devolver a resposta, não neutralizava
fórmulas e aplicava limites de datas sem converter o fuso da instância. Esta
entrega corrige esses três pontos e registra auditoria apenas após o stream.

## Metadata RouterOS

O backup SSH RouterOS extrai a versão presente no cabeçalho comentado do
`/export` já coletado, sem comando SSH adicional. A análise é informativa e
registrada em `backup.content_analyzed`; não altera a aceitação do artifact.
O parser lê apenas o início do cabeçalho e devolve um token de versão
validado, nunca a linha original, serial ou conteúdo com segredos. A
detecção de versão antes do backup continua fora de escopo.

## Decisão sobre lixeira e prazo de graça

**Adiada como feature separada.** A retenção atual faz hard-delete depois de
reverificar hash, tamanho e inode, protege o artifact mais recente e audita o
resumo. Uma lixeira segura requer estado e migração próprios, área protegida
com capacidade definida, política de purga, restauração autorizada, recovery
após falha entre arquivo e transação e testes de concorrência. Adicionar só
um rename ou um prazo no código atual criaria uma falsa possibilidade de
restauração. Até essa feature, exclusões são irreversíveis pelo produto.

## P1/P2 revistos nesta entrega

- **P1 resolvido:** o claim genérico ignora `ftp_received` em `queued` e
  `retry_wait`; o receiver FTP continua dono desse fluxo.
- **P2 resolvidos:** exclusão de conta FTP bloqueia `retry_wait`; sidecar de
  processamento inválido vai à quarentena em vez de ser lido a cada scan.
  A memoização de `InstanceTimezone` e a rota de download de artifact também
  constam do conjunto de mudanças desta fase.
- **P2 mantidos:** arquivo órfão após falha de `engine:complete`, corrida
  entre recepção manual e espontânea, unlink antes do commit na exclusão
  manual, trilha por artifact e recuperação na retenção, consulta do gráfico
  do dashboard, invalidação de sessões no reset administrativo e CHECKs
  PostgreSQL fora da suíte SQLite. Cada um pede tratamento e testes próprios;
  a decisão de lixeira acima não os resolve.

## Verificação

`DeviceReportTest`, `FailureReportTest`, `ExecutionReportTest`,
`ReportExportTest`, `ReportsAuthorizationTest` e `ReportsSmokeTest` exercitam
filtros, período, permissões, paginação, CSV, auditoria e ausência de
segredos. `ReportExportTest` cobre gerador de 50 mil linhas, contagem real,
exportação vazia, neutralização de CSV e exceção após linha parcial.
