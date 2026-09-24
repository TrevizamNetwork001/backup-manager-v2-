<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Backup Manager')</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
</head>
<body class="app-body">
<div class="app-shell">

    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">BM</div>

            <div>
                <strong>Backup Manager</strong>
                <span>Infrastructure Backup</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <span class="nav-icon">⌂</span>
                Dashboard
            </a>

            <div class="nav-section">Infraestrutura</div>

            @can('sites.view')
                <a
                    href="{{ route('sites.index') }}"
                    class="nav-link {{ request()->routeIs('sites.*') ? 'active' : '' }}"
                >
                    <span class="nav-icon">◎</span>
                    Sites / POPs
                </a>
            @endcan

            @can('devices.view')
                <a
                    href="{{ route('devices.index') }}"
                    class="nav-link {{ request()->routeIs('devices.*') ? 'active' : '' }}"
                >
                    <span class="nav-icon">▣</span>
                    Equipamentos
                </a>
            @endcan

            @can('credentials.view')
                <a href="{{ route('credentials.index') }}" class="nav-link {{ request()->routeIs('credentials.*') ? 'active' : '' }}">
                    <span class="nav-icon">◆</span>
                    Credenciais
                </a>
            @endcan

            @can('ftp.view')
                <a href="{{ route('ftp.index') }}" class="nav-link {{ request()->routeIs('ftp.*') ? 'active' : '' }}">
                    <span class="nav-icon">⇅</span>
                    FTP
                </a>
            @endcan

            <div class="nav-section">Backup</div>

            @can('backup_policies.view')
                <a href="{{ route('backup-policies.index') }}" class="nav-link {{ request()->routeIs('backup-policies.*') ? 'active' : '' }}">
                    <span class="nav-icon">◷</span>
                    Políticas
                </a>
            @endcan

            @can('backup_executions.view')
                <a href="{{ route('backup-executions.index') }}" class="nav-link {{ request()->routeIs('backup-executions.*') ? 'active' : '' }}">
                    <span class="nav-icon">↻</span>
                    Execuções
                </a>
            @endcan

            @can('backup_artifacts.view')
                <a href="{{ route('backup-artifacts.index') }}" class="nav-link {{ request()->routeIs('backup-artifacts.*') ? 'active' : '' }}">
                    <span class="nav-icon">▤</span>
                    Artefatos
                </a>
            @endcan

            @canany(['settings.view', 'audit.view', 'users.view', 'system_health.view'])
                <div class="nav-section">Administração</div>
                @can('system_health.view')
                    <a href="{{ route('system-health.index') }}" class="nav-link {{ request()->routeIs('system-health.*') ? 'active' : '' }}">
                        <span class="nav-icon">♥</span>
                        Saúde do sistema
                    </a>
                @endcan
                @can('settings.view')
                    <a href="{{ route('settings.edit') }}" class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}">
                        <span class="nav-icon">⚙</span>
                        Configurações
                    </a>
                @endcan
                @can('audit.view')
                    <a href="{{ route('audit.index') }}" class="nav-link {{ request()->routeIs('audit.*') ? 'active' : '' }}">
                        <span class="nav-icon">☰</span>
                        Auditoria
                    </a>
                @endcan
                @can('users.view')
                    <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                        <span class="nav-icon">☺</span>
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

    <main class="main-content">
        @hasSection('page-header')
            @yield('page-header')
        @else
        <header class="topbar">
            <div>
                <h1>@yield('page-title')</h1>
                <p>@yield('page-description')</p>
            </div>

            <div class="topbar-status">
                <span class="status-dot"></span>
                Sistema operacional
            </div>
        </header>
        @endif

        <section class="content">
            @yield('content')
        </section>
    </main>

</div>
</body>
</html>
