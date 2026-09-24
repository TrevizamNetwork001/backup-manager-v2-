@extends('layouts.app')
@section('title', ($isRotation ? 'Senha FTP alterada' : 'Conta FTP criada').' — Backup Manager')
@section('page-title', $isRotation ? 'Senha FTP alterada' : 'Conta FTP criada')
@section('page-description', 'Copie os dados de acesso antes de concluir.')
@section('content')
@php($server = app(\App\Services\FtpServerSettings::class)->get())
<div class="ftp-once-page"><article class="panel ftp-once-card">
    <div class="ftp-once-heading"><span class="ftp-success-mark" aria-hidden="true">✓</span><div><h2>{{ $isRotation ? 'Nova senha pronta' : 'Conta FTP criada' }}</h2><p>{{ $message }}</p></div></div>
    <div class="alert-warning" role="status">Esta senha será exibida somente agora. Guarde-a antes de fechar.</div>
    @if ($isRotation)<p class="ftp-rotation-warning">Atualize esta credencial no equipamento antes do próximo envio.</p>@endif
    <dl class="ftp-access-list">
        <div><dt>Servidor</dt><dd id="ftp-access-server">{{ $server['host'] ?: 'Não configurado' }}</dd></div>
        <div><dt>Porta</dt><dd id="ftp-access-port">21</dd></div>
        <div><dt>Usuário</dt><dd><code id="ftp-access-user">{{ $account->username }}</code></dd></div>
        <div><dt>Senha</dt><dd><code class="ftp-secret-value" id="ftp-access-secret">{{ $secret }}</code></dd></div>
        <div><dt>Diretório remoto</dt><dd id="ftp-access-path"><code>/</code></dd></div>
    </dl>
    <div class="ftp-copy-actions"><button type="button" class="secondary-button" data-copy-ftp="user">Copiar usuário</button><button type="button" class="secondary-button" data-copy-ftp="secret">Copiar senha</button><button type="button" class="secondary-button" data-copy-ftp="all">Copiar dados de acesso</button></div>
    <p class="ftp-copy-feedback" id="ftp-copy-feedback" role="status" aria-live="polite"></p>
    <div class="ftp-once-footer"><a class="primary-button inline-button" href="{{ route('ftp.show', $account) }}">Concluir</a></div>
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
