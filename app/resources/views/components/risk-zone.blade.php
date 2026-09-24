@props(['title' => 'Zona de risco', 'description' => null])
<section class="risk-zone">
    <div class="risk-zone__header">
        <h2>{{ $title }}</h2>
        @if($description)<p>{{ $description }}</p>@endif
    </div>
    <div class="risk-zone__body">
        {{ $slot }}
    </div>
</section>
