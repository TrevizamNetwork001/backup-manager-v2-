# Tela de login — implementação de 27/09/2026

Este documento registra as alterações feitas na tela `/login` a partir de `referencia/loginv1.png` (composição) e `referencia/fundo.png` (imagem de fundo). O escopo é a tela de acesso e o tratamento do identificador informado nela.

## Arquivos

| Arquivo | Papel |
| --- | --- |
| `referencia/loginv1.png` | Referência visual da tela completa. |
| `referencia/fundo.png` | Arte original do fundo. |
| `app/public/assets/login-background.png` | Cópia da arte servida pela aplicação. |
| `app/resources/views/auth/login.blade.php` | Marca, apresentação, formulário, rodapé, ícones SVG e orientação de recuperação. |
| `app/public/assets/app.css` | Aparência da tela, estados dos campos e regras responsivas. |
| `app/app/Http/Controllers/AuthController.php` | Autenticação por e-mail ou nome de usuário único. |
| `app/tests/Feature/AuthRateLimitTest.php` | Casos de nome único, nome ambíguo, porta pública e limitação de tentativas. |
| `docker/nginx/default.conf` | Preserva a porta pública recebida no `Host` ao encaminhar requisições PHP ao Laravel. |

## Interface e comportamento

- A tela usa a imagem dos servidores como fundo, com marca e mensagem à esquerda, benefícios abaixo, formulário em painel à direita e rodapé com a identificação Trevizam Network. Os ícones são SVG inline; nenhuma biblioteca visual foi adicionada.
- O formulário mantém CSRF, preenchimento anterior do identificador após erro, senha, opção **Lembrar-me** e envio para a rota `login.store`. O botão de olho alterna a visibilidade da senha e atualiza `aria-label` e `aria-pressed`.
- Em UI-POLISH-1, o controle **Esqueceu sua senha?** foi removido porque não há fluxo público de redefinição; a ação administrativa existente fica em `users/{user}/reset-password`.
- Após os ajustes visuais de 27/09/2026, o rodapé ocupa toda a largura da tela e apresenta, à esquerda, o ícone de conectividade, “Produto da Trevizam Network” e a frase “Infraestrutura e conectividade para um futuro mais seguro.”. Não há links de suporte, termos ou privacidade sem destino.
- A URL do CSS na view recebe a data de modificação do arquivo como parâmetro para evitar que o navegador mantenha uma versão antiga após ajustes visuais.

## Responsividade

| Faixa | Comportamento |
| --- | --- |
| Desktop amplo | Duas colunas; painel de até 565 px, conforme a composição da referência de 1672 × 941 px. |
| Largura entre 1101 e 1500 px **ou** altura de até 940 px, mantendo largura acima de 1100 px | Duas colunas mais compactas; painel de até 450 px, com altura determinada pelo conteúdo e espaçamentos reduzidos. Corrige a ocupação excessiva em notebooks. |
| Até 1100 px de largura | Colunas empilhadas. |
| Até 640 px de largura | Marca acima do formulário; texto de apresentação e benefícios decorativos são ocultados para dar prioridade ao acesso. |
| Até 1100 px de largura e até 600 px de altura | Espaçamentos mais compactos para telas baixas e orientação paisagem. |

O conteúdo continua rolável quando a altura disponível não comporta todo o formulário e o rodapé.

## Autenticação

O campo visual **Usuário ou e-mail** continua enviado com o nome `email` esperado pelo formulário existente. Se o valor tiver formato de e-mail, ele segue para a autenticação normal. Caso contrário, o controlador procura até dois usuários com o mesmo `name`: apenas um único resultado permite usar o e-mail daquela conta na tentativa de login. Nome inexistente ou ambíguo recebe a mesma mensagem genérica de falha. A verificação de conta ativa, a regeneração da sessão, o registro de auditoria e o `throttle:login` da rota permanecem no fluxo.

### Porta pública e destino após o login

A V2 é publicada em `https://backup.trevizamnetwork.com.br:8443`; a porta HTTPS padrão `443` do mesmo domínio serve o sistema antigo. O Nginx interno recebia `Host` com `:8443`, mas o parâmetro FastCGI `HTTP_HOST` fornecido pela configuração padrão descartava a porta. Por isso, o Laravel podia gerar a ação do formulário e o redirecionamento sem `:8443`, levando o navegador ao sistema antigo.

Em `docker/nginx/default.conf`, um `map` extrai apenas a porta numérica do `Host` recebido e a encaminha ao PHP como `HTTP_X_FORWARDED_PORT`. O Laravel já confia nos cabeçalhos do proxy configurado neste projeto. Assim, `route('login.store')`, `route('dashboard')` e os demais links gerados mantêm a origem pública da V2. O fluxo continua usando `redirect()->intended(route('dashboard'))`, portanto uma URL protegida acessada antes do login ainda pode ser o destino após autenticar.

## Foco dos campos

A causa do contorno duplo era a combinação do destaque do wrapper `.login-input-wrap:focus-within` com regras globais de `:focus-visible` que também desenhavam um outline no input. O estado final concentra o destaque no wrapper: borda `#04c7fd` e sombra externa de 1 px com `rgba(4, 199, 253, .30)`. Nos inputs de usuário/e-mail e senha, os estados `:focus` e `:focus-visible` não acrescentam borda, outline ou sombra internos. Assim, o campo continua identificado visualmente ao receber foco por mouse ou teclado. O foco dos botões não foi alterado.

## Rodapé e símbolo da marca — ajustes de 27/09/2026

A faixa do rodapé vai de uma borda à outra. Uma regra antiga de `.login-body`, `place-items: center`, continuava centralizando os elementos mesmo depois da mudança para Flexbox. O ajuste define `align-items: stretch` no body e `width: 100%; align-self: stretch` no rodapé. O conteúdo interno mantém margens laterais e alinhamento à esquerda; até 1000 px, a frase institucional passa para uma nova linha.

O banco de dados dentro do escudo foi redesenhado em SVG com uma elipse e duas camadas preenchidas em ciano (`#06c6fa`). O fundo sólido do escudo (`#001629`) e os espaços entre as camadas melhoram a definição. As classes `.login-brand__shield` e `.login-brand__database` substituem os seletores por posição que misturavam preenchimento e contorno.

Esses ajustes estão no commit `387f0df` — `style: ajusta rodape e icone da tela de login`, restrito à view de login e às regras CSS correspondentes.

Validação dos ajustes: `ExampleTest` aprovado com **2 testes e 3 asserções**, compilação Blade concluída, página `/login` servida pelo Nginx local com o novo rodapé e `git diff --check` sem erros. Não houve captura automatizada em navegador.

## Verificações da implementação inicial

- `php artisan view:cache`: compilação das views concluída.
- Testes direcionados de autenticação e RBAC após a correção da porta: **15 testes, 113 asserções**, aprovados, incluindo nome único e nome ambíguo.
- `ExampleTest`, que acessa `/login`: **3 testes, 4 asserções**, aprovados.
- Regressão da porta pública: `AuthRateLimitTest` com **6 testes, 33 asserções**, aprovado; verifica a ação do formulário e o redirecionamento com `X-Forwarded-Port: 8443`.
- `nginx -t` dentro do contêiner V2: configuração válida. Após recarregar o serviço, a página real em `:8443/login` exibiu a ação `https://backup.trevizamnetwork.com.br:8443/login`.
- `git diff --check`: sem erros de formatação.
- O usuário confirmou que o ajuste do foco ficou bom. Não havia navegador automatizado disponível no ambiente para registrar uma captura ou medir visualmente a tela via ferramenta.

Os comandos Laravel precisaram usar `LOG_CHANNEL=stderr` e um `VIEW_COMPILED_PATH` em `/tmp` porque os diretórios de log e cache do checkout não permitiam escrita durante a verificação. O Composer não estava disponível e a rede não resolvia `getcomposer.org`; por isso, a preparação com Laravel Boost indicada em `app/AGENTS.md` não foi executada nessa rodada.
