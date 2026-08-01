<div>
    <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1rem;">
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
            <select wire:model.live="statusFilter" class="dot-input" style="width:auto;">
                <option value="">All statuses</option>
                @foreach ($this->statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="categoryFilter" class="dot-input" style="width:auto;">
                <option value="">All categories</option>
                @foreach ($this->categories as $category)
                    <option value="{{ $category->value }}">{{ $category->label() }}</option>
                @endforeach
            </select>
            <span style="font-size:0.75rem;color:#52525b;margin-left:auto;">{{ $this->mechanics->count() }} mechanic(s)</span>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem;">
        @forelse ($this->mechanics as $mechanic)
            <div class="dot-card" style="padding:1.25rem 1.4rem;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.5rem;margin-bottom:0.5rem;">
                    <div>
                        <div style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;">{{ $mechanic->name }}</div>
                        <code style="font-size:10px;color:#52525b;">{{ $mechanic->key }}</code>
                    </div>
                    <span class="dot-badge dot-badge-accent">{{ $mechanic->category->label() }}</span>
                </div>

                <p style="font-size:0.78rem;color:#a1a1aa;line-height:1.55;margin:0 0 0.75rem;">{{ $mechanic->description }}</p>

                <p style="font-size:0.72rem;color:#3f3f46;line-height:1.5;margin:0 0 0.75rem;font-style:italic;">{{ $mechanic->category->intent() }}</p>

                <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                    <span class="dot-badge" style="background:{{ $mechanic->status->value === 'certified' ? 'rgba(34,197,94,0.12)' : ($mechanic->status->value === 'decertified' ? 'rgba(239,68,68,0.12)' : 'rgba(245,158,11,0.12)') }};color:{{ $mechanic->status->value === 'certified' ? '#22c55e' : ($mechanic->status->value === 'decertified' ? '#ef4444' : '#f59e0b') }};">
                        {{ $mechanic->status->label() }}
                    </span>
                    <span style="font-size:11px;color:#52525b;">acid test: {{ $mechanic->acid_test_passed ? 'passed' : 'not recorded' }}</span>
                    <span style="font-size:11px;color:#52525b;">{{ $mechanic->active_deployments_count }} team(s) using this</span>
                </div>

                <div style="display:flex;gap:0.5rem;margin-top:0.9rem;flex-wrap:wrap;">
                    @if ($mechanic->status->value === 'certified')
                        <button wire:click="deployToCurrentTeam({{ $mechanic->id }})" class="dot-btn dot-btn-primary" style="font-size:11.5px;padding:6px 11px;">
                            Deploy to my team
                        </button>
                    @endif

                    @if ($this->canGovern())
                        @if ($mechanic->status->value === 'proposed')
                            <button wire:click="certify({{ $mechanic->id }})" @disabled(!$mechanic->acid_test_passed) class="dot-btn dot-btn-ghost" style="font-size:11.5px;padding:6px 11px;">
                                Certify
                            </button>
                        @endif
                        @if ($mechanic->status->value === 'certified')
                            <button wire:click="startDecertify({{ $mechanic->id }})" class="dot-btn dot-btn-ghost" style="font-size:11.5px;padding:6px 11px;color:#ef4444;">
                                Decertify
                            </button>
                        @endif
                    @endif
                </div>

                @if ($decertifyingId === $mechanic->id)
                    <div style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid rgba(255,255,255,0.06);">
                        <textarea wire:model="decertifyingReason" class="dot-input" rows="2" placeholder="Decertification reason (required — becomes an incident record, wiki.md §10)"></textarea>
                        @error('decertifyingReason') <div style="color:#ef4444;font-size:11px;margin-top:4px;">{{ $message }}</div> @enderror
                        <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
                            <button wire:click="confirmDecertify" class="dot-btn" style="font-size:11px;padding:5px 10px;background:#ef4444;color:#fff;">Confirm decertify</button>
                            <button wire:click="$set('decertifyingId', null)" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="dot-card" style="padding:2rem;text-align:center;grid-column:1/-1;">
                <p style="font-size:0.8rem;color:#52525b;margin:0;">No mechanics match this filter.</p>
            </div>
        @endforelse
    </div>
</div>
