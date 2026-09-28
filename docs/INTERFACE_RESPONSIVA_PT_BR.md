# Interface responsiva e português — 27/09/2026

## Escopo concluído

Revisão do sistema para notebooks, tablets e celulares, atualização de
Relatórios e Saúde do sistema conforme as referências fornecidas, tradução
de rótulos operacionais e padronização dos formulários de edição. Esta rodada
consolida os ajustes da interface, incluindo os formulários de cadastro em
modais que já estavam presentes na área de trabalho.

## Relatórios

A página inicial apresenta cartões com contagens reais e as três últimas
exportações registradas na auditoria. O histórico respeita as permissões:
quem não possui `audit.view` visualiza apenas as próprias exportações. A
ação de exportar novamente usa filtros permitidos e consulta os dados
atuais; o arquivo CSV original não fica armazenado.

A barra lateral mantém os mesmos 250 px e a escala tipográfica das demais
abas. Foram removidos os aumentos exclusivos de largura, marca, ícones e
fontes de Relatórios. As cores da referência continuam na página.

As tabelas dos cinco relatórios passam a exibir linhas com rótulos em telas
de até 720 px. Filtros, paginação, permissões e nomes internos dos estados
continuam funcionando. Veja também [REPORTS.md](REPORTS.md).

## Saúde do sistema

A referência `referencia/fc45bb25-85fb-456e-ad15-034e875c3ac7.png` orientou
o painel de situação geral, quatro indicadores, oito serviços principais,
alertas e equipamentos sem backup concluído.

O estado exibido é o resultado real do diagnóstico. Um estado desconhecido
ou crítico nunca aparece como saudável apenas para reproduzir a imagem.
CPU, carga, memória, armazenamento e taxa de sucesso usam os serviços já
existentes. Valores indisponíveis aparecem como traços, sem números ou
gráficos inventados. As barras de progresso usam os percentuais reais.

O resumo mostra até quatro equipamentos da amostra de problemas do serviço
de saúde. Essa amostra é limitada a vinte equipamentos pelo serviço e não
representa um inventário completo. O aviso e o link para Status dos backups
permitem consultar os demais. Equipamentos com backup agendado nunca
concluído aparecem com esse motivo explícito.

O diagnóstico expansível conserva todas as verificações, informações de
fila, tarefas presas, erros, equipamentos e relatório técnico completo.
Os links dos serviços e alertas abrem a verificação correspondente.

## Cadastros e edição

Usuários, Políticas, Credenciais, Equipamentos e Sites/POPs agora apresentam
cabeçalho, cartões, campos e botões do mesmo sistema visual ao abrir Editar.
Os formulários compartilhados também atualizam as páginas de cadastro e
os modais existentes. Campos usam duas colunas quando há espaço e uma no
celular. Os valores, métodos HTTP e destinos dos formulários são preservados.

O controle **Site ativo** usa o mesmo fundo de cartão, borda e escala de
texto dos outros campos, em vez do fundo quase preto do componente antigo.

Em Usuários continuam disponíveis a alteração de nome, e-mail e papel e a
redefinição de senha, com os limites de administrador existentes. Políticas
preservam a lista de equipamentos associados, ativação, remoção, criação de
execução e seleção de credenciais do equipamento.

O assistente Huawei OLT/FTP mantém cabeçalho e navegação acessíveis: somente
o conteúdo central rola, inclusive no celular na horizontal. O menu da
conta FTP abre sobre a página, sem aumentar sua altura.

## Traduções

`app/app/Support/OperationalLabels.php` centraliza os rótulos utilizados em
execuções, dashboard, FTP e relatórios. Exemplos:

| Valor interno | Rótulo exibido |
| --- | --- |
| `stored` | Armazenado |
| `processing` | Em processamento |
| `quarantined` | Em quarentena |
| `succeeded` | Concluído |
| `queued` | Na fila |
| `retry_wait` | Aguardando nova tentativa |
| `timed_out` | Tempo esgotado |
| `scheduler` como origem | Agendamento |
| `ssh_pull` | Coleta via SSH |
| `ftp_push` | Envio via FTP |
| `account_uuid` como organização | Por conta |

**Armazenado** indica que o recebimento FTP foi armazenado com sucesso.
A conta de servidor de arquivos pode ter recebimentos armazenados sem uma
execução de backup associada.

Fabricante, nome do host, configuração, servidor de arquivos, agendador,
processador de tarefas e mensagens de diagnóstico substituem os termos
correspondentes em inglês. Auditoria identifica a exportação como
Exportação de relatório e apresenta seus metadados com rótulos em português.

Os CSVs passam a usar os mesmos rótulos traduzidos para métodos, estados,
saúde e organização FTP, além dos cabeçalhos em português. Consumidores que
comparavam as células com `succeeded` ou `ssh_pull` devem usar os novos
rótulos. Filtros, banco de dados, APIs, códigos de erro, caminhos e conteúdo
técnico bruto conservam seus identificadores originais.

## Validação

- 219 testes Laravel e 1.532 asserções aprovados nas áreas afetadas:
  relatórios, exportações, saúde, auditoria, cadastros, permissões, execuções
  e integração FTP/OLT. Inclui verificações dos rótulos reais em CSV e do
  recebimento `stored` exibido como Armazenado.
- 54 respostas HTTP autenticadas ou de login validadas com dados sintéticos
  isolados, mais a prévia de credencial FTP exibida uma única vez.
- 55 variantes de tela em nove resoluções: 1920×1080, 1672×941, 1366×768,
  1280×720, 1024×768, 768×1024, 390×844, 320×568 e 844×390.
  São 495 verificações, sem transbordamento horizontal ou erros JavaScript.
- 117 aberturas de menus e 144 aberturas de modais conferidas, incluindo
  limites da janela, rolagem e botões de navegação.
- Mais 18 conferências dos seis formulários de edição (incluindo Huawei
  OLT), em notebook e celular nas duas orientações, com CPU seis vezes mais
  lenta simulada pelo Chromium, sem transbordamento ou erros JavaScript.
- Blade compilado, arquivos PHP alterados formatados com Pint e
  `git diff --check` sem erros. O contêiner não possui Git; por isso Pint foi
  executado com a lista explícita de arquivos após a tentativa de `--dirty`.

Os ensaios de resolução usam Chromium e dados sintéticos; não substituem
medições em aparelhos físicos. A interface continua renderizada no servidor,
com CSS e JavaScript locais, sem nova biblioteca ou dependência. O desempenho
final também depende do aparelho, da rede e do servidor. Não houve migração
de banco nem alteração dos protocolos de backup.

## Arquivos e entrega

As alterações se concentram em `app/resources/views`,
`app/public/assets/app.css`, `ReportController`, `OperationalLabels`,
`EngineHealth`, `AuditPresenter` e testes de regressão. A consolidação da
interface inclui as dependências já presentes dos modais de equipamento e
credencial e do acompanhamento de execução. Os detalhes anteriores de
interface permanecem em [UI_POLISH.md](UI_POLISH.md).

O commit Git solicitado será identificado na entrega. Alterações de
recuperação, scripts de backup do sistema, instruções do projeto e remoções
de referências fora desta revisão são preservadas na área de trabalho.
