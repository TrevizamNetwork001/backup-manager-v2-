<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurar acesso — Backup Manager</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ filemtime(public_path('assets/app.css')) }}">
</head>
<body class="login-body">
<main class="login-main">
    <div class="initial-setup-shell">
        <section class="login-card" aria-labelledby="initial-setup-title">
            <header class="login-heading">
                <p>PRIMEIRO ACESSO</p>
                <h1 id="initial-setup-title">Configure seu acesso</h1>
                <div>Cadastre seu e-mail e uma senha nova para continuar.</div>
            </header>
            <form method="POST" action="{{ route('initial-setup.update') }}">
                @csrf
                <div class="login-field">
                    <label for="email">Novo e-mail</label>
                    <div class="login-input-wrap"><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus></div>
                    @error('email') <span class="login-field-error">{{ $message }}</span> @enderror
                </div>
                <div class="login-field">
                    <label for="password">Nova senha</label>
                    <div class="login-input-wrap"><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div>
                    @error('password') <span class="login-field-error">{{ $message }}</span> @enderror
                </div>
                <div class="login-field">
                    <label for="password_confirmation">Confirme a nova senha</label>
                    <div class="login-input-wrap"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div>
                </div>
                <button type="submit" class="login-submit">Salvar e entrar novamente</button>
            </form>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="initial-setup-logout">Sair</button>
            </form>
        </section>
    </div>
</main>
</body>
</html>
