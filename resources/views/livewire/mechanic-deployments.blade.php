<div class="dot-card" style="padding:1.25rem 1.5rem;">
    <table style="width:100%;border-collapse:collapse;font-size:0.8rem;">
        <thead>
            <tr style="text-align:left;color:#52525b;font-size:10px;text-transform:uppercase;letter-spacing:0.08em;">
                <th style="padding-bottom:0.6rem;">Mechanic</th>
                <th style="padding-bottom:0.6rem;">Category</th>
                <th style="padding-bottom:0.6rem;">Status</th>
                <th style="padding-bottom:0.6rem;">Started</th>
                <th style="padding-bottom:0.6rem;">Retired</th>
                <th style="padding-bottom:0.6rem;"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($this->deployments as $deployment)
                <tr style="border-top:1px solid rgba(255,255,255,0.06);">
                    <td style="padding:0.65rem 0;color:#f4f4f5;font-weight:600;">{{ $deployment->mechanic->name }}</td>
                    <td style="padding:0.65rem 0;">
                        <span class="dot-badge dot-badge-accent">{{ $deployment->mechanic->category->label() }}</span>
                    </td>
                    <td style="padding:0.65rem 0;color:{{ $deployment->status->value === 'active' ? '#22c55e' : '#71717a' }};">
                        {{ $deployment->status->label() }}
                    </td>
                    <td style="padding:0.65rem 0;color:#a1a1aa;">{{ $deployment->started_at->format('M j, Y') }}</td>
                    <td style="padding:0.65rem 0;color:#a1a1aa;">{{ $deployment->retired_at?->format('M j, Y') ?? '—' }}</td>
                    <td style="padding:0.65rem 0;text-align:right;">
                        @if ($deployment->status->value === 'active')
                            <button wire:click="retire({{ $deployment->id }})" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">
                                Retire
                            </button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="padding:2rem 0;text-align:center;color:#52525b;">
                        This team hasn't deployed any mechanics yet. Browse the
                        <a href="{{ route('mechanics.index') }}" style="color:#818cf8;">catalog</a> to get started.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
