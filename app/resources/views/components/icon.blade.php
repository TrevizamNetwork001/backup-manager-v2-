@props(['name', 'size' => 'md', 'label' => null])
<svg {{ $attributes->class(['icon', 'icon--' . $size]) }} viewBox="0 0 24 24" @if($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif focusable="false">
    @switch($name)
        @case('home')
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z" />
            @break
        @case('site')
            <circle cx="12" cy="12" r="8" /><circle cx="12" cy="12" r="3" />
            @break
        @case('server')
            <rect x="3" y="3" width="18" height="8" rx="2" /><rect x="3" y="13" width="18" height="8" rx="2" /><path d="M7 7h.01M7 17h.01M11 7h6M11 17h6" />
            @break
        @case('database')
            <ellipse cx="12" cy="5" rx="8" ry="3" /><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3" />
            @break
        @case('folder')
            <path d="M20 20H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4.5a2 2 0 0 1 1.6.8L12 7h8a2 2 0 0 1 2 2v2" />
            <path d="m2 18 2.5-7h17L19 20H4a2 2 0 0 1-2-2Z" />
            @break
        @case('file')
            <path d="M6 2h8l5 5v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1zM14 2v6h5M8 13h8M8 17h8" />
            @break
        @case('error')
            <circle cx="12" cy="12" r="9" /><path d="m9 9 6 6m0-6-6 6" />
            @break
        @case('clock')
            <circle cx="12" cy="12" r="9" /><path d="M12 7v5l4 2" />
            @break
        @case('sync')
            <path d="M20 7a9 9 0 0 0-15-2M4 2v4h4M4 17a9 9 0 0 0 15 2m1 3v-4h-4" />
            @break
        @case('key')
            <circle cx="8" cy="15" r="4" /><path d="m11 12 9-9 2 2-2 2 1 1-3 3-1-1-3 3" />
            @break
        @case('settings')
            <circle cx="12" cy="12" r="3" /><path d="M10 2h4l.7 2.5 2 .9 2.3-1.2 2.8 2.8-1.2 2.3.9 2L24 12v1l-2.5.7-.9 2 1.2 2.3-2.8 2.8-2.3-1.2-2 .9L14 23h-4l-.7-2.5-2-.9-2.3 1.2-2.8-2.8 1.2-2.3-.9-2L0 13v-2l2.5-.7.9-2-1.2-2.3 2.8-2.8 2.3 1.2 2-.9z" />
            @break
        @case('search')
            <circle cx="10.5" cy="10.5" r="6.5" /><path d="m15.5 15.5 5 5" />
            @break
        @case('eye')
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z" /><circle cx="12" cy="12" r="2.5" />
            @break
        @case('chevron-down')
            <path d="m5 9 7 7 7-7" />
            @break
        @case('more-horizontal')
            <circle cx="5" cy="12" r="1" />
            <circle cx="12" cy="12" r="1" />
            <circle cx="19" cy="12" r="1" />
            @break
        @case('info')
            <circle cx="12" cy="12" r="9" /><path d="M12 11v6M12 7h.01" />
            @break
        @case('ftp')
            <path d="M7 3v14m0 0-3-3m3 3 3-3M17 21V7m0 0-3 3m3-3 3 3M4 5h6m4 14h6" />
            @break
        @case('layers')
            <path d="m12 2 9 5-9 5-9-5 9-5ZM3 12l9 5 9-5M3 17l9 5 9-5" />
            @break
        @case('refresh')
            <path d="M20 11a8 8 0 1 1-2.3-5.7M20 4v6h-6" />
            @break
        @case('archive')
            <rect x="4" y="4" width="16" height="5" rx="1" />
            <rect x="4" y="10" width="16" height="5" rx="1" />
            <rect x="4" y="16" width="16" height="5" rx="1" />
            <path d="M8 6.5h8M8 12.5h8M8 18.5h8" />
            @break
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
        @case('check-circle')
            <circle cx="12" cy="12" r="9" />
            <path d="m8 12 2.7 2.7L16.5 9" />
            @break
        @case('alert')
            <path d="M12 3 2.8 19a1.3 1.3 0 0 0 1.1 2h16.2a1.3 1.3 0 0 0 1.1-2L12 3Z" />
            <path d="M12 9v5m0 3h.01" />
            @break
    @endswitch
</svg>
