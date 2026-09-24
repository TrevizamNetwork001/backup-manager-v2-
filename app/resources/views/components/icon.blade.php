@props(['name', 'size' => 'md', 'label' => null])
<svg {{ $attributes->class(['icon', 'icon--' . $size]) }} viewBox="0 0 24 24" @if($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif focusable="false">
    @switch($name)
        @case('add')
            <path d="M12 5v14M5 12h14" />
            @break
        @case('close')
            <path d="M5 5l14 14M19 5L5 19" />
            @break
        @case('device')
            <rect x="4" y="4" width="16" height="13" rx="2" />
            <path d="M8 20h8M12 17v3" />
            @break
        @case('backup')
            <path d="M12 4v11m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" />
            @break
        @case('shield')
            <path d="M12 3 5 6v5c0 4.5 2.6 7.8 7 10 4.4-2.2 7-5.5 7-10V6l-7-3Z" />
            @break
        @case('check')
            <path d="m5 12 5 5 9-10" />
            @break
        @case('alert')
            <path d="M12 3 2.8 19a1.3 1.3 0 0 0 1.1 2h16.2a1.3 1.3 0 0 0 1.1-2L12 3Z" />
            <path d="M12 9v5m0 3h.01" />
            @break
    @endswitch
</svg>
