@extends('layouts.app')
@section('title', ($isRotation ? 'Senha FTP alterada' : 'Conta FTP criada').' — Backup Manager')
@section('page-header')
<header class="page-header"><div class="page-header__content"><h1 class="page-header__title">{{ $isRotation ? 'Senha FTP alterada' : 'Conta FTP criada' }}</h1><p class="page-header__description">Copie os dados de acesso antes de concluir.</p></div></header>
@endsection
@section('content')
@php($server = app(\App\Services\FtpServerSettings::class)->get())
<div class="ftp-once-page"><article class="ftp-once-content">
    <div class="ftp-once-heading"><span class="ftp-success-mark" aria-hidden="true"><x-icon name="check" /></span><div><h2>{{ $isRotation ? 'Nova senha pronta' : 'Conta FTP criada' }}</h2><p>{{ $message }}</p></div></div>
    <div class="alert alert--warning" role="status">Esta senha será exibida somente agora. Guarde-a antes de fechar.</div>
    @if ($isRotation && $account->purpose === 'backup')<p class="alert alert--info">Atualize a credencial no equipamento antes do próximo envio.</p>@endif
    <dl class="ftp-access-list">
        <div><dt>Servidor</dt><dd class="tech-value" id="ftp-access-server">{{ $server['host'] ?: 'Não configurado' }}</dd></div>
        <div><dt>Porta</dt><dd class="tech-value" id="ftp-access-port">21</dd></div>
        <div><dt>Usuário</dt><dd><code class="tech-value ftp-selectable" id="ftp-access-user">{{ $account->username }}</code></dd></div>
        <div class="ftp-access-secret"><dt>Senha</dt><dd><code class="ftp-secret-value ftp-selectable" id="ftp-access-secret">{{ $secret }}</code></dd></div>
        <div><dt>Diretório remoto</dt><dd class="tech-value" id="ftp-access-path">/</dd></div>
    </dl>
    <div class="ftp-copy-actions"><button type="button" class="btn btn--secondary btn--sm" data-copy-ftp="user">Copiar usuário</button><button type="button" class="btn btn--secondary btn--sm" data-copy-ftp="secret">Copiar senha</button><button type="button" class="btn btn--secondary btn--sm" data-copy-ftp="all">Copiar dados de acesso</button></div>
    <p class="ftp-copy-feedback" id="ftp-copy-feedback" role="status" aria-live="polite"></p>
    <div class="ftp-once-footer"><a class="btn btn--primary" href="{{ route('ftp.show', $account) }}">Concluir</a></div>
</article></div>
<script>
(() => {
    const get = id => document.getElementById(`ftp-access-${id}`).textContent.trim();
    const feedback = document.getElementById('ftp-copy-feedback');
    document.querySelectorAll('[data-copy-ftp]').forEach(button => button.addEventListener('click', async () => {
        const kind = button.dataset.copyFtp;
        const value = kind === 'all' ? `Servidor: ${get('server')}\nPorta: ${get('port')}\nUsuário: ${get('user')}\nSenha: ${get('secret')}\nDiretório remoto: ${get('path')}` : get(kind);
        try { await navigator.clipboard.writeText(value); feedback.textContent = 'Copiado para a área de transferência.'; }
        catch { feedback.textContent = 'Não foi possível copiar automaticamente. Selecione o texto acima.'; }
    }));
})();
</script>
@endsection
