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

            <a
                href="{{ route('sites.index') }}"
                class="nav-link {{ request()->routeIs('sites.*') ? 'active' : '' }}"
            >
                <span class="nav-icon">◎</span>
                Sites / POPs
            </a>

            <a
                href="{{ route('devices.index') }}"
                class="nav-link {{ request()->routeIs('devices.*') ? 'active' : '' }}"
            >
                <span class="nav-icon">▣</span>
                Equipamentos
            </a>

            <a href="{{ route('credentials.index') }}" class="nav-link {{ request()->routeIs('credentials.*') ? 'active' : '' }}">
                <span class="nav-icon">◆</span>
                Credenciais
            </a>

            <div class="nav-section">Backup</div>

            <span class="nav-link disabled">
                <span class="nav-icon">↻</span>
                Execuções
            </span>

            <span class="nav-link disabled">
                <span class="nav-icon">▤</span>
                Artefatos
            </span>
        </nav>

        <div class="sidebar-footer">
            <div class="user-block">
                <div class="user-avatar">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </div>

                <div class="user-info">
                    <strong>{{ auth()->user()->name }}</strong>
                    <span>{{ auth()->user()->is_admin ? 'Administrador' : 'Usuário' }}</span>
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="logout-button">Sair</button>
            </form>
        </div>
    </aside>

    <main class="main-content">
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

        <section class="content">
            @yield('content')
        </section>
    </main>

</div>
</body>
</html>
