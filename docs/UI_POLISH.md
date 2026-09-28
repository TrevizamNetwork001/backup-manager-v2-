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
- Execuções: tabela operacional mostra hora, equipamento, política, método, status, duração, tentativa e erro. O detalhe ainda preserva timestamps e contexto completos.
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

O painel **Status dos equipamentos** mantém o círculo e as contagens de **Ativos** e **Inativos**, baseados no cadastro (`is_active`). Alertas e estados críticos dos backups continuam disponíveis na página dedicada **Status dos backups**.

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
