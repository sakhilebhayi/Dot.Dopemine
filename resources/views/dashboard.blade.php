<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">Mechanic Catalog</h1>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">Every mechanic here has passed the acid test — never optimized for addiction or screen time, only for progress, achievement, mastery, community, momentum, purpose, learning, or confidence.</p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:2rem;">
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">Certified Mechanics</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:#818cf8;">{{ $certifiedCount }}</div>
        </div>
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">Proposed (Pending Gate)</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:#f59e0b;">{{ $proposedCount }}</div>
        </div>
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">Decertified</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:#ef4444;">{{ $decertifiedCount }}</div>
        </div>
        <div class="dot-card" style="padding:1.25rem 1.5rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.75rem;">Active Deployments (Your Teams)</div>
            <div class="metric-val" style="font-size:2rem;font-weight:600;color:#22c55e;">{{ $activeDeployments }}</div>
        </div>
    </div>

    <livewire:mechanic-catalog />

    <div class="dot-card" style="padding:1.5rem 1.75rem;margin-top:1.5rem;">
        <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin-bottom:0.6rem;">What we will not build</div>
        <p style="font-size:0.8rem;color:#a1a1aa;line-height:1.6;margin:0 0 0.75rem;">
            The prohibited-metric list (wiki.md §6) is as much this platform's product as the catalog is. No mechanic
            category on this list can even be created — the catalog's category field is a fixed enum, not free text.
        </p>
        <ul style="font-size:0.78rem;color:#71717a;line-height:1.8;margin:0;padding-left:1.1rem;">
            @foreach ($prohibitedPatterns as $pattern)
                <li><strong style="color:#d4d4d8;">{{ $pattern->pattern }}</strong> — {{ $pattern->reason }}</li>
            @endforeach
        </ul>
    </div>
</div>
</x-app-layout>
