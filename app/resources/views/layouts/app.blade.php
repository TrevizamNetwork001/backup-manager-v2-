<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Backup Manager')</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ filemtime(public_path('assets/app.css')) }}">
</head>
<body class="app-body {{ request()->routeIs('dashboard') ? 'dashboard-body' : '' }}">
<a class="skip-link" href="#main-content">Ir para o conteúdo</a>
<div class="app-shell">

    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">BM</div>

            <div>
                <strong>Backup Manager</strong>
                <span>Backup de infraestrutura</span>
            </div>
        </div>

        <button type="button" class="sidebar-toggle" aria-controls="primary-navigation" aria-expanded="true">Menu</button>
        <nav class="sidebar-nav" id="primary-navigation" aria-label="Navegação principal">
            <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="nav-icon"><x-icon name="home" /></span>
                Dashboard
            </a>

            <div class="nav-section">Infraestrutura</div>

            @can('sites.view')
                <a
                    href="{{ route('sites.index') }}"
                    class="nav-link {{ request()->routeIs('sites.*') ? 'active' : '' }}"
                >
                    <span class="nav-icon"><x-icon name="site-location" /></span>
                    Sites / POPs
                </a>
            @endcan

            @can('devices.view')
                <a
                    href="{{ route('devices.index') }}"
                    class="nav-link {{ request()->routeIs('devices.*') ? 'active' : '' }}"
                >
                    <span class="nav-icon"><x-icon name="server" /></span>
                    Equipamentos
                </a>
            @endcan

            @can('credentials.view')
                <a href="{{ route('credentials.index') }}" class="nav-link {{ request()->routeIs('credentials.*') ? 'active' : '' }}">
                    <span class="nav-icon"><x-icon name="key" /></span>
                    Credenciais
                </a>
            @endcan

            @can('ftp.view')
                <a href="{{ route('ftp.index') }}" class="nav-link {{ request()->routeIs('ftp.*') ? 'active' : '' }}">
                    <span class="nav-icon"><x-icon name="ftp" /></span>
                    FTP
                </a>
            @endcan

            <div class="nav-section">Backup</div>

            @can('dashboard.view')
                <a href="{{ route('backup-health.index') }}" class="nav-link {{ request()->routeIs('backup-health.*') ? 'active' : '' }}">
                    <span class="nav-icon"><x-icon name="backup-status" /></span>
                    Status dos backups
                </a>
            @endcan

            @can('backup_policies.view')
                <a href="{{ route('backup-policies.index') }}" class="nav-link {{ request()->routeIs('backup-policies.*') ? 'active' : '' }}">
                    <span class="nav-icon"><x-icon name="policy" /></span>
                    Políticas
                </a>
            @endcan

            @can('backup_executions.view')
                <a href="{{ route('backup-executions.index') }}" class="nav-link {{ request()->routeIs('backup-executions.*') ? 'active' : '' }}">
                    <span class="nav-icon"><x-icon name="execution" /></span>
                    Execuções de backup
                </a>
            @endcan

            @can('backup_artifacts.view')
                <a href="{{ route('backup-artifacts.index') }}" class="nav-link {{ request()->routeIs('backup-artifacts.*') ? 'active' : '' }}">
                    <span class="nav-icon"><x-icon name="folder" /></span>
                    Artefatos
                </a>
            @endcan

            @can('reports.view')
                <a href="{{ route('reports.index') }}" class="nav-link {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <span class="nav-icon"><x-icon name="report" /></span>
                    Relatórios
                </a>
            @endcan

            @canany(['settings.view', 'audit.view', 'users.view', 'system_health.view'])
                <div class="nav-section">Administração</div>
                @can('system_health.view')
                    <a href="{{ route('system-health.index') }}" class="nav-link {{ request()->routeIs('system-health.*') ? 'active' : '' }}">
                        <span class="nav-icon"><x-icon name="system-health" /></span>
                        Saúde do sistema
                    </a>
                @endcan
                @can('settings.view')
                    <a href="{{ route('settings.edit') }}" class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}">
                        <span class="nav-icon"><x-icon name="settings" /></span>
                        Configurações
                    </a>
                @endcan
                @can('audit.view')
                    <a href="{{ route('audit.index') }}" class="nav-link {{ request()->routeIs('audit.*') ? 'active' : '' }}">
                        <span class="nav-icon"><x-icon name="file" /></span>
                        Auditoria
                    </a>
                @endcan
                @can('users.view')
                    <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                        <span class="nav-icon"><x-icon name="users" /></span>
                        Usuários
                    </a>
                @endcan
            @endcanany
        </nav>

        <div class="sidebar-footer">
            <div class="user-block">
                <div class="user-avatar">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </div>

                <div class="user-info">
                    <strong>{{ auth()->user()->name }}</strong>
                    <span>{{ \App\Support\Rbac::roleLabel(auth()->user()->role) }}</span>
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="logout-button">Sair</button>
            </form>
        </div>
    </aside>

    <main class="main-content" id="main-content">
        @hasSection('page-header')
            @yield('page-header')
        @else
        <header class="topbar">
            <div>
                <h1>@yield('page-title')</h1>
                <p>@yield('page-description')</p>
            </div>

            <div class="topbar-status">Painel de operações</div>
        </header>
        @endif

        <section class="content">
            @yield('content')
        </section>
    </main>

</div>
<script>
    document.querySelectorAll('.sidebar-nav .nav-link.active').forEach(link => link.setAttribute('aria-current', 'page'));
    const sidebar = document.querySelector('.sidebar');
    const sidebarSize = new ResizeObserver(() => {
        sidebar.style.setProperty('--sidebar-height', `${sidebar.offsetHeight}px`);
    });
    sidebarSize.observe(sidebar);
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    sidebarToggle?.setAttribute('aria-expanded', 'false');
    sidebarToggle?.addEventListener('click', () => {
        const expanded = sidebarToggle.getAttribute('aria-expanded') === 'true';
        sidebarToggle.setAttribute('aria-expanded', String(!expanded));
    });
    document.querySelectorAll('.row-menu, .chart-filter-menu').forEach(menu => {
        menu.addEventListener('toggle', () => {
            if (!menu.open) return;
            const panel = menu.querySelector('.table-actions, .chart-filters');
            if (!panel) return;
            panel.style.removeProperty('top');
            panel.style.removeProperty('bottom');
            const bounds = panel.getBoundingClientRect();
            const button = menu.querySelector('summary').getBoundingClientRect();
            if (bounds.bottom > window.innerHeight && button.top >= bounds.height + 4) {
                panel.style.top = 'auto';
                panel.style.bottom = 'calc(100% + 4px)';
            } else if (bounds.top < 0) {
                panel.style.top = 'calc(100% + 4px)';
                panel.style.bottom = 'auto';
            }
        });
    });
    document.querySelectorAll('[data-list-search]').forEach(input => {
        const key = input.dataset.listSearch;
        const rows = [...document.querySelectorAll(`[data-list-row="${key}"]`)];
        const empty = document.querySelector(`[data-list-empty="${key}"]`);
        input.addEventListener('input', () => {
            const query = input.value.trim().toLocaleLowerCase('pt-BR');
            let visible = 0;
            rows.forEach(row => {
                row.hidden = !row.dataset.search.includes(query);
                if (!row.hidden) visible++;
            });
            if (empty) empty.hidden = visible !== 0;
        });
    });
    document.querySelectorAll('form').forEach(form => {
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method === 'get' || method === 'dialog') return;
        form.addEventListener('submit', event => {
            if (event.defaultPrevented) return;
            if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
            form.dataset.submitting = 'true';
            const button = event.submitter;
            if (button && !button.name) {
                button.classList.add('is-loading');
                button.setAttribute('aria-busy', 'true');
            }
        });
    });
    document.querySelectorAll('.alert--success[role="status"], .alert-success').forEach((notice) => {
        const dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'alert__dismiss';
        dismiss.setAttribute('aria-label', 'Fechar mensagem');
        dismiss.textContent = '×';
        dismiss.addEventListener('click', () => notice.remove());
        notice.appendChild(dismiss);

        if (notice.dataset.persist === 'true') return;

        let timer = window.setTimeout(() => notice.remove(), 6000);
        notice.addEventListener('mouseenter', () => window.clearTimeout(timer));
        notice.addEventListener('mouseleave', () => {
            timer = window.setTimeout(() => notice.remove(), 6000);
        });
        notice.addEventListener('focusin', () => window.clearTimeout(timer));
        notice.addEventListener('focusout', () => {
            timer = window.setTimeout(() => notice.remove(), 6000);
        });
    });
</script>
</body>
</html>
