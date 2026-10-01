# UI-POLISH-1 — inventário e convenções visuais

Os ajustes do dashboard e da navegação de 27/09/2026 estão registrados na seção **Dashboard: alinhamento, cores e colunas**, com as decisões finais sobre os ícones logo abaixo. O rodapé de ponta a ponta e o símbolo do login estão detalhados em [LOGIN_SCREEN.md](LOGIN_SCREEN.md#rodapé-e-símbolo-da-marca--ajustes-de-27092026).

No início, `git status --short` mostrou cinco arquivos de UI rastreados modificados: `app/public/assets/app.css`, `app/resources/views/auth/login.blade.php`, `app/resources/views/backup-artifacts/index.blade.php`, `app/resources/views/backup-artifacts/show.blade.php` e `app/resources/views/users/create.blade.php`. A arte `app/public/assets/login-background.png` já estava não rastreada. O pedido citava quatro arquivos; a contagem observada foi cinco. Todos foram comparados com `git diff` e preservados na integração. Também havia alterações prévias em autenticação, Nginx, testes, scripts, docs e referências; as que não pertencem à UI permanecem separadas.

## Inventário inicial (antes das edições da fase)

| Tela | Finalidade e estado real | Ajuste de apresentação identificado |
| --- | --- | --- |
| Login | Autenticação, CSRF, limite de tentativas, lembrança de sessão | Novo desenho já presente no working tree; rodapé verboso e controle de recuperação sem fluxo próprio; preservar foco único |
| Dashboard | Contagens, série temporal, execuções e FTP reais | Muitas métricas competem por atenção; saúde dos backups removida por decisão do operador e acessível na navegação |
| Sites / POPs | Cadastro, edição, remoção condicionada, paginação e estado vazio | Referência de linguagem; falta busca rápida; remoção fica exposta na lista |
| Equipamentos | Cadastro, edição, status e paginação | Lista não resume método, política, último backup ou saúde; ausência de detalhe dedicado na V2; remoção fica exposta |
| Credenciais | Cadastro e vínculo seguro, sem expor segredo | Manter campos compactos, ajuda e ações por permissão |
| Políticas | Cadastro, associações, execução manual e agendamento | Hierarquia das associações e ações demanda agrupamento, sem mudar o fluxo |
| Execuções | Filtros de status, origem e equipamento; detalhe e ações de fila | Tabela extensa com datas redundantes; status precisa semântica uniforme; erro deve ser visível |
| Artefatos | Histórico paginado, detalhe, download autorizado e exclusão com preview | Mudanças pré-existentes já trazem tabela responsiva e risk-zone; preservar |
| FTP | Contas de backup e file server, recebimentos, prontidão e gestão | Separar finalidades visualmente e recolher diagnóstico técnico no detalhe |
| Relatórios | Consultas filtradas e exportação CSV | Cards e tabelas funcionais; limitar densidade e manter exportação contextual |
| Auditoria | Busca, filtros, resumo de segurança e metadata sanitizada | Alta densidade útil; manter filtros legíveis e ator Sistema quando nulo |
| Usuários | RBAC, criação, edição, estado e redefinição de senha | Form de criação já modificado no working tree; manter tabela compacta e risco discreto |
| Configurações | Fuso horário editável | Só há configuração da instância nesta versão; não inventar Engine/Storage/FTP/Security/Recovery editáveis |
| Saúde do sistema | Checks reais de DB, Redis, engine, worker, scheduler, fila, storage, FTP e retenção | Resumo primeiro; recolher mensagens técnicas individuais |
| Previews destrutivos | Impacto, blockers, confirmação e ação final | Padronizar área de risco e manter validação do servidor |
| Recovery/sistema | Documentação e diagnóstico; sem página de restore na V2 | Apontar para saúde/documentação, sem criar rota ou fluxo novo |

Estados transversais: listas vazias, filtros sem resultado, erro de formulário, ausência de dados e status desconhecido devem ter texto explícito. Paginação continua do servidor. Em mobile, preservar cabeçalhos de contexto por célula ou rolagem acessível. Os controles visíveis seguem `@can`; a autorização efetiva permanece no backend.

## Comparação operacional com V1

| Classificação | Observação |
| --- | --- |
| PRESERVAR | Acesso rápido a falhas, último backup, próximos agendamentos confiáveis, equipamentos que precisam atenção e uso de storage |
| MELHORAR | Leitura da lista de equipamentos por método/site/estado, contexto do arquivo e feedback de ações |
| DESCARTAR | Visual do V1, atalhos para recursos ausentes da V2 e detalhes técnicos expostos por padrão |

## Design system

`app/public/assets/app.css` contém tokens semânticos. Fundos `--color-bg`, `--color-surface`, `--color-surface-elevated`; contornos `--color-border`; texto `--color-text`, `--color-text-muted`; ação `--color-primary`; estado `--color-success`, `--color-warning`, `--color-danger`. Espaçamento `--space-1` a `--space-6` equivale a 4, 8, 12, 16, 24 e 32 px. Raios `--radius-sm/md/lg`; sombras `--shadow-elevated/overlay`; tipografia `--font-size-*` e `.type-*`. Cores de estado representam dados fornecidos pelo servidor.

Componentes: `.page-header`, `.card`, `.btn`, `.badge`, `.form-field`, `.form-control`, `.data-table`, `.empty-state`, `.risk-zone` e `.modal`. Uma ação principal por cabeçalho. Tabelas usam região rolável com nome acessível; ações destrutivas com preview ficam no detalhe ou na área de risco; remoções legadas de cadastro ficam recolhidas em “Ações” e mantêm confirmação nativa. Empty state explica ausência de dados e só oferece CTA autorizado. Inputs usam label real, erro associado e foco visível. Formulários POST exibem estado de envio para evitar clique repetido. Animação respeita `prefers-reduced-motion`.

Responsividade alvo: 1920, 1366, 1280, tablet e mobile. Nenhum painel pode depender de altura fixa; tabelas largas rolam ou usam `data-label`. Contraste, ordem de headings, teclado e foco de dialogs devem ser preservados.

## Aplicação nesta fase

- Login: preservado o desenho em duas colunas já existente; removidos o botão de recuperação sem rota própria e textos de rodapé sem destino; erros associados aos inputs. O link administrativo para redefinir senha continua em Usuários.
- Shell: navegação com RBAC existente, atalho de teclado para conteúdo e menu mobile recolhível. O topbar deixou de declarar saúde operacional sem ler um check real.
- Dashboard: mantém métricas, gráfico, execuções e FTP com dados reais; o painel de saúde dos backups saiu conforme solicitação. A página de saúde dos backups tem link próprio no menu, sob `dashboard.view`.
- Sites e Equipamentos: busca rápida na página atual, vazio de filtro e ações secundárias recolhidas. A lista de Equipamentos usa `DeviceBackupHealth::rows()` para método, política, último backup e saúde reais; inativos são marcados “Não avaliado”. A tabela ocupa a largura disponível em desktop; até 1100 px, os registros usam cards. A busca não altera a paginação do servidor.
- Execuções: tabela operacional mostra hora, equipamento, política, método, status, duração total em segundos e número da tentativa. A coluna de erro aparece somente nas páginas com falhas e usa a descrição em português. Para FTP recebido, a duração vai do horário registrado do arquivo até a conclusão do processamento; o detalhe preserva os horários e o contexto completos.
- Artefatos e criação de usuário: mudanças encontradas no working tree foram preservadas e usadas como base. O detalhe oferece download somente ao perfil autorizado e quando o status é disponível; a rota ainda verifica o arquivo. Exclusão de artifact permanece apenas no detalhe, com preview e frase de confirmação.
- FTP: diagnóstico técnico da lista recolhido; finalidades e recebimento permanecem visíveis. O detalhe já recolhia chroot e PureDB.
- Saúde do sistema: cada check mostra o status imediatamente e a mensagem ao expandir. Os demais painéis continuam somente leitura.
- Relatórios, Auditoria, RBAC, Configurações e formulários: componentes e permissões existentes foram mantidos. Configurações só oferece Timezone porque as demais áreas não têm formulário nesta versão.

O V2 não possui rota de detalhe geral do equipamento; a edição reúne dados gerais e o fluxo Huawei/FTP. A fase visual não cria essa rota nem duplica a lógica de saúde. A lista usa a classificação existente; o relatório de equipamentos e a página de saúde oferecem filtros e mais contexto.

### Ajustes na lista de Equipamentos — 27/09/2026

A listagem segue os componentes visuais de Sites / POPs: cabeçalho, contador, busca, tabela, estados e menu de ações. Em desktop, a tabela usa `width: 100%`, `min-width: 0` e colunas de largura controlada, com padding horizontal menor e quebra de texto nas células. Status e ações ficam compactos. O container não cria rolagem horizontal; em larguras de até 1100 px, a lista muda para cards com rótulos por campo. As colunas e os dados operacionais foram preservados.

O dropdown **Ações** fica posicionado sobre a tabela, alinhado à direita do botão, sem participar da largura da célula. Na última linha, abre para cima para manter as opções acessíveis. Editar, Remover e as permissões existentes permanecem os mesmos.

A lista de **Credenciais** usa o mesmo dropdown para Editar e Remover, respeitando `credentials.manage` e `credentials.disable`. A tabela cabe na largura disponível em desktop; até 1220 px, os registros passam a cards com rótulos por campo, mantendo o menu sobreposto.

Em **Sites / POPs**, o menu Ações também flutua sobre a tabela e abre para cima na última linha. A tabela deixa de impor largura mínima; até 1000 px, os registros passam a cards. A busca e as permissões `sites.manage`/`sites.delete` permanecem intactas.

Na coluna **Equipamento**, nome, hostname (quando existir) e IP são exibidos em linhas próprias. O separador `·` entre hostname e IP foi removido para não sobrar no fim da linha quando o conteúdo quebra.

Os cálculos de largura foram conferidos para 1920×1080, 1366×768 e 1280×720, e `git diff --check` passou. O ambiente não oferecia navegador para captura ou validação visual automatizada; o resultado foi confirmado pelo operador. Essas mudanças ficaram restritas ao CSS da lista de Equipamentos e ao Blade da coluna Equipamento, sem alteração de backend ou publicação remota.

### Menus de ações, FTP e políticas de backup — 27/09/2026

O padrão de dropdown flutuante foi aplicado também às listas de **Sites / POPs**, **Credenciais**, **FTP** e **Políticas de Backup**. O menu usa `<details>` com o resumo “Ações”; seu painel é posicionado de forma absoluta sobre a tabela, com largura controlada, de modo que abrir o menu não alarga a linha. Nas listas de Sites, Equipamentos, Credenciais e Políticas, a última linha abre o painel para cima. Em FTP, “Abrir” é a ação disponível na lista; as operações próprias da conta continuam no detalhe. Nas Políticas, as opções são exibidas conforme RBAC e o rótulo da edição é **Editar** (não “Editar / Gerenciar”); a remoção mantém a confirmação e a restrição existentes.

No cadastro de **Conta FTP**, a senha agora recebe indicação dinâmica de força (sem senha, fraca, razoável ou forte) e uma dica de como melhorá-la. O indicador é orientação de interface, não substitui a validação do backend. O botão **Gerar** cria uma senha aleatória com `crypto.getRandomValues` e preenche também a confirmação. A finalidade “Backup” torna a seleção de equipamento obrigatória no formulário; o servidor já aplica `required_if:purpose,backup` em `FtpAdminController::store`. Para “Servidor de arquivos”, o campo é desativado e limpo.

**Arquivos desta alteração:** `app/public/assets/app.css` e os templates `app/resources/views/{devices,sites,credentials,ftp,backup-policies}/index.blade.php`. A regra do servidor citada acima já existia e não foi alterada nesta etapa.

**Registro Git e validação:** `git diff --check` passou após as edições. A revisão pode ser repetida com `git status --short`, `git diff --check` e `git diff -- app/public/assets/app.css app/resources/views/devices/index.blade.php app/resources/views/sites/index.blade.php app/resources/views/credentials/index.blade.php app/resources/views/ftp/index.blade.php app/resources/views/backup-policies/index.blade.php docs/UI_POLISH.md`. No momento do registro, as alterações ainda estavam no working tree, sem commit ou push. O status do repositório também contém outras mudanças preexistentes, que não foram incluídas neste registro. Não houve validação visual em navegador nesta etapa.

### Detalhe e acompanhamento de execuções — 27/09/2026

O detalhe da execução foi reorganizado em cartões e campos de metadados responsivos. Os estados internos em inglês agora têm rótulos em português (por exemplo, `queued` aparece como **Na fila**). Ao enfileirar, o aviso informa que a execução entrou na fila e explica que o engine a processará quando houver worker disponível; esse aviso fica na tela até ser fechado.

Enquanto a execução estiver pendente, na fila, em andamento ou aguardando nova tentativa, a tela consulta uma rota autenticada de status a cada cinco segundos. Durante o processamento, atualiza o badge entre **Na fila**, **Em andamento** e **Aguardando nova tentativa**. Ao detectar um estado final, atualiza a página para mostrar horários, erros e artefatos mais recentes, sem exigir F5. A consulta é somente leitura e retorna apenas o status. A execução continua sendo processada pelo engine.

Na lista de Políticas de Backup, a tabela agora usa layout fixo e uma coluna de ações compacta. O painel de **Ações** fica absoluto, com largura limitada e sem aumentar a linha; a última linha abre o menu para cima. A tabela cabe na largura do container e, até 1100 px, cada política é apresentada como cartão com rótulos de campo. A opção de edição é chamada **Editar**.

Validação desta atualização: o teste PHP da tela/endpoints foi preparado em `app/tests/Feature/BackupExecutionTest.php`, mas não pôde ser executado neste ambiente porque o binário `php` não está instalado. `git diff --check` é a validação local disponível; a confirmação visual em navegador ainda precisa ocorrer na homologação. Sem commit ou push.

### Cadastro de equipamento — 27/09/2026

O botão **Novo equipamento** e a ação do estado vazio agora abrem um modal no mesmo padrão visual do cadastro de Conta FTP: cabeçalho e descrição, formulário em duas colunas, campos agrupados, rodapé fixo e rolagem apenas no corpo do modal. Em telas estreitas, os campos passam a uma coluna. O modal fecha pelo botão, pelo fundo ou por Escape; erros de validação reabrem o formulário e preservam os valores enviados.

O formulário reutiliza `devices._form`, preservando campos, regras, rota POST, CSRF e RBAC. O controller da listagem fornece os Sites / POPs ativos para o seletor. Sem um Site / POP ativo, os botões de cadastro ficam desabilitados e a tela orienta a criar ou ativar um local. A rota direta `/devices/create` permanece disponível.

Arquivos: `app/app/Http/Controllers/DeviceController.php`, `app/resources/views/devices/index.blade.php`, `app/resources/views/devices/_form.blade.php` e `app/public/assets/app.css`. `git diff --check` passou; PHP e navegador não estão disponíveis para executar testes ou validar o modal visualmente nesta sessão. Sem commit ou push.

### Formulários de criação no mesmo modal — 27/09/2026

O modal compartilhado `form-create-modal` foi aplicado também a **Nova Credencial**, **Nova Política**, **Novo usuário** e **Novo Site / POP**. As ações dos cabeçalhos e estados vazios abrem o cadastro em contexto; os formulários reutilizam seus partials ou foram extraídos para `users/_form.blade.php`. Os campos, as rotas POST, CSRF, validações e regras RBAC continuam preservados. Erros de validação reabrem o modal, exibem mensagens junto aos campos e mantêm os valores não secretos; senhas não são repostas.

Em Credenciais, a lista carrega os equipamentos disponíveis para o seletor. Se a tela não tiver equipamento ou, no caso do cadastro de Equipamento, Site / POP ativo, a ação de criação é desabilitada e a tela informa o pré-requisito. As rotas dedicadas de criação permanecem disponíveis para acesso direto.

Arquivos envolvidos: `app/app/Http/Controllers/{CredentialController,DeviceController}.php`, `app/resources/views/{credentials,backup-policies,devices,users,sites}/index.blade.php`, os partials de formulário dessas áreas e `app/public/assets/app.css`. `git diff --check` é a checagem local; testes PHP e validação visual não foram executados porque PHP e navegador não estão disponíveis neste ambiente. Sem commit ou push.

### Dashboard: alinhamento, cores e colunas — 27/09/2026

O dashboard foi ajustado a partir de `referencia/9c945559-3cdc-4efc-8e13-310802f365a4.png`. O painel **Contas FTP** agora ocupa a coluna menor ao lado de **Status dos equipamentos**, em vez de uma linha inteira. **Últimas execuções** e **Recebimentos FTP recentes** também ficam lado a lado nas larguras que comportam as duas tabelas.

| Largura da tela | Painéis de visão geral | Tabelas recentes |
| --- | --- | --- |
| Acima de 1500 px | Execuções de backup, Status dos equipamentos e Contas FTP na mesma linha, nas proporções 1,9 / 1,12 / 1. | Duas colunas. |
| De 1201 a 1500 px | Mesmas três colunas; o anel de equipamentos fica acima da legenda para caber no painel. | Duas colunas. |
| De 1051 a 1200 px | Gráfico na primeira linha; Status dos equipamentos e Contas FTP lado a lado abaixo. | Duas colunas. |
| De 721 a 1050 px | Gráfico na primeira linha; Status dos equipamentos e Contas FTP lado a lado abaixo. | Uma coluna. |
| Até 720 px | Painéis empilhados. | Uma coluna; até 640 px, linhas com rótulos por campo. |

As regras deixaram de forçar Contas FTP a ocupar todas as colunas até 1500 px e de empilhar as tabelas abaixo de 1580 px. O CSS limita a largura mínima dos painéis para manter os conteúdos dentro da grade. Após comparar novamente a referência, a visão geral usa `align-items: stretch` e os cartões usam `align-self: stretch`, com altura mínima de 264 px acima de 1200 px, para alinhar tanto os topos como as bordas inferiores. O gráfico tem altura de 148 px e o filtro de período fica compacto no cabeçalho. Ao abrir esse controle, um painel flutuante com `<details>` mostra o formulário existente de período, datas e botão Aplicar, sem aumentar a altura dos cartões; erros de validação abrem o painel. Os filtros continuam sendo enviados por GET.

Status dos equipamentos centraliza o círculo e a legenda no espaço do cartão compacto; Contas FTP mantém as cinco linhas abaixo do cabeçalho, com altura mínima de 38 px e sem distribuir espaço extra entre elas. O bloco do círculo deixou de exigir uma altura mínima de 196 px. A altura pode crescer quando o conteúdo precisa, sem corte ou rolagem interna forçada nos cartões.

No cartão **Artefatos**, o ícone de pasta usa a variante `amber`, com amarelo `#ffc640` e fundo `#453a25`. O cartão **Sites / POPs** usa a variante `purple`, com roxo `#7165ff` e fundo `#202653`. As cores são aplicadas aos SVGs por `currentColor`.

Os cinco cartões de indicadores receberam desenhos exclusivos `dashboard-*`: marcador de localização para Sites / POPs, servidor para Equipamentos, banco de dados com confirmação para Backups, triângulo de alerta para Falhas e pasta aberta para Artefatos. Mantêm as cores, dimensões e alinhamento aprovados. Esses desenhos são independentes dos ícones da barra lateral e das demais telas.

Após revisão visual do usuário, a pasta de Artefatos foi ajustada para seguir a referência: aba superior e frente inclinada para a direita, com cantos arredondados e contorno amarelo.

Na barra lateral, os ícones SVG foram ajustados à função de cada item: **Relatórios** usa `report` (documento com barras de gráfico); **Saúde do sistema**, `system-health` (monitor com sinal de atividade); **Usuários**, `users` (duas pessoas); **Políticas**, `policy` (prancheta com lista de regras); **Status dos backups**, `backup-status` (banco de dados com relógio); e **Execuções de backup**, `execution` (lista com símbolo de execução). Os desenhos foram adicionados ao componente `resources/views/components/icon.blade.php` e mantêm `viewBox="0 0 24 24"`, traço e cor do conjunto existente. As rotas e permissões dos itens permanecem no fluxo atual.

**Sites / POPs** usa `site-location`, um marcador de localização para representar os locais da infraestrutura.

**Credenciais** mantém o nome e passa a usar o ícone `key` já existente no componente, representando os dados de acesso.

**Artefatos** mantém o nome e usa `folder`, alinhando a representação dos arquivos armazenados ao ícone de pasta do cartão do dashboard.

O nome **Saúde dos backups** passou a ser **Status dos backups** na navegação, no título da página e no resumo correspondente em Relatórios; a rota continua `backup-health.index` e a classificação permanece em `DeviceBackupHealth`. O item **Execuções** passou a ser **Execuções de backup** na navegação, mantendo a rota `backup-executions.index`.

As duas tabelas recentes tiveram a coluna **Ação** e os links de três pontinhos removidos. **Últimas execuções** exibe Início, Equipamento, Tipo, Status e Duração, com cinco colunas e `colspan="5"` no estado vazio. **Recebimentos FTP recentes** também teve a coluna **Conta** removida para simplificar o resumo do dashboard: exibe apenas Data/Hora, Arquivo, Tamanho e Status, com quatro colunas e `colspan="4"` no estado vazio. As larguras foram redistribuídas entre as colunas restantes. O acesso à área FTP permanece em **Ver todas**, e a identificação da conta continua disponível nas telas de detalhe.

Na coluna **Arquivo** do resumo FTP, nomes longos são encurtados apenas antes da última extensão, mantendo finais como `.zip` ou `.bin` visíveis. O nome completo aparece no tooltip. Arquivos sem extensão e nomes ocultos como `.env` continuam exibidos como um único nome. As telas de detalhe mantêm a identificação da conta e o nome completo do arquivo.

Arquivos de interface alterados: `app/public/assets/app.css` e as views `dashboard.blade.php`, `layouts/app.blade.php`, `components/icon.blade.php`, `backup-health/index.blade.php` e `reports/index.blade.php`. A expectativa do novo nome da navegação foi atualizada em `DeviceBackupHealthTest.php`. A autenticação, as consultas e os dados apresentados permanecem no fluxo existente. Os ajustes do login desta mesma sessão estão descritos em [LOGIN_SCREEN.md](LOGIN_SCREEN.md#rodapé-e-símbolo-da-marca--ajustes-de-27092026).

Validação: `DeviceBackupHealthTest`, `DashboardTimezoneTest` e `ReportsAuthorizationTest` aprovados com **17 testes e 97 asserções** após ajustar os nomes e ícones. O teste `test_dashboard_chart_accepts_presets_and_historical_dates` passou com **1 teste e 10 asserções**, cobrindo filtros predefinidos, datas históricas e rejeição de períodos acima do limite. Compilação Blade concluída e `php artisan view:clear` executado no contêiner; `git diff --check` sem erros. O usuário conferiu a tela e aprovou o layout final do dashboard. Não houve medição ou captura automatizada em navegador. O alinhamento, as colunas, as cores e os ajustes da navegação foram registrados no commit local `e4d1157` — `style: alinha dashboard e atualiza icones da navegacao`.

Atualização de 01/10/2026: **Status dos equipamentos** mantém o círculo, sem navegação ao clicar, mas agora divide o total cadastrado em três grupos: ativos sem falha pendente (verde), ativos cujo último resultado relevante de backup falhou ou aguarda nova tentativa (vermelho) e inativos (cinza). Um sucesso posterior retira o equipamento da faixa vermelha. A página **Status dos backups** continua responsável por atraso, ausência de histórico e outras condições de saúde.

### Revisão final dos ícones e decisões do usuário — 27/09/2026

A última revisão de desenhos se restringiu aos cinco indicadores do dashboard. O usuário aprovou o conjunto e pediu um ajuste adicional na pasta de Artefatos para aproximá-la da imagem de referência; esse ajuste foi aplicado mantendo o amarelo.

| Cartão do dashboard | SVG exclusivo | Representação final |
| --- | --- | --- |
| Sites / POPs | `dashboard-sites` | Marcador de localização roxo. |
| Equipamentos | `dashboard-devices` | Servidor com duas unidades e indicadores azuis. |
| Backups (24h) | `dashboard-backups` | Banco de dados com confirmação verde. |
| Falhas (24h) | `dashboard-failures` | Triângulo de alerta vermelho. |
| Artefatos | `dashboard-artifacts` | Pasta aberta amarela, com aba superior, frente inclinada para a direita e cantos arredondados, seguindo a referência. |

Os desenhos usam o componente `app/resources/views/components/icon.blade.php`; os nomes exclusivos são selecionados em `app/resources/views/dashboard.blade.php`. As dimensões, cores e posições dos cartões permanecem as aprovadas. A revisão não modifica os desenhos compartilhados da sidebar.

O usuário confirmou que o restante da interface estava bom. O item **Dashboard** da sidebar conserva `home`. Para **FTP**, foi sugerida uma pasta com setas de transferência, mas a decisão final foi manter o desenho existente `ftp`, com setas de entrada e saída. Essa sugestão não foi implementada. Os demais nomes e ícones da navegação permanecem conforme documentado acima.

Após os novos desenhos e a correção da pasta, `php artisan view:cache` compilou as views com sucesso e `php artisan view:clear` limpou o cache do contêiner para disponibilizar a atualização. `git diff --check` passou. Os testes funcionais citados na seção anterior pertencem à etapa de layout e navegação; não foram repetidos para esta revisão de SVG. Não houve captura automatizada da tela.

No momento desta documentação, a revisão dos cinco ícones, a correção da pasta e este complemento estão no working tree, ainda sem novo commit. Os commits anteriores do login (`387f0df`) e do layout/navegação (`e4d1157`) já existem localmente. Este pedido de documentação não executou push nem alterou a interface.

### Relatórios: cartões, histórico e padronização — 27/09/2026

A página inicial de Relatórios foi ajustada à imagem
`referencia/a069a809-fe39-4cf6-8cd7-3770931a5010.png`: fundo azul escuro,
cartões com ícones coloridos, contadores reais e setas de acesso. Os novos
ícones `play-circle` e `arrow-right` reutilizam o componente SVG existente.
A grade usa três, duas ou uma coluna conforme a largura disponível.

Foi acrescentada a tabela **Relatórios recentes**, alimentada pelas três
últimas exportações registradas na auditoria. Ela apresenta descrição,
filtros, quantidade de registros, data no fuso da instância, responsável,
resultado e ações para abrir o relatório ou exportar novamente. A nova
exportação usa os filtros registrados e os dados atuais, sem recuperar um
CSV armazenado. **Ver todos** e **Ver detalhes** respeitam `audit.view`;
usuários sem essa permissão consultam somente as próprias exportações.
Há orientação no estado vazio e apresentação da tabela em blocos no celular.

Após a revisão do usuário, o menu lateral deixou de usar larguras e tamanhos
exclusivos de Relatórios. Agora herda a largura, marca, fontes, ícones e
espaçamentos comuns às outras abas. Títulos, corpo, contadores e metadados
da página também passaram a usar a escala central de tipografia; as cores
da referência foram mantidas.

Os arquivos alterados foram `ReportController.php`,
`resources/views/reports/index.blade.php`,
`resources/views/components/icon.blade.php`, `public/assets/app.css` e
`tests/Feature/ReportsSmokeTest.php`, todos dentro de `app/`.

Validação: **13 testes e 137 asserções aprovados** nas suítes de renderização,
permissões e exportação de relatórios; PHP formatado com Pint e
`git diff --check` sem erros. As prévias autenticadas com dados isolados foram
conferidas em Chromium, com histórico e sem histórico, em sete resoluções
de 320 a 1672 px, sem transbordamento horizontal ou erros JavaScript. A
comparação das dimensões e fontes do menu com os estilos comuns também
passou nas sete resoluções. O Nginx local serviu o CSS com HTTP 200 e
redirecionou `/reports` sem autenticação com HTTP 302. Não houve migração,
nova dependência, commit ou push nesta atualização.

O funcionamento dos contadores, critérios do histórico, permissões, limites
e comandos de validação estão detalhados em
[REPORTS.md](REPORTS.md#página-inicial-de-relatórios--atualização-de-27092026).

## Revisão consolidada — 27/09/2026

Relatórios, Saúde do sistema, responsividade, tradução dos estados e
padronização da edição de Usuários, Políticas, Credenciais, Equipamentos
e Sites/POPs estão documentados em
[INTERFACE_RESPONSIVA_PT_BR.md](INTERFACE_RESPONSIVA_PT_BR.md).
A rodada consolidada acrescenta validação visual em nove resoluções e
219 testes Laravel aprovados e inclui o commit solicitado pelo usuário.
Os registros de etapas anteriores abaixo e acima mantêm seus resultados
históricos; os resultados atuais estão no documento consolidado.

## Validação e limites

A validação visual automatizada depende de navegador disponível no ambiente. O asset PNG de fundo do login é servido como fallback; uma versão WebP/AVIF só deve ser gerada quando houver codificador disponível, sem degradar a arte. O projeto não usa biblioteca JS nova.

Validação desta rodada: suíte Laravel completa, 83 testes Python, `php -l` dos arquivos PHP alterados, compilação Blade e `git diff --check` sem erros. Smoke HTTP de leitura no Nginx local: login, CSS e fundo PNG retornaram 200. O fundo atual tem 1.472.019 bytes; não havia codificador WebP/AVIF nem navegador automatizado neste ambiente, então a confirmação visual em 1920, 1366, 1280, tablet e mobile continua manual na homologação.

## Checklist de UI — Sites / POPs, 30/09/2026

- [x] A ação **Editar** abre o mesmo layout modal usado em **Novo Site / POP**, reaproveitando o formulário de nome, código, localização, descrição e estado.
- [x] A edição preenche os valores do Site / POP selecionado, envia `PUT` e retorna à lista na mesma página da paginação após salvar ou cancelar.
- [x] Erros de validação reabrem a edição com os valores enviados; o modal de cadastro não abre junto.
- [x] Cada rota renderiza apenas seu modal, evitando IDs de campos repetidos e foco no formulário errado.
- [x] O formulário de cadastro recebe `site=null` explicitamente, para não herdar o último registro renderizado na tabela.
- [x] `SiteTest`: 11 testes e 52 asserções aprovados, incluindo retorno ao modal após erro de validação; `git diff --check` passou.
- [x] Área de Sites / POPs validada pelo operador em 30/09/2026.

O Pint foi executado para `SiteController.php` e `SiteTest.php`, mas não conseguiu gravar porque o contêiner monta `app/` como somente leitura. O teste PHP e a compilação dos templates passaram.

## Checklist de UI — Equipamentos, 30/09/2026

- [x] A rota de edição abre os dados cadastrais no mesmo modal, formulário em duas colunas e rodapé do cadastro de novo equipamento. **Cancelar**, X, clique no fundo e Escape voltam à lista de equipamentos. O rodapé mantém apenas Cancelar e Salvar alterações.
- [x] O menu **Ações** da lista abre um mini modal FTP/SSH por equipamento. Equipamentos compatíveis têm acesso ao assistente FTP; os demais não recebem essa opção. Os dados da chave SSH e a ação de confiança ficam no próprio mini modal quando SSH se aplica.
- [x] **Tipo** permite escolher OLT, Switch, Roteador ou Firewall. A distinção visual preserva `platform=olt` ou `platform=network`, usados pelas regras de backup; Firewall usa `platform=network`. Equipamentos antigos de rede sem classificação podem ser classificados na próxima edição.
- [x] **Função** é um campo de texto livre, sem seta ou lista de sugestões. Exemplos: BGP, BNG, Core, Firewall, CGNAT e Acesso. Ela é independente de Tipo: NE8000 M8 pode ser Roteador/BGP e NE8000 M4, Roteador/BNG.
- [x] O campo Hostname técnico saiu dos formulários porque Nome do equipamento já fornece a identificação. Valores antigos são preservados quando o formulário é salvo, e a conexão continua usando o IP de gerenciamento.
- [x] A lista mostra Tipo e Função quando preenchidos e inclui ambos na busca local.
- [x] `DeviceTest`: 18 testes e 270 asserções aprovados; a migração aditiva foi exercitada no banco de testes e aplicada no banco do aplicativo como única migração pendente.
- [x] Área de Equipamentos validada pelo operador em 30/09/2026.

O Pint foi executado para os arquivos PHP desta rodada, mas o contêiner monta `app/` como somente leitura e impediu a gravação do formatador. `git diff --check` passou.

Complemento do catálogo de Fabricante: **A10 Networks** e **Hillstone** foram adicionados às opções do cadastro e da edição de Equipamentos. A validação e a normalização dos nomes usam o mesmo catálogo; a adição não altera quais fabricantes possuem drivers de backup disponíveis.

Validação do complemento: `DeviceTest` passou com 18 testes e 286 asserções; Pint passou para `Device.php` e `DeviceTest.php`.

Complemento de Tipo: **Firewall** foi adicionado ao cadastro, à edição e à identificação na lista. A validação aceita o novo valor, salvo com `platform=network`. `DeviceTest` passou com 18 testes e 290 asserções; Pint passou para os arquivos PHP alterados.

Correção do cancelamento na edição: o modal de `/devices/{id}/edit` agora retorna à listagem pelo botão **Cancelar**, pelo X, por Escape ou por clique no fundo. O botão que fechava o modal e expunha a página de configurações foi removido. Os fluxos específicos de FTP (`olt_wizard=1`) e de aprovação da chave SSH (`#device-ssh-security`) continuam abrindo a seção correspondente sem o modal cadastral. `DeviceTest` passou com 18 testes e 293 asserções; Pint e `git diff --check` passaram.

O acesso direto a FTP e SSH voltou ao menu **Ações** da lista como mini modal. A VSOL V1600GT mostra apenas SSH; Huawei compatível abre o assistente FTP. Fechar ou concluir o assistente retorna à lista, e a confiança de chave enviada pelo mini modal também retorna à lista com mensagem de sucesso. `DeviceTest`, `EngineJobTest` e `RbacTest` passaram com 47 testes e 538 asserções; Pint e `git diff --check` passaram. O operador confirmou a validação da área de Equipamentos em 30/09/2026.

Padronização visual posterior: o mini modal de **FTP / SSH** reutiliza `form-create-modal`, incluindo cabeçalho, corpo com rolagem, rodapé e botão de fechar no mesmo padrão de **Novo equipamento** e **Editar equipamento**. O assistente FTP preserva suas seis etapas, mas agora usa a mesma paleta azul, moldura, cabeçalho, botão X, rodapé e campos de entrada; os botões auxiliares da senha receberam aparência secundária. `DeviceTest` passou com 19 testes e 311 asserções; Pint e `git diff --check` passaram. O operador validou a área de Equipamentos em 30/09/2026.

## Checklist de UI — Credenciais, 30/09/2026

- [x] **Editar** abre o mesmo modal e formulário usados em **Nova Credencial**, sobre a lista de credenciais.
- [x] Os campos recebem os dados da credencial selecionada. O segredo permanece vazio e, se não for alterado, o valor armazenado é preservado.
- [x] **Cancelar**, X, clique fora do modal e Escape retornam à lista; a página da paginação é mantida.
- [x] Erros de validação reabrem o modal de edição com os valores enviados, sem abrir o modal de cadastro.
- [x] O cadastro recebe `credential=null` explicitamente, para não herdar o último item da tabela.
- [x] `CredentialTest`: 10 testes e 78 asserções aprovados, incluindo a renderização e a reabertura da edição após erro de validação.
- [x] Área de Credenciais testada e aprovada pelo operador na URL de produção em 30/09/2026.

### SSH em Credenciais — complemento de 30/09/2026

- [x] O formulário de cadastro e edição mostra **Testar conexão SSH** somente quando o tipo é SSH. O teste usa equipamento, usuário, porta e senha digitados; na edição, o segredo salvo é usado quando o campo está vazio. O teste não salva dados nem executa backup.
- [x] O resultado informa sucesso ou falha de conexão/autenticação sem devolver a senha ao navegador. O motor Python existente executa a verificação; o aplicativo recebe apenas o resultado e a chave pública observada.
- [x] A ação **Segurança SSH** e a aprovação da chave observada saíram de Equipamentos e foram para **Ações** da credencial SSH. A ação FTP permanece em Equipamentos apenas para dispositivos compatíveis.
- [x] Uma chave desconhecida ou alterada exige aprovação explícita antes do teste de autenticação, preservando a verificação de identidade do equipamento.
- [x] O aplicativo recebeu acesso de leitura ao código do motor em `compose.yml`; o contêiner `app` foi recriado e voltou a ficar ativo. A execução local do módulo pelo contêiner retornou o erro esperado para um fabricante fictício, sem abrir conexão.
- [x] Fluxo SSH de Credenciais incluído na validação e aprovação informadas pelo operador em 30/09/2026.

Validação direcionada: `CredentialTest` e `DeviceTest` passaram com 31 testes e 366 asserções; o teste de instruções do assistente FTP passou com 39 asserções. A compilação Blade e `git diff --check` passaram. Uma rodada ampliada de 95 testes teve cinco falhas em cenários FTP que dependem de fixtures não montadas ou de estado de arquivo no contêiner de teste, além de uma expectativa anterior de elegibilidade FTP; as áreas alteradas passaram. O código PHP foi formatado com Pint em contêiner temporário gravável. A conferência HTTP local não respondeu a partir do sandbox, apesar de os serviços `app` e `nginx` estarem ativos no Compose.

Complemento visual: **Ações → Segurança SSH** agora usa a mesma estrutura de modal de **Nova Credencial** e **Editar Credencial**: cabeçalho, corpo com grade de campos somente leitura, estado da chave destacado e rodapé fixo. **Confiar nesta chave observada** aparece como ação principal no rodapé apenas quando há uma chave pendente e permissão para aprová-la. O X, **Fechar**, clique fora e Escape fecham o modal.

O operador aprovou o novo layout de **Ações → Segurança SSH** em 30/09/2026 e, após concluir os demais ajustes, informou que toda a área de Credenciais foi testada e aprovada.

Complemento do campo **Senha / Segredo**: cadastro e edição exibem um botão de olho no campo. Quando há texto digitado, ele alterna entre mostrar e ocultar esse texto. Na edição com o campo vazio, o clique consulta a senha salva mediante permissão `credentials.manage` e a mostra no próprio campo. A senha não é incluída no HTML inicial. Se o operador apenas visualizar e salvar sem editar, o campo é limpo antes do envio e a senha armazenada permanece igual; se alterar o texto, a nova senha é enviada. A resposta não é armazenada em cache e a consulta gera o evento `credential.secret_revealed` sem registrar o valor. Ao ocultar ou fechar o modal, o valor visualizado sem alteração é removido do campo. `CredentialTest`: 14 testes e 109 asserções aprovados, inclusive autorização, resposta sem cache e auditoria. O operador confirmou o campo em 30/09/2026.

Simplificação dos tipos: **Nova Credencial** e edição oferecem somente **SSH** e **Telnet**. SSH é o método usado pelas políticas e pelo motor de backup atual. Telnet permanece como cadastro de acesso para futuras OLTs FiberHome, mas não executa backup na V2 atual. Contas de backup FTP são gerenciadas na área FTP, sem credencial genérica; SFTP e API não possuem fluxo operacional nesta versão. Registros antigos de outros tipos continuam visíveis e podem ser editados sem troca forçada de tipo, mas novos cadastros FTP/SFTP/API são recusados. Não foi criada integração API para MikroTik, pois o fluxo SSH existente já atende o backup. A base consultada tinha sete credenciais, todas SSH. `CredentialTest` e `BackupPolicyTest`: 34 testes e 233 asserções aprovados.

A frase explicativa abaixo de **Tipo de acesso** foi removida a pedido do operador. Em 30/09/2026, ele informou que a área de Credenciais está integralmente testada e aprovada.

## Checklist de UI — FTP, 30/09/2026

- [x] O operador informou que testou a aba `/ftp` e aprovou a interface em 30/09/2026.
- [x] Após a aprovação da lista, o operador apontou que `/ftp/accounts/11` tinha um layout diferente, com fontes e elementos maiores. A tela de detalhes passou a usar o cabeçalho, a largura de conteúdo, as cores, a tipografia, os botões e os cards padrão do painel. Foram retirados os estilos que alteravam a barra lateral e o fundo apenas nessa página; os dados e as ações FTP foram preservados.
- [x] O card **Último recebimento** mostra somente a data e a hora; a linha com o nome do arquivo foi removida.
- [x] O operador confirmou que a tela de detalhes FTP está correta após esses ajustes.

## Checklist de UI — Status dos backups, 30/09/2026

- [x] A página `/backup-health` passou a usar o cabeçalho, os cards, a tabela, a paginação e o estado vazio do padrão atual do painel.
- [x] As contagens, os motivos de atenção e os links para o histórico de execuções continuam com os mesmos dados e rotas.
- [x] A view Blade compilou e o teste direcionado da página passou com 11 verificações.
- [x] O operador validou o novo layout de `/backup-health` e encerrou o item.

## Revisão de políticas para produção, 30/09/2026

- [x] O operador salvou **MikroTik Diário**. Consulta de leitura confirmou política ativa, coleta SSH, agendamento diário às 01:30, retenção de 30 dias e quantidade vazia. O horário anterior observado era 19:05. A limpeza automática por retenção da instância está desativada (`backup.retention_enabled=false`).
- [x] O gerenciamento de vínculos passou para **Equipamentos → Ações → Política de backup**. O modal mostra vínculos atuais, status, histórico e políticas compatíveis, pede credencial SSH quando aplicável e permite associar, ativar, desativar e remover apenas vínculos sem execuções. A página de edição da política mantém a lista de equipamentos para consulta e a ação de criar execução manual.
- [x] O formulário de política distingue **Política ativa** da limpeza automática por retenção e mostra o estado atual dessa limpeza na instância. Associar uma nova política não desativa vínculos existentes automaticamente; o modal avisa isso.
- [x] **Editar política** reutiliza o layout do modal de **Nova Política**, abre com os valores salvos e retorna à lista por Cancelar, X, clique fora ou Escape. A página da listagem é preservada ao editar e ao salvar.
- [x] Validação: `BackupPolicyTest` (21 testes, 132 asserções) e `DeviceTest` (18 testes, 267 asserções) passaram; Blade compilou e `git diff --check` não apontou erros.
- [x] O aviso de retenção foi reescrito em linguagem direta após o operador informar que não entendeu o texto anterior: exemplo de 30 dias, regra quando ambos os limites são usados e mensagem separada para o estado da limpeza automática. A descrição de **Política ativa** fala apenas do uso e agendamento da política.
- [x] O formulário agora sugere **30 dias** para backup diário e indica que **Retenção em quantidade** é opcional quando o prazo em dias já atende. A sugestão não sobrescreve políticas existentes.
- [x] O serviço `app` passou a receber as mesmas variáveis `BACKUP_RETENTION_ENABLED` e `BACKUP_RETENTION_TIME` do serviço `scheduler`. A variável de ativação serve como estado inicial na migração; depois disso, o controle global é salvo no banco. O horário continua vindo de `BACKUP_RETENTION_TIME` (padrão 04:30 no fuso da instância).

### Retenção em Configurações

- [x] Os limites continuam em cada política: **Retenção em dias** define a idade máxima e **Retenção em quantidade** limita o número de versões. Se ambos estiverem preenchidos, ultrapassar qualquer um torna o backup candidato. O último backup válido de cada vínculo é preservado.
- [x] A explicação detalhada e o controle global da limpeza foram para **Configurações → Retenção de backups**. No formulário da política ficou uma indicação curta do local de ativação. **Política ativa** controla o agendamento da coleta, não a limpeza.
- [x] O administrador pode gerar uma prévia que mostra candidatos, últimos backups protegidos, arquivos ausentes e anomalias, sem apagar arquivos. Para ligar a limpeza, é preciso confirmar uma prévia de até dez minutos; se o resultado mudar antes da confirmação, uma nova prévia é apresentada. Desligar tem efeito imediato.
- [x] O agendador executa diariamente no horário configurado e consulta o controle salvo no banco a cada execução. Enquanto estiver desligado, a execução agendada sai sem remover arquivos. O comando manual `backups:retention --apply` continua disponível para operação explícita.
- [x] A migração aditiva `2026_10_01_002207_add_retention_enabled_to_application_settings_table` foi aplicada como única migração pendente. O estado inicial preserva a configuração anterior e, nesta instância, permanece **desligado**. Mudanças de estado geram evento `backup_retention.setting_changed`.
- [x] `BackupRetentionTest`: 21 testes e 128 asserções aprovados, incluindo autorização, prévia, confirmação, alternância e respeito do agendador ao estado. O operador confirmou a nova seção de Configurações após a implementação.
- [x] `BackupPolicyTest` e `BackupSchedulerTest`: 34 testes e 194 asserções aprovados. Blade compilou, `git diff --check` passou, e a execução real de `backups:retention --scheduled` no contêiner agendador retornou `disabled`.

### Conferência posterior — MikroTik Diário

Consulta somente de leitura em 30/09/2026, às 21:34 (America/Sao_Paulo): política `MikroTik Diário` (ID 1) ativa, método SSH, artefato de configuração, agendamento diário às **03:00**, sem seleção de dia da semana, retenção de 30 dias e quantidade vazia. O vínculo com `MK-BORDA-01` está ativo; equipamento e credencial SSH também estão ativos. Há 19 execuções no vínculo, com a mais recente concluída com sucesso, e 12 artefatos disponíveis entre 22/09 e 30/09. A limpeza automática global estava **ligada** nessa consulta. O operador confirmou que alterou intencionalmente o horário de 01:30 para **03:00**. As execuções recentes observadas ainda foram agendadas para 19:05, antes dessa nova configuração; a primeira execução às 03:00 ainda não havia ocorrido no momento da consulta.

Checagem preventiva às 21:36: contêineres do agendador e motor ativos; banco, Redis, motor, drivers, tick do agendador, fila e armazenamento saudáveis. Não havia execução do MikroTik em andamento nem fila pendente. O estado geral de saúde mostrava alerta por taxa de sucesso de 50% nas últimas 24 horas entre todos os equipamentos, sem indicação de falha específica do `MK-BORDA-01`. A execução das 03:00 de 01/10/2026 ainda depende de observação após esse horário.

### Layout dos modais em Equipamentos → Ações

- [x] **Política de backup** usa o padrão visual de **Novo equipamento**: mesmo cabeçalho, largura, grade de identificação, campos e rodapé. Os vínculos usam linhas simples com status e ações; **Associar política** fica no rodapé. O ajuste foi revisto e aprovado pelo operador em 01/10.
- [x] **FTP** segue o mesmo padrão, com **Cancelar** e a ação principal **Abrir assistente FTP** no rodapé.
- [x] Na etapa inicial, `DeviceTest` e `BackupPolicyTest` tiveram 39 testes e 399 asserções aprovados. O operador aprovou a versão final do modal em 01/10; o resultado automatizado mais recente está em [CHANGES_2026-10-01.md](CHANGES_2026-10-01.md).

### Clareza do fluxo Huawei FTP

- [x] O operador tentou alterar a política compartilhada **Huawei FTP** para agendamento diário e recebeu a validação de que FTP Push não usa o scheduler. A política continua ativa em `ftp_push/config/manual`, com retenção de 30 dias; estava vinculada a duas OLTs Huawei e quatro equipamentos Huawei de rede, sendo um vínculo inativo.
- [x] No formulário, o campo passou a explicar **como o backup começa**. Para FTP, mostra **Envio pelo equipamento** e indisponibiliza as opções Diário e Semanal; o texto orienta configurar o envio automático em cada Huawei. Na lista de políticas, o início do backup FTP também aparece como **Envio pelo equipamento**.
- [x] O modo passivo do FTP trata da conexão de dados. O início do upload continua no equipamento. O Backup Manager recebe e valida o arquivo, registra a execução e aplica a retenção; ele não programa o envio FTP na OLT ou no roteador. O teste da OLT pelo assistente permanece manual.
- [x] A validação no servidor foi preservada para impedir agendamentos FTP inválidos mesmo sem JavaScript. Teste direcionado `HuaweiOltFtpTest::test_ftp_policy_accepts_manual_and_rejects_daily_and_weekly` passou com 16 asserções; Blade compilou.

### Auditoria de leitura após ajustes das políticas

O operador informou que ajustou todas as políticas. Consulta de leitura encontrou seis políticas: MikroTik Diário, Huawei Switch SSH, Huawei Roteador SSH, Huawei FTP, Teste de integração OLT FTP #4 e SSH OLT-VSOL. As políticas SSH diárias têm 30 dias de retenção; Huawei FTP está em recebimento pelo equipamento com 30 dias; a política de teste mantém quantidade 1. A limpeza global está ligada e configurada para 04:30 no fuso America/Sao_Paulo.

Pontos observados na consulta de 30/09, antes da revisão operacional de 01/10:

- `SWITCH-BASE` (modal `#device-policy-2`) tem **Huawei Switch SSH** e **Huawei Roteador SSH** ativos, ambos às 03:00. O vínculo de roteador (ID 15) não tem execuções; o vínculo de switch (ID 2) tem três execuções. Recomenda-se remover o vínculo de roteador e manter o de switch.
- `BNG-NE8000` tem os vínculos SSH e FTP desativados. Houve dois recebimentos FTP bem-sucedidos em 30/09, mas não há vínculo ativo para novos backups.
- `OLT-huawei-base` tem **Huawei FTP** e **Teste de integração OLT FTP #4** ativos. O recebimento FTP escolhe o primeiro vínculo compatível por ID, atualmente o Huawei FTP; a política de teste permanece redundante e tem histórico. Recomenda-se desativar o vínculo de teste, preservando as execuções antigas.

Situação posterior em 01/10: `BNG-NE8000` passou a ter SSH e FTP ativos, como métodos distintos; o vínculo e a política de teste da `OLT-huawei-base` foram arquivados sem apagar suas 11 execuções; `SWITCH-BASE` continua com os dois vínculos SSH ativos até revisão específica. O sistema impede novas duplicações ativas do mesmo método.

### Conclusão visível ao salvar vínculos

O primeiro ajuste fechava o modal após salvar porque o aviso de sucesso aparecia atrás dele. Após nova revisão do operador, **ativar/desativar e remover** mantêm aberto o modal do mesmo equipamento e exibem a confirmação nele; associar uma nova política ainda retorna à lista. Erros preservam o contexto. Ao fechar manualmente um modal aberto por fragmento, o fragmento é limpo da URL. Remover um vínculo com histórico arquiva esse vínculo e preserva as execuções antigas; uma execução ainda pendente ou em andamento impede a remoção.

Quando um equipamento já tem política ativa, a seção de nova associação fica recolhida em **Adicionar outra política**; o botão de associar aparece apenas ao expandi-la. Sem políticas adicionais disponíveis, a seção não aparece. O operador aprovou o modal final em 01/10. As validações atuais estão consolidadas em [CHANGES_2026-10-01.md](CHANGES_2026-10-01.md).

O operador respondeu “tudo ok” após a correção do modal. Nova consulta em 30/09/2026 às 21:55 (America/Sao_Paulo) confirmou que a primeira execução do **MikroTik Diário** às 03:00 ainda não chegou: a última execução continuava sendo a #105, agendada no horário anterior e concluída com sucesso. Política e vínculo permaneciam ativos; o scheduler seguia registrado. A verificação do resultado de 01/10 às 03:00 continua pendente até a execução ocorrer.

### Usuários — edição no padrão do cadastro

Em `/users/{id}/edit`, **Editar usuário** agora abre sobre a lista em um modal com o mesmo layout e campos do cadastro. Nome, e-mail e papel vêm preenchidos; salvar retorna à lista com confirmação. Cancelar, fechar, clicar fora ou pressionar Escape retornam à lista. **Redefinir senha** abre um modal próprio, com confirmação e as mesmas regras de validação existentes. A URL direta de edição também abre o modal; erros mantêm o formulário correspondente aberto. `UserManagementTest`: 17 testes e 61 asserções aprovados; Blade compilou.

### Configurações — aproveitamento da central antiga

A V1 reunia preferências e atalhos na área de Configurações. Na V2, fuso e retenção já têm controle próprio, enquanto Políticas, FTP, Usuários, Saúde do sistema e Auditoria já possuem telas funcionais. A página `/settings` ganhou uma seção **Administração** com acesso direto a essas telas, respeitando as permissões existentes. A importação/exportação de configuração da V1 requer um formato próprio para os modelos da V2, com conciliação de vínculos e exclusão de segredos; ficou para trabalho separado. O controle de HTTPS/Certbot da V1 também não foi transportado para o ambiente Docker da V2.

### Credenciais — autorização da chave SSH e retorno ao teste

Quando **Testar conexão SSH** encontra uma chave ainda não confiada ou alterada, a mensagem oferece **Clique para autorizar a chave SSH**. O link abre **Segurança SSH** na própria credencial, já com o fingerprint observado para conferência. A aprovação continua sendo uma ação explícita do operador; o sistema não confia automaticamente na chave.

Após **Confiar nesta chave observada**, o sistema confirma a conclusão e retorna ao modal de edição da credencial, com **Testar conexão SSH** em foco. O teste não é disparado automaticamente. `CredentialTest`: 18 testes e 146 asserções aprovados; Blade compilou. O operador confirmou o fluxo em 01/10/2026.
