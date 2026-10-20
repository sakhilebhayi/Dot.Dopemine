<x-points-admin section="ledger" title="Ledger explorer"
    intro="Read-only. Look up a person by their user uuid to see their balance, every lot of points with its expiry, and the full history with the rule version behind each entry. Nothing on this page changes anything.">

    <form method="GET" action="{{ route('admin.points.ledger') }}" class="pa-card" role="search">
        <div class="pa-field">
            <label for="user">User uuid</label>
            <div style="display:flex; gap:.6rem; flex-wrap:wrap;">
                <input id="user" class="dot-input" style="flex:1 1 20rem; width:auto;" name="user" value="{{ $query }}" required maxlength="36" autocomplete="off" spellcheck="false" placeholder="00000000-0000-0000-0000-000000000000" @if (! empty($invalid)) aria-invalid="true" aria-describedby="user-err" @endif>
                <button class="pa-btn pa-btn-primary" type="submit">Look up</button>
            </div>
            @if (! empty($invalid))<span id="user-err" class="pa-error" role="alert">That is not a uuid. It looks like 8-4-4-4-12 hexadecimal characters.</span>@endif
        </div>
    </form>

    @if ($query === '')
        <section aria-labelledby="recent-h">
            <h2 id="recent-h" class="pa-h1" style="font-size:1.05rem;">Latest entries across everyone</h2>
            <div class="pa-card">
                @if ($recent->isEmpty())
                    <p class="pa-muted" style="margin:0;">The ledger is empty: nothing has been awarded yet.</p>
                @else
                    <div class="pa-scroll" role="region" aria-label="Latest ledger entries" tabindex="0">
                        <table class="pa-table">
                            <thead><tr><th scope="col">When</th><th scope="col">Type</th><th scope="col" class="pa-right">Points</th><th scope="col">Rule</th><th scope="col">Person</th></tr></thead>
                            <tbody>
                            @foreach ($recent as $entry)
                                <tr>
                                    <td><x-points-time :at="$entry->created_at" /></td>
                                    <td>{{ ucfirst($entry->type) }}</td>
                                    <td class="pa-right pa-num {{ $entry->points >= 0 ? 'pa-pos' : 'pa-neg' }}">{{ $entry->points > 0 ? '+' : '' }}{{ number_format($entry->points) }}</td>
                                    <td class="pa-mono">{{ $entry->rule_key ? $entry->rule_key.' v'.$entry->rule_version : '—' }}</td>
                                    <td class="pa-mono"><a href="{{ route('admin.points.ledger', ['user' => $entry->user_uuid]) }}" style="color:#c7d2fe;">{{ $entry->user_uuid }}</a></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    @elseif (empty($invalid))
        @if (! $found)
            <div class="pa-card"><p class="pa-muted" style="margin:0;">Nothing in the ledger for <span class="pa-mono">{{ $query }}</span>. Either they have earned nothing yet, or the uuid is wrong.</p></div>
        @else
            <div class="pa-card">
                <div class="pa-row">
                    <h2>Balance</h2>
                    <span class="pa-mono">{{ $query }}</span>
                </div>
                <p class="pa-num" style="font-size:2rem; margin:.4rem 0 0; color:#f4f4f5;">{{ number_format($balance) }} <span class="pa-muted" style="font-size:.85rem;">points they can spend now</span></p>
                <p class="pa-hint" style="margin:.3rem 0 0;">Worked out from the lots below that have not expired. Nothing is stored that could drift from the entries.</p>
            </div>

            <section aria-labelledby="lots-h">
                <h2 id="lots-h" class="pa-h1" style="font-size:1.05rem;">Lots of points</h2>
                <div class="pa-card">
                    <div class="pa-scroll" role="region" aria-label="Lots of points" tabindex="0">
                        <table class="pa-table">
                            <thead><tr><th scope="col">Lot</th><th scope="col">Source</th><th scope="col">Granted</th><th scope="col" class="pa-right">Points</th><th scope="col" class="pa-right">Left</th><th scope="col">Expires</th><th scope="col">State</th><th scope="col">Rule</th></tr></thead>
                            <tbody>
                            @foreach ($lots as $lot)
                                @php
                                    $lapsed = $lot->expires_at !== null && $lot->expires_at->isPast();
                                    $state = $lot->remaining <= 0 ? ['Closed', 'pa-badge-off'] : ($lapsed ? ['Past expiry', 'pa-badge-warn'] : ['Open', 'pa-badge-ok']);
                                @endphp
                                <tr>
                                    <th scope="row" class="pa-num" style="color:#f4f4f5;">#{{ $lot->id }}</th>
                                    <td>{{ ucfirst($lot->type) }}</td>
                                    <td><x-points-time :at="$lot->created_at" /></td>
                                    <td class="pa-right pa-num">{{ number_format($lot->points) }}</td>
                                    <td class="pa-right pa-num">{{ number_format($lot->remaining) }}</td>
                                    <td><x-points-time :at="$lot->expires_at" /></td>
                                    <td><span class="pa-badge {{ $state[1] }}">{{ $state[0] }}</span></td>
                                    <td class="pa-mono">{{ $lot->rule_key ? $lot->rule_key.' v'.$lot->rule_version : '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section aria-labelledby="hist-h">
                <h2 id="hist-h" class="pa-h1" style="font-size:1.05rem;">Every entry, newest first</h2>
                <div class="pa-card">
                    <div class="pa-scroll" role="region" aria-label="Ledger entries" tabindex="0">
                        <table class="pa-table" style="min-width:56rem;">
                            <thead><tr><th scope="col">#</th><th scope="col">When</th><th scope="col">Type</th><th scope="col" class="pa-right">Points</th><th scope="col">Rule version</th><th scope="col">Contribution</th><th scope="col">Lot</th><th scope="col">Reason</th></tr></thead>
                            <tbody>
                            @foreach ($entries as $entry)
                                @php $via = $entry->lot_id ? ($lotRules[$entry->lot_id] ?? null) : null; @endphp
                                <tr>
                                    <th scope="row" class="pa-num" style="color:#f4f4f5;">{{ $entry->id }}</th>
                                    <td><x-points-time :at="$entry->created_at" /></td>
                                    <td>{{ ucfirst($entry->type) }}</td>
                                    <td class="pa-right pa-num {{ $entry->points >= 0 ? 'pa-pos' : 'pa-neg' }}">{{ $entry->points > 0 ? '+' : '' }}{{ number_format($entry->points) }}</td>
                                    <td class="pa-mono">
                                        @if ($entry->rule_key)
                                            <a href="{{ route('admin.points.rules') }}#rule-{{ str_replace('.', '-', $entry->rule_key) }}" style="color:#c7d2fe;">{{ $entry->rule_key }} v{{ $entry->rule_version }}</a>
                                        @elseif ($via?->rule_key)
                                            <span class="pa-muted">via lot #{{ $via->id }}:</span> {{ $via->rule_key }} v{{ $via->rule_version }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="pa-mono">{{ $entry->contribution_ref ?? '—' }}</td>
                                    <td class="pa-num">{{ $entry->lot_id ? '#'.$entry->lot_id : ($entry->expires_at ? 'starts #'.$entry->id : '—') }}</td>
                                    <td>{{ $entry->reason ?? '—' }}@if (($entry->meta['unrecovered'] ?? 0) > 0) <span class="pa-badge pa-badge-warn">{{ $entry->meta['unrecovered'] }} already spent</span>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($entries->hasMorePages() || $entries->currentPage() > 1)
                        <nav class="pa-pager" aria-label="Ledger pages">
                            @if ($entries->previousPageUrl())<a class="pa-btn pa-btn-ghost" href="{{ $entries->previousPageUrl() }}">Newer</a>@endif
                            @if ($entries->nextPageUrl())<a class="pa-btn pa-btn-ghost" href="{{ $entries->nextPageUrl() }}">Older</a>@endif
                        </nav>
                    @endif
                </div>
            </section>

            @if ($redemptions->isNotEmpty())
                <section aria-labelledby="red-h">
                    <h2 id="red-h" class="pa-h1" style="font-size:1.05rem;">Redemptions</h2>
                    <div class="pa-card">
                        <div class="pa-scroll" role="region" aria-label="Redemptions" tabindex="0">
                            <table class="pa-table">
                                <thead><tr><th scope="col">Requested</th><th scope="col">Reward</th><th scope="col" class="pa-right">Points</th><th scope="col">Status</th><th scope="col">Reference or reason</th></tr></thead>
                                <tbody>
                                @foreach ($redemptions as $redemption)
                                    <tr>
                                        <td><x-points-time :at="$redemption->created_at" /></td>
                                        <td>{{ $redemption->reward?->title ?? '—' }}</td>
                                        <td class="pa-right pa-num">{{ number_format($redemption->points) }}</td>
                                        <td><span class="pa-badge {{ $redemption->status === 'fulfilled' ? 'pa-badge-ok' : ($redemption->status === 'pending' ? 'pa-badge-warn' : 'pa-badge-bad') }}">{{ ucfirst($redemption->status) }}</span></td>
                                        <td>{{ $redemption->status === 'fulfilled' ? $redemption->fulfilment_ref : $redemption->note }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @endif
        @endif
    @endif
</x-points-admin>
