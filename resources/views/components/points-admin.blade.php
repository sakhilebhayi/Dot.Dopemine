@props(['title', 'intro' => null, 'section'])

<x-app-layout>
    <style>
        .pa-wrap { padding: 2rem 2.5rem 3rem; max-width: 78rem; }
        .pa-h1 { font-family: 'Syne', sans-serif; font-size: 1.5rem; font-weight: 700; color: #f4f4f5; margin: 0 0 .25rem; letter-spacing: -.01em; }
        .pa-intro { font-size: .85rem; color: #a1a1aa; margin: 0; max-width: 60rem; line-height: 1.55; }
        .pa-tabs { display: flex; flex-wrap: wrap; gap: .4rem; margin: 1.25rem 0 1.5rem; padding: 0; list-style: none; }
        .pa-tabs a { display: inline-block; padding: .45rem .8rem; border-radius: 8px; font-size: .8rem; font-weight: 600; color: #a1a1aa; text-decoration: none; border: 1px solid rgba(255,255,255,.08); }
        .pa-tabs a:hover { color: #f4f4f5; background: rgba(255,255,255,.06); }
        .pa-tabs a[aria-current="page"] { color: #c7d2fe; background: rgba(129,140,248,.14); border-color: rgba(129,140,248,.35); }
        .pa-card { background: #141416; border: 1px solid rgba(255,255,255,.08); border-radius: 12px; padding: 1.1rem 1.25rem; margin-bottom: 1rem; }
        .pa-card h2, .pa-card h3 { font-family: 'Syne', sans-serif; margin: 0; color: #f4f4f5; font-size: 1rem; font-weight: 700; }
        .pa-muted { color: #a1a1aa; font-size: .8rem; line-height: 1.5; }
        .pa-mono { font-family: 'JetBrains Mono', monospace; font-size: .78rem; word-break: break-all; }
        .pa-num { font-family: 'JetBrains Mono', monospace; font-variant-numeric: tabular-nums; }
        .pa-row { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: baseline; justify-content: space-between; }
        .pa-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); gap: .75rem 1rem; margin: .9rem 0 0; }
        .pa-facts dt { font-size: .68rem; text-transform: uppercase; letter-spacing: .08em; color: #a1a1aa; margin: 0 0 .15rem; }
        .pa-facts dd { margin: 0; color: #f4f4f5; font-size: .9rem; }
        .pa-form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: .8rem 1rem; margin-top: .8rem; }
        .pa-field { display: flex; flex-direction: column; gap: .3rem; }
        .pa-field > label, .pa-label { font-size: .78rem; font-weight: 600; color: #d4d4d8; }
        .pa-hint { font-size: .72rem; color: #a1a1aa; }
        .pa-check { display: flex; align-items: center; gap: .5rem; font-size: .85rem; color: #e4e4e7; min-height: 2.25rem; }
        .pa-check input { width: 1.1rem; height: 1.1rem; accent-color: #818cf8; }
        .pa-wrap .dot-input { min-height: 2.25rem; font-size: .85rem; }
        .pa-wrap textarea.dot-input { min-height: 4.5rem; resize: vertical; }
        .pa-wrap .dot-input::placeholder { color: #71717a; }
        .pa-wrap a:focus-visible, .pa-wrap button:focus-visible, .pa-wrap summary:focus-visible, .pa-wrap .dot-input:focus-visible, .pa-wrap [tabindex]:focus-visible { outline: 2px solid #a5b4fc; outline-offset: 2px; }
        .pa-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 2.25rem; padding: .45rem .95rem; border-radius: 8px; border: 1px solid transparent; font-size: .82rem; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; }
        .pa-btn-primary { background: #818cf8; color: #09090b; }
        .pa-btn-primary:hover { filter: brightness(1.1); }
        .pa-btn-ghost { background: rgba(255,255,255,.06); color: #e4e4e7; border-color: rgba(255,255,255,.14); }
        .pa-btn-ghost:hover { background: rgba(255,255,255,.12); }
        .pa-btn-danger { background: rgba(248,113,113,.14); color: #fecaca; border-color: rgba(248,113,113,.4); }
        .pa-btn-danger:hover { background: rgba(248,113,113,.24); }
        .pa-badge { display: inline-block; padding: .1rem .55rem; border-radius: 100px; font-size: .7rem; font-weight: 600; border: 1px solid rgba(255,255,255,.14); color: #d4d4d8; white-space: nowrap; }
        .pa-badge-ok { color: #bbf7d0; border-color: rgba(74,222,128,.4); background: rgba(74,222,128,.08); }
        .pa-badge-warn { color: #fde68a; border-color: rgba(251,191,36,.4); background: rgba(251,191,36,.08); }
        .pa-badge-off { color: #d4d4d8; background: rgba(255,255,255,.05); }
        .pa-badge-bad { color: #fecaca; border-color: rgba(248,113,113,.4); background: rgba(248,113,113,.08); }
        .pa-flash { border-radius: 10px; padding: .7rem .95rem; margin-bottom: 1rem; font-size: .85rem; border: 1px solid rgba(74,222,128,.35); background: rgba(74,222,128,.08); color: #bbf7d0; }
        .pa-flash-error { border-color: rgba(248,113,113,.45); background: rgba(248,113,113,.09); color: #fecaca; }
        .pa-flash ul { margin: 0; padding-left: 1.1rem; }
        .pa-error { font-size: .75rem; color: #fca5a5; }
        .pa-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .pa-table { width: 100%; border-collapse: collapse; font-size: .8rem; min-width: 38rem; }
        .pa-table caption { text-align: left; padding: 0 0 .5rem; font-size: .8rem; color: #a1a1aa; }
        .pa-table th { text-align: left; font-size: .68rem; text-transform: uppercase; letter-spacing: .07em; color: #a1a1aa; font-weight: 600; padding: .45rem .6rem; border-bottom: 1px solid rgba(255,255,255,.12); white-space: nowrap; }
        .pa-table td { padding: .55rem .6rem; border-bottom: 1px solid rgba(255,255,255,.06); color: #e4e4e7; vertical-align: top; }
        .pa-table td.pa-right, .pa-table th.pa-right { text-align: right; }
        .pa-pos { color: #86efac; } .pa-neg { color: #fca5a5; }
        .pa-wrap details > summary { cursor: pointer; font-size: .82rem; font-weight: 600; color: #c7d2fe; padding: .35rem 0; }
        .pa-wrap details[open] > summary { margin-bottom: .4rem; }
        .pa-wrap hr { border: 0; border-top: 1px solid rgba(255,255,255,.08); margin: .9rem 0; }
        .pa-split { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-top: .9rem; }
        .pa-pager { display: flex; gap: .6rem; margin-top: .8rem; }
        .pa-wrap .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
        @media (max-width: 820px) {
            .pa-wrap { padding: 1rem; }
            .pa-split { grid-template-columns: 1fr; }
            .pa-card { padding: .9rem; }
            .pa-btn { width: 100%; }
        }
    </style>

    <div class="pa-wrap">
        <h1 class="pa-h1">{{ $title }}</h1>
        @if ($intro)
            <p class="pa-intro">{{ $intro }}</p>
        @endif

        <nav aria-label="Dot Points back-office">
            <ul class="pa-tabs">
                @foreach ([
                    'redemptions' => 'Redemption queue',
                    'rules' => 'Reward rules',
                    'rewards' => 'Rewards catalogue',
                    'ledger' => 'Ledger explorer',
                    'audit' => 'Audit log',
                ] as $name => $label)
                    <li><a href="{{ route('admin.points.'.$name) }}" @if ($section === $name) aria-current="page" @endif>{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>

        @if (session('status'))
            <div class="pa-flash" role="status">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="pa-flash pa-flash-error" role="alert">{{ session('error') }}</div>
        @endif
        @if ($errors->default->any())
            <div class="pa-flash pa-flash-error" role="alert">
                <strong>That was not saved.</strong>
                <ul>@foreach ($errors->default->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </div>
        @endif

        {{ $slot }}
    </div>
</x-app-layout>
