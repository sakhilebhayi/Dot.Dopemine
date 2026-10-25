<x-points-admin section="rewards" title="Rewards catalogue"
    intro="The price list: what points can be spent on. Points have no cash value and cannot be transferred, so only billing credit, premium capacity and marketplace items are listed. Recognition and badges are never for sale. A reward is retired, not deleted, so past redemptions keep their meaning.">

    <div class="pa-card">
        <details @if ($errors->default->any() && old('key')) open @endif>
            <summary>Add a reward</summary>
            <form method="POST" action="{{ route('admin.points.rewards.store') }}">
                @csrf
                <div class="pa-form-grid">
                    <div class="pa-field"><label for="new-key">Key</label><input id="new-key" class="dot-input" name="key" required maxlength="80" pattern="[a-z0-9][a-z0-9._\-]*" value="{{ old('key') }}" placeholder="billing.credit-r50" aria-describedby="new-keyh"><span id="new-keyh" class="pa-hint">Permanent. Lower-case letters, numbers, dots, dashes.</span></div>
                    <div class="pa-field"><label for="new-title">Title</label><input id="new-title" class="dot-input" name="title" required maxlength="160" value="{{ old('title') }}"></div>
                    <div class="pa-field"><label for="new-kind">Kind</label>
                        <select id="new-kind" class="dot-input" name="kind" required>
                            @foreach (\App\Models\Reward::KIND_LABELS as $kind => $label)<option value="{{ $kind }}" @selected(old('kind') === $kind)>{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="pa-field"><label for="new-price">Price in points</label><input id="new-price" class="dot-input" type="number" min="1" name="price_points" required value="{{ old('price_points') }}"></div>
                    <div class="pa-field"><label for="new-stock">Stock</label><input id="new-stock" class="dot-input" type="number" min="0" name="stock" value="{{ old('stock') }}" placeholder="Unlimited"></div>
                    <div class="pa-field"><label for="new-cap">Monthly limit, all people</label><input id="new-cap" class="dot-input" type="number" min="1" name="monthly_cap" value="{{ old('monthly_cap') }}" placeholder="No limit"></div>
                </div>
                <div class="pa-field" style="margin-top:.8rem;"><label for="new-desc">Description</label><textarea id="new-desc" class="dot-input" name="description" maxlength="1000">{{ old('description') }}</textarea></div>
                <div class="pa-field" style="margin-top:.8rem;"><label for="new-why">Why you are adding it</label><input id="new-why" class="dot-input" name="reason" required minlength="5" maxlength="500" value="{{ old('reason') }}"></div>
                <p style="margin:.8rem 0 0;"><button class="pa-btn pa-btn-primary" type="submit">Add to the price list</button></p>
            </form>
        </details>
    </div>

    @forelse ($rewards as $reward)
        <article class="pa-card" aria-labelledby="rw-{{ $reward->id }}">
            <div class="pa-row">
                <h2 id="rw-{{ $reward->id }}">{{ $reward->title }}</h2>
                <span>
                    <span class="pa-badge">{{ $reward->kindLabel() }}</span>
                    <span class="pa-badge {{ $reward->active ? 'pa-badge-ok' : 'pa-badge-off' }}">{{ $reward->active ? 'On sale' : 'Retired' }}</span>
                </span>
            </div>
            <p class="pa-mono pa-muted" style="margin:.2rem 0 0;">{{ $reward->key }}</p>
            @if ($reward->description)<p class="pa-muted" style="margin:.4rem 0 0;">{{ $reward->description }}</p>@endif
            <dl class="pa-facts">
                <div><dt>Price</dt><dd class="pa-num">{{ number_format($reward->price_points) }} points</dd></div>
                <div><dt>Stock</dt><dd>{{ $reward->stock === null ? 'Unlimited' : number_format($reward->stock).' left' }}@if ($reward->stock === 0) <span class="pa-badge pa-badge-warn">Sold out</span>@endif</dd></div>
                <div><dt>Monthly limit</dt><dd>{{ $reward->monthly_cap === null ? 'None' : number_format($reward->monthly_cap) }}</dd></div>
                <div><dt>Waiting for delivery</dt><dd>@if (($pending[$reward->id] ?? 0) > 0)<a href="{{ route('admin.points.redemptions') }}" style="color:#c7d2fe;">{{ $pending[$reward->id] }}</a>@else 0 @endif</dd></div>
            </dl>

            <hr>
            <details>
                <summary>Edit</summary>
                <form method="POST" action="{{ route('admin.points.rewards.update', $reward) }}">
                    @csrf @method('PUT')
                    <div class="pa-form-grid">
                        <div class="pa-field"><label for="t-{{ $reward->id }}">Title</label><input id="t-{{ $reward->id }}" class="dot-input" name="title" required maxlength="160" value="{{ $reward->title }}"></div>
                        <div class="pa-field"><label for="k-{{ $reward->id }}">Kind</label>
                            <select id="k-{{ $reward->id }}" class="dot-input" name="kind" required>
                                @foreach (\App\Models\Reward::KIND_LABELS as $kind => $label)<option value="{{ $kind }}" @selected($reward->kind === $kind)>{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div class="pa-field"><label for="p-{{ $reward->id }}">Price in points</label><input id="p-{{ $reward->id }}" class="dot-input" type="number" min="1" name="price_points" required value="{{ $reward->price_points }}"></div>
                        <div class="pa-field"><label for="s-{{ $reward->id }}">Stock</label><input id="s-{{ $reward->id }}" class="dot-input" type="number" min="0" name="stock" value="{{ $reward->stock }}" placeholder="Unlimited"></div>
                        <div class="pa-field"><label for="c-{{ $reward->id }}">Monthly limit</label><input id="c-{{ $reward->id }}" class="dot-input" type="number" min="1" name="monthly_cap" value="{{ $reward->monthly_cap }}" placeholder="No limit"></div>
                    </div>
                    <div class="pa-field" style="margin-top:.8rem;"><label for="d-{{ $reward->id }}">Description</label><textarea id="d-{{ $reward->id }}" class="dot-input" name="description" maxlength="1000">{{ $reward->description }}</textarea></div>
                    <div class="pa-field" style="margin-top:.8rem;"><label for="w-{{ $reward->id }}">Why you are changing it</label><input id="w-{{ $reward->id }}" class="dot-input" name="reason" required minlength="5" maxlength="500"></div>
                    <p style="margin:.8rem 0 0;"><button class="pa-btn pa-btn-primary" type="submit">Save changes</button></p>
                </form>
            </details>

            <details style="margin-top:.4rem;">
                <summary>{{ $reward->active ? 'Retire this reward' : 'Put back on sale' }}</summary>
                <form method="POST" action="{{ route($reward->active ? 'admin.points.rewards.retire' : 'admin.points.rewards.reinstate', $reward) }}">
                    @csrf
                    <div class="pa-field"><label for="r-{{ $reward->id }}">Why</label><input id="r-{{ $reward->id }}" class="dot-input" name="reason" required minlength="5" maxlength="500"></div>
                    <p class="pa-hint" style="margin:.4rem 0 0;">{{ $reward->active ? 'Nobody can redeem it afterwards. Redemptions already waiting are not touched.' : 'People can redeem it again.' }}</p>
                    <p style="margin:.7rem 0 0;"><button class="pa-btn {{ $reward->active ? 'pa-btn-danger' : 'pa-btn-ghost' }}" type="submit">{{ $reward->active ? 'Retire' : 'Put back on sale' }}</button></p>
                </form>
            </details>
        </article>
    @empty
        <div class="pa-card"><p class="pa-muted" style="margin:0;">The price list is empty. Add the first reward above; until then nobody can spend points.</p></div>
    @endforelse
</x-points-admin>
