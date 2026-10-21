@extends('layouts.app')

@section('title', 'Meu perfil — Backup Manager')

@section('page-header')
<header class="page-header">
    <div class="page-header__content">
        <h1 class="page-header__title">Meu perfil</h1>
        <p class="page-header__description">Escolha como seu avatar aparece no painel.</p>
    </div>
</header>
@endsection

@section('content')
<div class="profile-page stack">
    @if(session('success'))
        <div class="alert alert--success" role="status">{{ session('success') }}</div>
    @endif
    <article class="card">
        <div class="card__body profile-page__body">
            <div class="profile-current">
                <div class="profile-current__avatar @if($user->avatar_preset) user-avatar--{{ $user->avatar_preset }} @endif" aria-hidden="true">
                    @if($user->avatar_path)
                        <img src="{{ route('profile.avatar.show') }}" alt="">
                    @elseif($user->avatar_preset)
                        <x-icon name="user" size="lg" />
                    @else
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    @endif
                </div>
                <div>
                    <h2>{{ $user->name }}</h2>
                    <p>{{ $user->email }}</p>
                </div>
            </div>

            <div class="profile-options">
                <section class="profile-option" aria-labelledby="profile-photo-title">
                    <h3 id="profile-photo-title">Enviar uma foto</h3>
                    <p>Use uma imagem JPG, PNG ou WebP de até 2 MB.</p>
                    <form method="POST" action="{{ route('profile.avatar.upload') }}" enctype="multipart/form-data">
                        @csrf
                        <label class="form-label" for="profile-avatar-file">Escolher imagem</label>
                        <input class="form-control" id="profile-avatar-file" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" required>
                        @error('avatar') <span class="form-error" role="alert">{{ $message }}</span> @enderror
                        <img id="profile-avatar-preview" class="profile-avatar-preview" alt="Prévia da foto escolhida" hidden>
                        <button class="btn btn--primary" type="submit">Salvar foto</button>
                    </form>
                </section>

                <section class="profile-option" aria-labelledby="profile-preset-title">
                    <h3 id="profile-preset-title">Escolher um avatar</h3>
                    <p>Uma opção pronta para usar sem enviar foto.</p>
                    <form method="POST" action="{{ route('profile.avatar.preset') }}">
                        @csrf @method('PATCH')
                        <div class="profile-presets">
                            @foreach(\App\Models\User::AVATAR_PRESETS as $preset)
                                <label class="profile-preset">
                                    <input type="radio" name="avatar_preset" value="{{ $preset }}" @checked(old('avatar_preset', $user->avatar_preset) === $preset) required>
                                    <span class="profile-preset__image user-avatar--{{ $preset }}" aria-hidden="true"><x-icon name="user" /></span>
                                    <span>{{ ucfirst($preset) }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('avatar_preset') <span class="form-error" role="alert">{{ $message }}</span> @enderror
                        <button class="btn btn--secondary" type="submit">Usar avatar escolhido</button>
                    </form>
                </section>
            </div>

            @if($user->avatar_path || $user->avatar_preset)
                <form method="POST" action="{{ route('profile.avatar.remove') }}" class="profile-remove">
                    @csrf @method('DELETE')
                    <button class="btn btn--ghost" type="submit">Remover avatar e usar inicial</button>
                </form>
            @endif
        </div>
    </article>
</div>
<script>
(() => {
    const input = document.getElementById('profile-avatar-file');
    const preview = document.getElementById('profile-avatar-preview');
    let previewUrl;
    input.addEventListener('change', () => {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        const file = input.files?.[0];
        preview.hidden = !file;
        if (file) preview.src = previewUrl = URL.createObjectURL(file);
    });
    window.addEventListener('pagehide', () => { if (previewUrl) URL.revokeObjectURL(previewUrl); });
})();
</script>
@endsection
