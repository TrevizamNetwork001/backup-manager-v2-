# UI-POLISH-2 — revisão visual final

Revisão de 27/09/2026, restrita à apresentação. Backend, schema, rotas e fluxos não foram alterados nesta fase. As alterações anteriores do working tree foram preservadas e separadas do commit.

## Bugs corrigidos

- Saúde dos backups: a grade excedia a largura do mobile; agora respeita o container e apresenta registros com rótulos em telas estreitas.
- Menus Ações: opções cortadas à esquerda no mobile; alinhamento corrigido e abertura acima/abaixo conforme o espaço disponível. O filtro de período usa o mesmo posicionamento vertical.
- Modais FTP e de artefatos: cabeçalho e botões permanecem visíveis; somente o corpo rola. Altura útil desconta as bordas, inclusive nos modais de cadastro existentes.
- Sidebar: em notebooks, o rodapé ficava abaixo da área visível durante a rolagem. A posição sticky considera a altura real da navegação, sem criar scrollbar interno.
- Dashboard em tablet: indicadores ultrapassavam os cartões e a legenda se sobrepunha ao círculo. Indicadores passam a duas colunas e a legenda fica abaixo do círculo.
- Relatórios: links dos cartões herdavam a cor padrão do navegador e sublinhavam a descrição. Agora usam cores do tema e destaque no hover.
- Políticas: o campo chamado `method` interrompia o JavaScript compartilhado de formulários. O script lê o atributo do formulário.
- No detalhe de execução previamente modificado, foram restaurados o comando FTP e os textos operacionais cobertos pela suíte. A asserção de metadados FTP foi adaptada para verificar conteúdo e ordem sem depender das antigas tags `strong`.

## Telas e responsividade

Login, dashboard, Sites/POPs, Equipamentos, Credenciais, FTP, Saúde dos backups, Políticas, Execuções, Artefatos, Relatórios, Saúde do sistema, Configurações, Auditoria e Usuários. Incluídos os cinco relatórios específicos, cadastros/edições, detalhes, listas vazias, filtros sem resultados, menus, modais, badges, paginação, teclado e foco.

Chromium: **1920×1080, 1366×768, 1280×720, 768×1024, 390×844 e 320×568**. As 48 respostas Laravel foram geradas com SQLite em memória e dados sintéticos, incluindo nomes longos e paginação; nenhuma fixture foi criada no banco real. A revisão foi feita com capturas e medições, com nova conferência dos pontos corrigidos. Não houve overflow horizontal da página nem alteração das dimensões ao abrir Ações na conferência final. Tabelas que precisam de rolagem mantêm o scroll dentro da região da tabela.

Regressões: Equipamentos sem o separador `·` na célula de identificação e sem scrollbar horizontal; Ações sobreposto ao conteúdo; login com foco único no wrapper; navegação e modais acessíveis pelo teclado.

## Background do login

WebP **lossless**, com fallback PNG por `image-set` e pela declaração original de background para navegadores antigos. Mantidos **1672×941** e os mesmos pixels RGB.

| Asset | Bytes |
| --- | ---: |
| PNG preservado | 1.472.019 |
| WebP | 1.053.438 |
| Redução | **28,4%** |

Comparação de pixels e capturas do login comprova aparência idêntica. Nenhuma dependência foi adicionada à aplicação.

## Validação

- Laravel completo: **336 testes, 2.044 asserções**, aprovados, incluindo integração real Laravel/Python. Fixtures do engine disponibilizadas temporariamente no contêiner de teste.
- Python completo: **83 testes do engine + 16 testes FTP**, aprovados; integração sintética PureDB/Pure-FTPd aprovada em contêiner efêmero com o código atual do checkout.
- `php -l`: **129 arquivos PHP**, sem erros; compilação Blade aprovada e cache de views limpo.
- `git diff --check`: sem erros.
- Staging local `:8081`: 14 páginas autenticadas × seis resoluções, HTTP 200; login, CSS, WebP e fallback PNG também HTTP 200. A sessão temporária da revisão foi removida.
- Saúde do staging: banco, Redis, engine, drivers, fila, storage e FTP saudáveis; sem checks críticos ou em atenção. Estado geral **unknown**, pois worker está ocioso e scheduler/retenção não possuem histórico. Isso limita a confirmação operacional para RELEASE-1; nenhum serviço foi modificado para produzir histórico artificial.

Commit local solicitado: `style: finaliza polimento visual do backup manager`. Sem push. Alterações anteriores permanecem no working tree, inclusive as mudanças preexistentes na tela de execução cujos textos foram restaurados; o commit desta fase não inclui controllers, rotas ou scripts operacionais.
