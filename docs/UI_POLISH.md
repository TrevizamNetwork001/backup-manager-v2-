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
- Sites e Equipamentos: busca rápida na página atual, vazio de filtro e ações secundárias recolhidas. A busca não altera a paginação do servidor.
- Execuções: tabela operacional mostra hora, equipamento, política, método, status, duração, tentativa e erro. O detalhe ainda preserva timestamps e contexto completos.
- Artefatos e criação de usuário: mudanças encontradas no working tree foram preservadas e usadas como base. O detalhe oferece download somente ao perfil autorizado e quando o status é disponível; a rota ainda verifica o arquivo. Exclusão de artifact permanece apenas no detalhe, com preview e frase de confirmação.
- FTP: diagnóstico técnico da lista recolhido; finalidades e recebimento permanecem visíveis. O detalhe já recolhia chroot e PureDB.
- Saúde do sistema: cada check mostra o status imediatamente e a mensagem ao expandir. Os demais painéis continuam somente leitura.
- Relatórios, Auditoria, RBAC, Configurações e formulários: componentes e permissões existentes foram mantidos. Configurações só oferece Timezone porque as demais áreas não têm formulário nesta versão.

O V2 não possui rota de detalhe geral do equipamento; a edição reúne dados gerais e o fluxo Huawei/FTP. A fase visual não cria essa rota nem duplica a lógica de saúde para preencher a lista. Método, política, último backup e saúde por equipamento estão disponíveis no relatório de equipamentos e na página de saúde, com os filtros atuais.

## Validação e limites

A validação visual automatizada depende de navegador disponível no ambiente. O asset PNG de fundo do login é servido como fallback; uma versão WebP/AVIF só deve ser gerada quando houver codificador disponível, sem degradar a arte. O projeto não usa biblioteca JS nova.

Validação desta rodada: suíte Laravel completa, 83 testes Python, `php -l` dos arquivos PHP alterados, compilação Blade e `git diff --check` sem erros. Smoke HTTP de leitura no Nginx local: login, CSS e fundo PNG retornaram 200. O fundo atual tem 1.472.019 bytes; não havia codificador WebP/AVIF nem navegador automatizado neste ambiente, então a confirmação visual em 1920, 1366, 1280, tablet e mobile continua manual na homologação.
