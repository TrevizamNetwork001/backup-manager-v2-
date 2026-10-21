@if($geo['asn'])
    <span class="audit-geo-asn">AS{{ $geo['asn'] }} · {{ $geo['organization'] ?: 'Operadora não identificada' }}</span>
@else
    <span class="audit-geo-asn">—</span>
@endif
