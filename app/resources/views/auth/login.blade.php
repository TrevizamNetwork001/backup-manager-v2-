<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar — Backup Manager</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ filemtime(public_path('assets/app.css')) }}">
</head>
<body class="login-body">
<svg class="login-icons" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="login-icon-database" viewBox="0 0 40 40"><ellipse cx="20" cy="8" rx="13" ry="5"/><path d="M7 8v24c0 2.8 5.8 5 13 5s13-2.2 13-5V8M7 16c0 2.8 5.8 5 13 5s13-2.2 13-5M7 24c0 2.8 5.8 5 13 5s13-2.2 13-5"/></symbol>
    <symbol id="login-icon-shield" viewBox="0 0 40 44"><path d="M20 2 36 8v11c0 11-6.7 18.3-16 23C10.7 37.3 4 30 4 19V8L20 2Z"/><path d="m13 22 5 5 10-11"/></symbol>
    <symbol id="login-icon-server" viewBox="0 0 40 40"><rect x="3" y="4" width="34" height="14" rx="2"/><rect x="3" y="22" width="34" height="14" rx="2"/><path d="M10 11h1m5 0h1m-7 18h1m5 0h1m8-18h5m-5 18h5"/></symbol>
    <symbol id="login-icon-user" viewBox="0 0 32 32"><circle cx="16" cy="10" r="6"/><path d="M4.5 28v-2c0-6.2 5-10 11.5-10s11.5 3.8 11.5 10v2"/></symbol>
    <symbol id="login-icon-lock" viewBox="0 0 32 32"><rect x="6" y="13" width="20" height="16" rx="2"/><path d="M10 13V9a6 6 0 0 1 12 0v4m-6 7v4"/></symbol>
    <symbol id="login-icon-eye" viewBox="0 0 32 32"><path d="M2 16s5-8 14-8 14 8 14 8-5 8-14 8S2 16 2 16Z"/><circle cx="16" cy="16" r="4"/></symbol>
    <symbol id="login-icon-eye-off" viewBox="0 0 32 32"><path d="M2 16s5-8 14-8 14 8 14 8-5 8-14 8S2 16 2 16Z"/><path d="m4 4 24 24M13 13a4 4 0 0 0 6 6"/></symbol>
    <symbol id="login-icon-enter" viewBox="0 0 32 32"><path d="M17 4h9a2 2 0 0 1 2 2v20a2 2 0 0 1-2 2h-9M4 16h18m-7-7 7 7-7 7"/></symbol>
</svg>
<main class="login-main">
    <div class="login-shell">
        <section class="login-intro" aria-label="Backup Manager">
            <div class="login-brand">
                <div class="login-brand__mark" aria-hidden="true">
                    <svg viewBox="0 0 84 98">
                        <path class="login-brand__shield" d="M42 3 80 17v27c0 26-15 43-38 51C19 87 4 70 4 44V17L42 3Z"/>
                        <g class="login-brand__database">
                            <ellipse cx="42" cy="32" rx="18" ry="8"/>
                            <path d="M24 38C24 46 60 46 60 38V46C60 54 24 54 24 46Z"/>
                            <path d="M24 50C24 58 60 58 60 50V58C60 66 24 66 24 58Z"/>
                        </g>
                    </svg>
                </div>
                <div class="login-brand__type"><div class="login-brand__name">Backup <span>Manager</span></div><div class="login-brand__subtitle">INFRASTRUCTURE BACKUP</div></div>
            </div>
            <div class="login-intro__copy"><h1>Proteção e controle<br>para a continuidade<br>do seu negócio.</h1><p>Gerencie seus backups, equipamentos e políticas<br class="login-desktop-break"> de forma centralizada, segura e confiável.</p></div>
            <div class="login-features" aria-label="Benefícios">
                <div class="login-feature"><svg aria-hidden="true"><use href="#login-icon-database"/></svg><span>Backups<br>centralizados</span></div>
                <div class="login-feature"><svg aria-hidden="true"><use href="#login-icon-shield"/></svg><span>Segurança<br>e integridade</span></div>
                <div class="login-feature"><svg aria-hidden="true"><use href="#login-icon-server"/></svg><span>Infraestrutura<br>sob controle</span></div>
            </div>
        </section>
        <section class="login-card" aria-labelledby="login-title">
            <header class="login-heading"><p>ACESSO AO SISTEMA</p><h2 id="login-title">Backup <span>Manager</span></h2><div>INFRASTRUCTURE BACKUP</div></header>
            <form method="POST" action="{{ route('login.store') }}">
                @csrf
                <div class="login-field">
                    <label for="email">Usuário ou e-mail</label>
                    <div class="login-input-wrap"><svg aria-hidden="true"><use href="#login-icon-user"/></svg><input id="email" name="email" type="text" value="{{ old('email') }}" placeholder="Seu usuário ou e-mail" autocomplete="username" aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" @error('email') aria-describedby="login-email-error" @enderror required autofocus></div>
                    @error('email') <span class="login-field-error" id="login-email-error">{{ $message }}</span> @enderror
                </div>
                <div class="login-field">
                    <label for="password">Senha</label>
                    <div class="login-input-wrap"><svg aria-hidden="true"><use href="#login-icon-lock"/></svg><input id="password" name="password" type="password" placeholder="Sua senha" autocomplete="current-password" @error('password') aria-invalid="true" aria-describedby="login-password-error" @enderror required><button class="login-password-toggle" type="button" aria-label="Mostrar senha" aria-pressed="false" onclick="toggleLoginPassword(this)"><svg aria-hidden="true"><use href="#login-icon-eye-off"/></svg></button></div>
                    @error('password') <span class="login-field-error" id="login-password-error">{{ $message }}</span> @enderror
                </div>
                <div class="login-options">
                    <label class="login-remember"><input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}><span>Lembrar-me</span></label>
                </div>
                <button type="submit" class="login-submit"><svg aria-hidden="true"><use href="#login-icon-enter"/></svg>Entrar</button>
            </form>
        </section>
    </div>
</main>
<footer class="login-footer">
    <div class="login-footer__inner">
        <div class="login-footer__brand">
            <svg viewBox="0 0 44 44" aria-hidden="true"><path d="m24 15-8 12m13-10 5 14m-16 1 12 4"/><circle cx="27" cy="10" r="7"/><circle cx="11" cy="31" r="7"/><circle cx="36" cy="36" r="7"/></svg>
            <span>Produto da <strong>Trevizam Network</strong></span>
        </div>
        <p class="login-footer__tagline">Infraestrutura e conectividade para um futuro mais seguro.</p>
    </div>
</footer>
<script>
function toggleLoginPassword(button) {
    const field = document.getElementById('password');
    const visible = field.type === 'password';
    field.type = visible ? 'text' : 'password';
    button.setAttribute('aria-label', visible ? 'Ocultar senha' : 'Mostrar senha');
    button.setAttribute('aria-pressed', String(visible));
    button.querySelector('use').setAttribute('href', visible ? '#login-icon-eye' : '#login-icon-eye-off');
}
document.querySelector('.login-card form').addEventListener('submit', event => {
    const form = event.currentTarget;
    if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
    form.dataset.submitting = 'true';
    requestAnimationFrame(() => {
        const button = form.querySelector('.login-submit');
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.textContent = 'Entrando…';
    });
});
</script>
</body>
</html>
