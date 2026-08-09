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
                            <button wire:click="startRecordingOutcome({{ $deployment->id }})" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">
                                Record outcome
                            </button>
                            <button wire:click="retire({{ $deployment->id }})" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">
                                Retire
                            </button>
                        @endif
                    </td>
                </tr>
                @if ($recordingOutcomeId === $deployment->id)
                    <tr>
                        <td colspan="6" style="padding:0 0 0.75rem;">
                            <div class="dot-card" style="padding:1rem;">
                                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                                    <div>
                                        <label style="font-size:10px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">Period start</label>
                                        <input type="date" wire:model="outcomePeriodStart" class="dot-input" style="display:block;">
                                        @error('outcomePeriodStart') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label style="font-size:10px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">Period end</label>
                                        <input type="date" wire:model="outcomePeriodEnd" class="dot-input" style="display:block;">
                                        @error('outcomePeriodEnd') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label style="font-size:10px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">Engagement movement</label>
                                        <input type="text" wire:model="outcomeEngagementMovement" placeholder="e.g. 0.15" class="dot-input" style="display:block;width:110px;">
                                        @error('outcomeEngagementMovement') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label style="font-size:10px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">Outcome movement</label>
                                        <input type="text" wire:model="outcomeOutcomeMovement" placeholder="e.g. -0.02" class="dot-input" style="display:block;width:110px;">
                                        @error('outcomeOutcomeMovement') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                                <textarea wire:model="outcomeNotes" class="dot-input" rows="2" placeholder="Notes (optional)" style="margin-top:0.6rem;"></textarea>
                                <div style="display:flex;gap:0.5rem;margin-top:0.6rem;">
                                    <button wire:click="saveOutcome" class="dot-btn dot-btn-primary" style="font-size:11px;padding:5px 10px;">Save outcome</button>
                                    <button wire:click="cancelRecordingOutcome" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">Cancel</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endif
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
