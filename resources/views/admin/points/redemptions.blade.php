<x-points-admin section="redemptions" title="Redemption queue"
    intro="Points a person has spent that still need delivering. Delivering the reward happens outside this screen until Dot.Billing, the entitlement service and the marketplace have hooks; record the outcome here. Failing or cancelling returns the points as a new lot, once.">

    <section aria-labelledby="pending-h">
        <h2 id="pending-h" class="pa-h1" style="font-size:1.05rem;">Waiting for delivery <span class="pa-badge {{ $pending->isEmpty() ? 'pa-badge-off' : 'pa-badge-warn' }}">{{ $pending->count() }}</span></h2>

        @forelse ($pending as $redemption)
            <article class="pa-card" aria-labelledby="red-{{ $redemption->id }}">
                <div class="pa-row">
                    <h3 id="red-{{ $redemption->id }}">{{ $redemption->reward?->title ?? 'Unknown reward' }}</h3>
                    <span>
                        <span class="pa-badge">{{ $redemption->reward?->kindLabel() ?? '—' }}</span>
                    </span>
                </div>
                <dl class="pa-facts">
                    <div><dt>Cost</dt><dd class="pa-num">{{ number_format($redemption->points) }} points</dd></div>
                    <div><dt>Waiting</dt><dd>{{ $redemption->created_at->diffForHumans(null, true) }} <span class="pa-muted">(since <x-points-time :at="$redemption->created_at" />)</span></dd></div>
                    <div style="grid-column: 1 / -1;"><dt>Requester</dt><dd class="pa-mono"><a href="{{ route('admin.points.ledger', ['user' => $redemption->user_uuid]) }}" style="color:#c7d2fe;">{{ $redemption->user_uuid }}</a></dd></div>
                </dl>

                <div class="pa-split">
                    <form method="POST" action="{{ route('admin.points.redemptions.fulfil', $redemption->uuid) }}">
                        @csrf
                        <div class="pa-field">
                            <label for="ref-{{ $redemption->id }}">Delivery reference</label>
                            <input id="ref-{{ $redemption->id }}" class="dot-input" name="fulfilment_ref" required maxlength="191" autocomplete="off" placeholder="Invoice, credit note or order number" aria-describedby="refh-{{ $redemption->id }}">
                            <span id="refh-{{ $redemption->id }}" class="pa-hint">Kept with the redemption as proof it was delivered.</span>
                        </div>
                        <p style="margin:.7rem 0 0;"><button class="pa-btn pa-btn-primary" type="submit">Mark fulfilled</button></p>
                    </form>

                    <form method="POST" action="{{ route('admin.points.redemptions.fail', $redemption->uuid) }}">
                        @csrf
                        <div class="pa-field">
                            <label for="out-{{ $redemption->id }}">Outcome</label>
                            <select id="out-{{ $redemption->id }}" class="dot-input" name="outcome">
                                <option value="failed">Failed: it could not be delivered</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="pa-field" style="margin-top:.6rem;">
                            <label for="note-{{ $redemption->id }}">Reason, shown to the person</label>
                            <input id="note-{{ $redemption->id }}" class="dot-input" name="note" required minlength="5" maxlength="300" autocomplete="off" placeholder="e.g. Marketplace item is no longer available">
                        </div>
                        <p style="margin:.7rem 0 0;"><button class="pa-btn pa-btn-danger" type="submit">Return {{ number_format($redemption->points) }} points</button></p>
                    </form>
                </div>
            </article>
        @empty
            <div class="pa-card"><p class="pa-muted" style="margin:0;">No redemptions are waiting. When someone spends points on a reward it appears here.</p></div>
        @endforelse
    </section>

    <section aria-labelledby="done-h" style="margin-top:1.75rem;">
        <h2 id="done-h" class="pa-h1" style="font-size:1.05rem;">Recently decided</h2>
        <div class="pa-card">
            @if ($resolved->isEmpty())
                <p class="pa-muted" style="margin:0;">Nothing has been decided yet.</p>
            @else
                <div class="pa-scroll" role="region" aria-label="Recently decided redemptions" tabindex="0">
                    <table class="pa-table">
                        <thead><tr><th scope="col">Decided</th><th scope="col">Reward</th><th scope="col" class="pa-right">Points</th><th scope="col">Status</th><th scope="col">Reference or reason</th><th scope="col">Requester</th></tr></thead>
                        <tbody>
                        @foreach ($resolved as $redemption)
                            <tr>
                                <td><x-points-time :at="$redemption->updated_at" /></td>
                                <td>{{ $redemption->reward?->title ?? '—' }}</td>
                                <td class="pa-right pa-num">{{ number_format($redemption->points) }}</td>
                                <td><span class="pa-badge {{ $redemption->status === 'fulfilled' ? 'pa-badge-ok' : 'pa-badge-bad' }}">{{ ucfirst($redemption->status) }}</span></td>
                                <td>{{ $redemption->status === 'fulfilled' ? $redemption->fulfilment_ref : $redemption->note }}</td>
                                <td class="pa-mono"><a href="{{ route('admin.points.ledger', ['user' => $redemption->user_uuid]) }}" style="color:#c7d2fe;">{{ \Illuminate\Support\Str::limit($redemption->user_uuid, 13, '…') }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>
</x-points-admin>
