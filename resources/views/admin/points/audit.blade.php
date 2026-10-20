<x-points-admin section="audit" title="Audit log"
    intro="Who changed what in this back-office, when, and why. Entries are never edited or removed. Rule versions also carry their own author and reason in the version history.">
    <div class="pa-card">
        @if ($audits->isEmpty())
            <p class="pa-muted" style="margin:0;">No administrative changes have been made yet.</p>
        @else
            <div class="pa-scroll" role="region" aria-label="Audit log" tabindex="0">
                <table class="pa-table" style="min-width:46rem;">
                    <thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Subject</th><th scope="col">Why</th><th scope="col">Detail</th></tr></thead>
                    <tbody>
                    @foreach ($audits as $audit)
                        <tr>
                            <td><x-points-time :at="$audit->created_at" /></td>
                            <td>{{ $audit->actor_label }}</td>
                            <td class="pa-mono">{{ $audit->action }}</td>
                            <td class="pa-mono">{{ $audit->subject_key }}</td>
                            <td>{{ $audit->reason }}</td>
                            <td>@if ($audit->details)<details><summary>Show</summary><pre class="pa-mono" style="white-space:pre-wrap; margin:0;">{{ json_encode($audit->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></details>@else —@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($audits->hasMorePages() || $audits->currentPage() > 1)
                <nav class="pa-pager" aria-label="Audit pages">
                    @if ($audits->previousPageUrl())<a class="pa-btn pa-btn-ghost" href="{{ $audits->previousPageUrl() }}">Newer</a>@endif
                    @if ($audits->nextPageUrl())<a class="pa-btn pa-btn-ghost" href="{{ $audits->nextPageUrl() }}">Older</a>@endif
                </nav>
            @endif
        @endif
    </div>
</x-points-admin>
