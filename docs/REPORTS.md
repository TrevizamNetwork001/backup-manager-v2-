# Relatórios e exportação CSV

Atualização consolidada de responsividade, edição e traduções:
[INTERFACE_RESPONSIVA_PT_BR.md](INTERFACE_RESPONSIVA_PT_BR.md).
Os CSVs agora apresentam estados e métodos em português, mantendo os
identificadores originais no banco, nos filtros e na auditoria.

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

## Página inicial de Relatórios — atualização de 27/09/2026

A página `/reports` foi atualizada a partir da imagem
`referencia/a069a809-fe39-4cf6-8cd7-3770931a5010.png`. O resultado usa fundo
azul escuro, bordas discretas, títulos em ciano e cartões com ícone, contador
e seta de acesso. Os cartões levam às consultas existentes; Status dos
backups e Auditoria levam às respectivas áreas do sistema.

Os números vêm do banco de dados, sem reproduzir os valores ilustrativos da
referência:

| Cartão | Contagem apresentada |
| --- | --- |
| Execuções | Todas as execuções de backup. |
| Equipamentos | Todos os equipamentos cadastrados. |
| Falhas | Execuções com `status = failed`. |
| Status dos backups | Todos os equipamentos cadastrados. |
| Artefatos | Todos os registros de artefatos, incluindo estados disponíveis, removidos e ausentes. |
| FTP | Todas as contas FTP, incluindo contas inativas. |
| Auditoria | Todos os eventos de auditoria; cartão disponível somente com `audit.view`. |

O contador de Falhas resume execuções falhas. A consulta detalhada mantém
seus critérios próprios de agrupamento por código de erro. Os contadores
da página inicial não recebem filtros de período e são formatados com
separador de milhar em português.

### Histórico e ações

**Relatórios recentes** exibe até três exportações registradas por
`report.exported`, restritas ao recurso `report` e aos tipos execuções,
equipamentos, falhas, artefatos e FTP. A consulta ordena por data e depois por
ID, ambos decrescentes, e carrega os nomes dos usuários junto com os eventos.
Se a tabela de auditoria não existir, o histórico fica vazio.

Cada linha mostra o tipo de relatório, a descrição **CSV exportado**, o
período ou a indicação de filtro personalizado, a quantidade de registros,
a data no fuso configurado da instância, o responsável e o resultado.
**Concluído** corresponde a `success`; os demais resultados aparecem como
**Falhou**. O histórico atual é alimentado pelos eventos de exportação
concluída: não foi criado acompanhamento de geração assíncrona ou estado
**Processando**.

O botão com ícone de download, rotulado **Exportar novamente em CSV**, chama
a exportação existente com os filtros guardados no evento. Ele gera um novo
arquivo com os dados atuais; o CSV original não é armazenado. Somente as
chaves de filtro previstas para cada tipo são incluídas nos links. O menu
de três pontos oferece **Abrir relatório** com esses filtros e, para quem
tem `audit.view`, **Ver detalhes** do evento.

Usuários com `audit.view` podem consultar exportações de outros usuários e
usar **Ver todos**, que abre Auditoria filtrada por `report.exported` e pelo
recurso `report`. Os demais usuários veem somente as próprias exportações,
sem cartão de Auditoria ou links de acesso ao histórico administrativo.
A ação de CSV respeita `reports.export`. Quando não há exportações, a tabela
orienta a selecionar um relatório e exportar em CSV.

### Responsividade, menu lateral e fontes

Os cartões usam três colunas em telas acima de 1450 px, duas de 901 a
1450 px e uma até 900 px. Até 1100 px, as linhas da tabela recente viram
blocos com rótulos de campo; até 380 px, cada bloco usa uma coluna. Os menus
flutuantes reutilizam o comportamento de posicionamento do layout comum.

Após a revisão do usuário, foram removidas as dimensões exclusivas do menu
lateral de Relatórios: largura de 305 px, largura intermediária de 260 px,
marca ampliada, fontes e ícones maiores e espaçamentos adicionais. O menu
passou a herdar as dimensões comuns das outras páginas, incluindo a largura
de 250 px no desktop e o comportamento compartilhado no celular. As cores
da página e do item ativo foram mantidas.

Também foram padronizados os títulos e textos com os tokens existentes:
título da página em 24 px, título de seção em 18 px, título de cartão em
16 px, corpo e contadores em 14 px e metadados em 12 px. Foram removidos os
aumentos específicos de títulos e fontes no celular.

### Arquivos e validação desta atualização

Arquivos de implementação:

- `app/app/Http/Controllers/ReportController.php`: contadores e consulta do histórico com controle de acesso.
- `app/resources/views/reports/index.blade.php`: cartões, histórico, estado vazio e ações.
- `app/resources/views/components/icon.blade.php`: novos desenhos `play-circle` e `arrow-right`.
- `app/public/assets/app.css`: aparência e responsividade da página, com dimensões compartilhadas do menu e tokens de tipografia.
- `app/tests/Feature/ReportsSmokeTest.php`: cobertura dos contadores, ordenação e limite do histórico, filtros preservados, fuso horário e permissões.

Validações realizadas:

- `ReportsSmokeTest`, `ReportsAuthorizationTest` e `ReportExportTest`: **13 testes e 137 asserções aprovados**, usando SQLite em memória no contêiner PHP. Os testes foram repetidos após a formatação do código e o último ajuste de texto.
- Laravel Pint aplicado aos dois arquivos PHP alterados. A opção `--dirty` não estava disponível no contêiner, que não tem acesso ao Git; a formatação foi executada diretamente nos dois arquivos.
- Conferência em Chromium das páginas com histórico e vazia nas resoluções **1672×941, 1366×768, 1280×720, 1024×768, 768×1024, 390×844 e 320×568**. Sem transbordamento horizontal ou erros JavaScript; cartões, ícones, estado vazio e menus acessíveis.
- Após o ajuste do menu, comparação automatizada das dimensões, fontes e espaçamentos de seus elementos com os estilos comuns, aprovada nas mesmas sete resoluções.
- Leitura HTTP do Nginx local: `/reports` retornou **302** sem autenticação e `/assets/app.css` retornou **200**. A renderização autenticada foi verificada pelos testes, não por uma sessão no endereço público.
- `git diff --check` sem erros.

Para repetir os testes e a checagem do diff, executar na raiz do projeto:

```bash
docker compose exec -T app php artisan test --compact tests/Feature/ReportsSmokeTest.php tests/Feature/ReportsAuthorizationTest.php tests/Feature/ReportExportTest.php
git diff --check
```

As prévias visuais foram geradas com dados isolados de teste, sem alteração
de dados de produção. O CSS é servido diretamente e sua URL já inclui a
data de modificação para invalidar o cache; não foi necessário compilar
assets com npm. Nesta atualização não houve migração, nova dependência,
commit ou push. A documentação complementar está em
[UI_POLISH.md](UI_POLISH.md#relatórios-cartões-histórico-e-padronização--27092026).

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
