<span class="audit-geo-country">
    @if($geo['country_code'])
        <img src="{{ asset('assets/flags/'.$geo['country_code'].'.svg') }}" alt="" width="23" height="16" loading="lazy">
    @endif
    {{ $geo['country_name'] }}
</span>
