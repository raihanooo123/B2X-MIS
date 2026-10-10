@if (! empty($doc['footer'] ?? []))
    <div class="footer">
        @foreach ($doc['footer'] as $line)
            <p>{{ $line }}</p>
        @endforeach
    </div>
@endif
