# UI-POLISH-1 — inventário e convenções visuais

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
- Sites e Equipamentos: busca rápida na página atual, vazio de filtro e ações secundárias recolhidas. A lista de Equipamentos usa `DeviceBackupHealth::rows()` para método, política, último backup e saúde reais; inativos são marcados “Não avaliado”. Em tela ampla usa tabela ajustada à largura; abaixo de 1500 px usa cards sem rolagem horizontal, conforme feedback do operador. A busca não altera a paginação do servidor.
- Execuções: tabela operacional mostra hora, equipamento, política, método, status, duração, tentativa e erro. O detalhe ainda preserva timestamps e contexto completos.
- Artefatos e criação de usuário: mudanças encontradas no working tree foram preservadas e usadas como base. O detalhe oferece download somente ao perfil autorizado e quando o status é disponível; a rota ainda verifica o arquivo. Exclusão de artifact permanece apenas no detalhe, com preview e frase de confirmação.
- FTP: diagnóstico técnico da lista recolhido; finalidades e recebimento permanecem visíveis. O detalhe já recolhia chroot e PureDB.
- Saúde do sistema: cada check mostra o status imediatamente e a mensagem ao expandir. Os demais painéis continuam somente leitura.
- Relatórios, Auditoria, RBAC, Configurações e formulários: componentes e permissões existentes foram mantidos. Configurações só oferece Timezone porque as demais áreas não têm formulário nesta versão.

O V2 não possui rota de detalhe geral do equipamento; a edição reúne dados gerais e o fluxo Huawei/FTP. A fase visual não cria essa rota nem duplica a lógica de saúde. A lista usa a classificação existente; o relatório de equipamentos e a página de saúde oferecem filtros e mais contexto.

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

Na barra lateral, os ícones SVG foram ajustados à função de cada item: **Relatórios** usa `report` (documento com barras de gráfico); **Saúde do sistema**, `system-health` (monitor com sinal de atividade); **Usuários**, `users` (duas pessoas); **Políticas**, `policy` (prancheta com lista de regras); **Status dos backups**, `backup-status` (banco de dados com relógio); e **Execuções de backup**, `execution` (lista com símbolo de execução). Os desenhos foram adicionados ao componente `resources/views/components/icon.blade.php` e mantêm `viewBox="0 0 24 24"`, traço e cor do conjunto existente. As rotas e permissões dos itens permanecem no fluxo atual.

**Sites / POPs** usa `site-location`, um marcador de localização para representar os locais da infraestrutura.

**Credenciais** mantém o nome e passa a usar o ícone `key` já existente no componente, representando os dados de acesso.

**Artefatos** mantém o nome e usa `folder`, alinhando a representação dos arquivos armazenados ao ícone de pasta do cartão do dashboard.

O nome **Saúde dos backups** passou a ser **Status dos backups** na navegação, no título da página e no resumo correspondente em Relatórios; a rota continua `backup-health.index` e a classificação permanece em `DeviceBackupHealth`. O item **Execuções** passou a ser **Execuções de backup** na navegação, mantendo a rota `backup-executions.index`.

As duas tabelas recentes tiveram a coluna **Ação** e os links de três pontinhos removidos. **Últimas execuções** exibe Início, Equipamento, Tipo, Status e Duração, com cinco colunas e `colspan="5"` no estado vazio. **Recebimentos FTP recentes** também teve a coluna **Conta** removida para simplificar o resumo do dashboard: exibe apenas Data/Hora, Arquivo, Tamanho e Status, com quatro colunas e `colspan="4"` no estado vazio. As larguras foram redistribuídas entre as colunas restantes. O acesso à área FTP permanece em **Ver todas**, e a identificação da conta continua disponível nas telas de detalhe.

Na coluna **Arquivo** do resumo FTP, nomes longos são encurtados apenas antes da última extensão, mantendo finais como `.zip` ou `.bin` visíveis. O nome completo aparece no tooltip. Arquivos sem extensão e nomes ocultos como `.env` continuam exibidos como um único nome. As telas de detalhe mantêm a identificação da conta e o nome completo do arquivo.

Arquivos de interface alterados: `app/public/assets/app.css` e as views `dashboard.blade.php`, `layouts/app.blade.php`, `components/icon.blade.php`, `backup-health/index.blade.php` e `reports/index.blade.php`. A expectativa do novo nome da navegação foi atualizada em `DeviceBackupHealthTest.php`. A autenticação, as consultas e os dados apresentados permanecem no fluxo existente. Os ajustes do login desta mesma sessão estão descritos em [LOGIN_SCREEN.md](LOGIN_SCREEN.md#rodapé-e-símbolo-da-marca--ajustes-de-27092026).

Validação: `DeviceBackupHealthTest`, `DashboardTimezoneTest` e `ReportsAuthorizationTest` aprovados com **17 testes e 97 asserções** após ajustar os nomes e ícones. O teste `test_dashboard_chart_accepts_presets_and_historical_dates` passou com **1 teste e 10 asserções**, cobrindo filtros predefinidos, datas históricas e rejeição de períodos acima do limite. Compilação Blade concluída e `php artisan view:clear` executado no contêiner; `git diff --check` sem erros. O usuário conferiu a tela e aprovou o layout final do dashboard. Não houve medição ou captura automatizada em navegador. Os ajustes e sua documentação são registrados em commit local nesta etapa, sem push.

O painel **Status dos equipamentos** mantém o círculo e as contagens de **Ativos** e **Inativos**, baseados no cadastro (`is_active`). Alertas e estados críticos dos backups continuam disponíveis na página dedicada **Status dos backups**.

## Validação e limites

A validação visual automatizada depende de navegador disponível no ambiente. O asset PNG de fundo do login é servido como fallback; uma versão WebP/AVIF só deve ser gerada quando houver codificador disponível, sem degradar a arte. O projeto não usa biblioteca JS nova.

Validação desta rodada: suíte Laravel completa, 83 testes Python, `php -l` dos arquivos PHP alterados, compilação Blade e `git diff --check` sem erros. Smoke HTTP de leitura no Nginx local: login, CSS e fundo PNG retornaram 200. O fundo atual tem 1.472.019 bytes; não havia codificador WebP/AVIF nem navegador automatizado neste ambiente, então a confirmação visual em 1920, 1366, 1280, tablet e mobile continua manual na homologação.
