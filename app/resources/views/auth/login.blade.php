<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Entrar — Backup Manager</title>

    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
</head>

<body class="login-body">

<div class="login-shell">

    <div class="login-brand">
        <div class="brand-mark large">BM</div>

        <div>
            <strong>Backup Manager</strong>
            <span>Infrastructure Backup Platform</span>
        </div>
    </div>

    <div class="login-card">

        <div class="login-heading">
            <h1>Bem-vindo</h1>
            <p>Acesse o painel de gerenciamento de backups.</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}">
            @csrf

            <div class="field">
                <label for="email">E-mail</label>

                <input
                    id="email"
                    name="email"
                    type="email"
                    value="{{ old('email') }}"
                    autocomplete="username"
                    required
                    autofocus
                >

                @error('email')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="password">Senha</label>

                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <label class="remember">
                <input type="checkbox" name="remember" value="1">
                Manter sessão
            </label>

            <button type="submit" class="primary-button">
                Entrar
            </button>
        </form>

    </div>

    <div class="login-footer">
        Backup Manager V2
    </div>

</div>

</body>
</html>
