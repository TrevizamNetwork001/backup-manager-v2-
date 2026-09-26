# Modernização da UI — identidade do Backup Manager

O estado implementado em 26/09/2026 está registrado em [CHANGES_2026-09-26.md](CHANGES_2026-09-26.md), incluindo Dashboard, FTP, listas, Configurações, Saúde do sistema, paginação e Auditoria. As seções abaixo registram a direção visual e a fundação original do design system.

## Direção do produto

**Backup Manager — infraestrutura técnica, confiável, precisa e operacional.**

A interface é dark-first, técnica, moderna, compacta, profissional e limpa. Deve apresentar muitos dados sem parecer apertada, com pouca ornamentação, hierarquia forte, surfaces em camadas e estados operacionais claros. Equipamentos, backups, integridade e operação são o centro da linguagem visual. A UI deve transmitir confiabilidade, automação, infraestrutura, observabilidade e segurança.

Evitar aparência genérica de template, visual gamer ou exageradamente futurista, neon excessivo, glassmorphism, gradientes decorativos e cards grandes sem necessidade operacional.

## Originalidade

Não copiar visualmente IRCENTER, Calendly, Grafana, Ubiquiti, Cloudflare, GitHub, temas Bootstrap ou qualquer dashboard pronto. Isso inclui paleta exata, sidebar, cards, layout de dashboard, ícones, espaçamentos, componentes e microinterações. A interface precisa parecer um produto próprio quando vista isoladamente.

Referências externas servem apenas para estudar técnicas de hierarquia, densidade, responsividade, Grid/Flexbox, acessibilidade, componentização, navegação, feedback, formulários e visualização de dados. Quando uma referência for consultada, registrar somente: **técnica observada → problema que resolve → adaptação original para o Backup Manager**. Nunca usar uma referência como especificação de cópia de um componente.

## Design system próprio

Usar tokens semânticos centrais: `--color-bg`, `--color-surface`, `--color-surface-elevated`, `--color-surface-sunken`, `--color-border`, `--color-border-strong`, `--color-text`, `--color-text-secondary`, `--color-text-muted`, `--color-primary`, `--color-primary-hover`, `--color-focus`, `--color-success`, `--color-warning`, `--color-danger`, `--color-info` e `--color-neutral`. As variantes de fundo e borda dos estados também devem vir de tokens. A fundação inicial está em `app/public/assets/app.css`.

A escolha final de cores deve nascer da interface existente e ser validada visualmente e por contraste. Navy escuro, tons frios e uma cor de destaque técnica são possibilidades, sem reproduzir diretamente a paleta de outro produto. Dark é a identidade principal. Organizar os tokens para um futuro override como `html[data-theme="light"]`, sem implementar light nesta fase.

Manter padrões próprios e coerentes para cards, badges, botões, inputs, tabelas, cabeçalhos, toolbars, modais, alertas, estados vazios e status. A densidade deve favorecer leitura e decisão operacional: valor e estado primeiro; contexto e ações em níveis secundários.

## Ícones e arquitetura frontend

Construir um conjunto pequeno de ícones SVG inline via Blade, desenhado para as necessidades do produto. Usar o mesmo `viewBox`, espessura, tamanho base e regras de `stroke`/`fill` controladas por CSS. Não copiar a linguagem visual ou o conjunto de ícones de outro produto.

Preservar Laravel Blade com renderização no servidor, HTML semântico, CSS customizado, CSS Grid, Flexbox, variáveis CSS, JavaScript vanilla e progressive enhancement. Não introduzir React, Vue, SPA, biblioteca visual pesada ou template pronto sem necessidade arquitetural demonstrada.

## Revisão obrigatória ao fim de cada fase

Além dos testes técnicos e visuais aplicáveis, reportar:

1. Padrões próprios do Backup Manager criados ou consolidados.
2. Estilos antigos substituídos e compatibilidade mantida.
3. Inconsistências e riscos ainda presentes.
4. Confirmação de que nenhuma biblioteca visual externa ou template foi copiado.

As próximas fases devem migrar as telas gradualmente, preservando comportamento funcional e validando desktop, notebook, tablet, mobile, teclado e contraste.

## Fundação UI-1 adotada

O CSS publicado mantém a paleta dark existente como ponto de partida e acrescenta `--color-surface-soft`, `--color-border-soft` e `--color-text-inverse` às camadas semânticas. Os aliases antigos continuam disponíveis enquanto as views forem migradas. A escala nova usa espaçamentos de 4, 8, 12, 16, 24, 32 e 40px; raios de 8, 12 e 16px; títulos de página/seção/card de 24/18/16px; corpo/label/metadados de 14/13/12px. Os novos componentes consomem tokens; light permanece apenas uma possibilidade de override futuro.

Novos layouts podem combinar `.page-container` ou `.page-container--data` com `.stack`, `.cluster` e `.grid`. As larguras preferenciais para colapso são 1024, 768 e 480px. A base também fornece botões, formulários, links, badges, alertas, estados vazios, tabelas e modais, sem substituir ainda os blocos específicos das páginas.

O componente Blade anônimo `<x-icon name="…" />` usa `viewBox` 24×24, traço uniforme e `currentColor`, com tamanhos `sm`, `md` e `lg`. Os nomes iniciais são `add`, `close`, `device`, `backup`, `shield`, `check` e `alert`. Ícones decorativos recebem `aria-hidden`; para um ícone informativo, passar `label="…"`. Esse conjunto é próprio e pequeno, sem importação de biblioteca externa. A conversão dos caracteres e SVGs antigos fica para as fases das respectivas telas.
