<x-points-admin section="rules" title="Reward rules"
    intro="What each kind of contribution earns. A rule is never edited: publishing a change writes the next version and closes the old one, and every point already awarded keeps the exact version that produced it.">

    @php
        $describe = fn ($r) => $r->min_points !== null ? $r->min_points.'–'.$r->max_points.' points (default '.$r->points.')' : $r->points.' points';
        $cap = fn ($r) => $r->daily_cap_points === null ? 'No daily cap' : $r->daily_cap_points.' points a day';
    @endphp

    @foreach ($rules as $key => $versions)
        @php
            $latest = $versions->first();
            $anchor = 'rule-'.str_replace('.', '-', $key);
            $bag = $errors->getBag('rule:'.$key);
            $failed = $bag->any();
            $val = fn (string $field) => $failed ? old($field) : $latest->{$field};
            $checked = fn (string $field) => $failed ? (bool) old($field) : (bool) $latest->{$field};
        @endphp
        <article id="{{ $anchor }}" class="pa-card" aria-labelledby="{{ $anchor }}-h">
            <div class="pa-row">
                <h2 id="{{ $anchor }}-h" class="pa-mono" style="font-size:.95rem;">{{ $key }}</h2>
                <span>
                    <span class="pa-badge">Version {{ $latest->version }}</span>
                    <span class="pa-badge {{ $latest->active ? 'pa-badge-ok' : 'pa-badge-off' }}">{{ $latest->active ? 'On' : 'Off: pays nothing' }}</span>
                </span>
            </div>
            @if ($latest->note && $latest->changed_by === 'seed')
                <p class="pa-muted" style="margin:.3rem 0 0;">{{ $latest->note }}</p>
            @endif
            <dl class="pa-facts">
                <div><dt>Pays</dt><dd>{{ $describe($latest) }}</dd></div>
                <div><dt>Approval</dt><dd>{{ $latest->requires_approval ? 'Needs approval first' : 'Paid on the event' }}</dd></div>
                <div><dt>Limit</dt><dd>{{ $cap($latest) }}</dd></div>
                <div><dt>Per contribution</dt><dd>{{ $latest->max_awards_per_contribution }} {{ \Illuminate\Support\Str::plural('award', $latest->max_awards_per_contribution) }}</dd></div>
                <div><dt>Listens to</dt><dd class="pa-mono">{{ $latest->platform }} · {{ $latest->event_type }}</dd></div>
                <div><dt>In force since</dt><dd><x-points-time :at="$latest->effective_from" /></dd></div>
            </dl>

            <hr>
            <details @if ($failed) open @endif>
                <summary>Publish a new version</summary>
                @if ($failed)
                    <div class="pa-flash pa-flash-error" role="alert">
                        <strong>Not published.</strong>
                        <ul>@foreach ($bag->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
                    </div>
                @endif
                <form method="POST" action="{{ route('admin.points.rules.publish', $key) }}">
                    @csrf
                    <input type="hidden" name="based_on_version" value="{{ $latest->version }}">
                    <div class="pa-form-grid">
                        <div class="pa-field"><label for="{{ $anchor }}-points">Points (default)</label><input id="{{ $anchor }}-points" class="dot-input" type="number" min="0" max="10000" name="points" required value="{{ $val('points') }}"></div>
                        <div class="pa-field"><label for="{{ $anchor }}-min">Smallest award</label><input id="{{ $anchor }}-min" class="dot-input" type="number" min="0" max="10000" name="min_points" value="{{ $val('min_points') }}" aria-describedby="{{ $anchor }}-rangeh"></div>
                        <div class="pa-field"><label for="{{ $anchor }}-max">Largest award</label><input id="{{ $anchor }}-max" class="dot-input" type="number" min="0" max="10000" name="max_points" value="{{ $val('max_points') }}" aria-describedby="{{ $anchor }}-rangeh"></div>
                        <div class="pa-field"><label for="{{ $anchor }}-cap">Daily cap, points per person</label><input id="{{ $anchor }}-cap" class="dot-input" type="number" min="1" name="daily_cap_points" value="{{ $val('daily_cap_points') }}" placeholder="No cap"></div>
                        <div class="pa-field"><label for="{{ $anchor }}-per">Awards per contribution</label><input id="{{ $anchor }}-per" class="dot-input" type="number" min="1" max="100" name="max_awards_per_contribution" required value="{{ $val('max_awards_per_contribution') }}"></div>
                    </div>
                    <p id="{{ $anchor }}-rangeh" class="pa-hint" style="margin:.5rem 0 0;">Leave the range empty for a fixed amount. With a range, the sender picks inside it and the default must sit inside too.</p>
                    <div class="pa-form-grid">
                        <label class="pa-check"><input type="hidden" name="requires_approval" value="0"><input type="checkbox" name="requires_approval" value="1" @checked($checked('requires_approval'))> Needs approval before it pays</label>
                        <label class="pa-check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($checked('active'))> Rule is on</label>
                    </div>
                    <div class="pa-field" style="margin-top:.8rem;">
                        <label for="{{ $anchor }}-why">Why you are changing it</label>
                        <textarea id="{{ $anchor }}-why" class="dot-input" name="reason" required minlength="10" maxlength="500" placeholder="Kept with this version permanently, with your name and the time.">{{ $failed ? old('reason') : '' }}</textarea>
                    </div>
                    <p style="margin:.8rem 0 0;"><button class="pa-btn pa-btn-primary" type="submit">Publish version {{ $latest->version + 1 }}</button></p>
                </form>
            </details>

            <details style="margin-top:.4rem;">
                <summary>Version history ({{ $versions->count() }})</summary>
                <div class="pa-scroll" role="region" aria-label="Version history of {{ $key }}" tabindex="0">
                    <table class="pa-table">
                        <thead><tr><th scope="col">Version</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Pays</th><th scope="col">Approval</th><th scope="col">Cap</th><th scope="col">State</th><th scope="col">Changed by</th><th scope="col">Why</th></tr></thead>
                        <tbody>
                        @foreach ($versions as $v)
                            <tr>
                                <th scope="row" class="pa-num" style="color:#f4f4f5;">v{{ $v->version }}</th>
                                <td><x-points-time :at="$v->effective_from" /></td>
                                <td>@if ($v->effective_to)<x-points-time :at="$v->effective_to" />@else <span class="pa-badge pa-badge-ok">Current</span>@endif</td>
                                <td>{{ $describe($v) }}</td>
                                <td>{{ $v->requires_approval ? 'Yes' : 'No' }}</td>
                                <td>{{ $v->daily_cap_points ?? '—' }}</td>
                                <td>{{ $v->active ? 'On' : 'Off' }}</td>
                                <td>{{ $v->changed_by ?? '—' }}</td>
                                <td>{{ $v->note ?? '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </article>
    @endforeach
</x-points-admin>
