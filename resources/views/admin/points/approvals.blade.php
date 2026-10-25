<x-points-admin section="approvals" title="Pending approval"
    intro="Contributions whose reward is paid only after a moderator who is not the author has approved them. Approval normally arrives from InfoDot when a moderator approves; approve here only when it never did. Approving pays the reward once, under the same caps and rules as an approval from InfoDot, and is recorded in the audit log.">

    <section aria-labelledby="pending-h">
        <h2 id="pending-h" class="pa-h1" style="font-size:1.05rem;">Waiting for approval <span class="pa-badge {{ $pending->isEmpty() ? 'pa-badge-off' : 'pa-badge-warn' }}">{{ $pending->count() }}</span></h2>

        @forelse ($pending as $row)
            @php($contribution = $row['contribution'])
            <article class="pa-card" aria-labelledby="appr-{{ $contribution->id }}">
                <div class="pa-row">
                    <h3 id="appr-{{ $contribution->id }}" class="pa-mono">{{ $contribution->ref }}</h3>
                    <span class="pa-badge">{{ ucfirst($contribution->kind) }}</span>
                </div>
                <dl class="pa-facts">
                    <div><dt>Would pay</dt><dd class="pa-num">
                        @if ($row['unrewarded'])
                            Nothing (company-only)
                        @elseif ($row['amount'] !== null)
                            {{ number_format($row['amount']) }} points
                        @else
                            Rule switched off
                        @endif
                    </dd></div>
                    <div><dt>Rule</dt><dd class="pa-mono">{{ $row['rule_key'] }}</dd></div>
                    <div><dt>Waiting</dt><dd>{{ $row['since']->diffForHumans(null, true) }} <span class="pa-muted">(since <x-points-time :at="$row['since']" />)</span></dd></div>
                    <div style="grid-column: 1 / -1;"><dt>Author</dt><dd class="pa-mono"><a href="{{ route('admin.points.ledger', ['user' => $contribution->user_uuid]) }}" style="color:#c7d2fe;">{{ $contribution->user_uuid }}</a></dd></div>
                </dl>

                <form method="POST" action="{{ route('admin.points.approvals.approve', $contribution->uuid) }}" style="margin-top:.9rem;">
                    @csrf
                    <div class="pa-field">
                        <label for="reason-{{ $contribution->id }}">Note for the audit log (optional)</label>
                        <input id="reason-{{ $contribution->id }}" class="dot-input" name="reason" maxlength="300" autocomplete="off" placeholder="e.g. Checked the content and the steps">
                    </div>
                    <p style="margin:.7rem 0 0;"><button class="pa-btn pa-btn-primary" type="submit">Approve</button></p>
                </form>
            </article>
        @empty
            <div class="pa-card"><p class="pa-muted" style="margin:0;">Nothing is waiting for approval. When InfoDot reports a contribution that still needs a moderator, it appears here.</p></div>
        @endforelse
    </section>
</x-points-admin>
