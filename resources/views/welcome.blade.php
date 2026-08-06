<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dot.Dopemine — Certified engagement mechanics for the Dot Ecosystem</title>
        <meta name="description" content="A catalog of progress surfaces, recognition mechanics, and habit-scaffolding tools — each certified against an acid test before any platform can deploy it. No dark patterns, ever.">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,500;0,6..72,600;0,6..72,700;1,6..72,500&family=Work+Sans:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            :root {
                --paper: #f4f3ef;
                --panel: #fbfaf7;
                --ink: #15171b;
                --ink-soft: #54565c;
                --gold: #c99a1a;
                --gold-bright: #e8b923;
                --rust: #a8462b;
                --rust-soft: #c96a4b;
                --line: rgba(21, 23, 27, 0.12);
                --font-display: 'Newsreader', ui-serif, Georgia, serif;
                --font-body: 'Work Sans', system-ui, sans-serif;
                --font-mono: 'Space Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); font-style: italic; }
            .font-display-upright { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }

            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }

            @media (prefers-reduced-motion: no-preference) {
                .reveal {
                    opacity: 0;
                    transform: translateY(14px);
                    transition: opacity 600ms var(--ease-out), transform 600ms var(--ease-out);
                }
                .reveal.is-visible { opacity: 1; transform: translateY(0); }
                .draw-path {
                    stroke-dasharray: 700;
                    stroke-dashoffset: 700;
                    transition: stroke-dashoffset 1500ms var(--ease-out);
                }
                .draw-path.is-visible { stroke-dashoffset: 0; }
            }
            @media (prefers-reduced-motion: reduce) {
                .reveal { opacity: 1; transform: none; }
                .draw-path { stroke-dashoffset: 0; }
            }

            @media (hover: hover) and (pointer: fine) {
                .row-hover:hover { background: rgba(21, 23, 27, 0.025); }
                .link-underline { background-size: 0% 1px; }
                .link-underline:hover { background-size: 100% 1px; }
            }
            .link-underline {
                background-image: linear-gradient(currentColor, currentColor);
                background-position: 0 100%;
                background-repeat: no-repeat;
                transition: background-size 220ms var(--ease-out);
            }
        </style>
    </head>
    <body class="antialiased">

        <!-- Nav -->
        <header
            x-data="{ scrolled: false, mobileMenuOpen: false }"
            @scroll.window="scrolled = window.pageYOffset > 24"
            :class="scrolled ? 'bg-[#f4f3ef]/95 backdrop-blur-md border-b border-[var(--line)]' : 'border-b border-transparent'"
            class="fixed top-0 left-0 right-0 z-50 transition-colors duration-300"
        >
            <nav class="max-w-[1400px] mx-auto px-5 sm:px-8 py-3 flex items-center justify-between">
                <a href="/" class="flex items-center press">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Dopemine" class="h-14 sm:h-[4.5rem] w-auto">
                </a>

                <div class="hidden md:flex items-center gap-8 font-mono text-[13px] tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="#how-it-works" class="link-underline hover:text-[var(--ink)] pb-0.5">How it works</a>
                    <a href="#prohibited" class="link-underline hover:text-[var(--ink)] pb-0.5">Prohibited list</a>
                </div>

                @if (Route::has('login'))
                    <div class="flex items-center gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="press flex items-center gap-2 px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--rust)] text-white text-sm font-display-upright font-semibold rounded-full transition-colors">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:block text-sm font-medium text-[var(--ink-soft)] hover:text-[var(--ink)] transition-colors">
                                Sign in
                            </a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="press px-5 py-2.5 bg-[var(--ink)] hover:bg-[var(--rust)] text-white text-sm font-display-upright font-semibold rounded-full transition-colors">
                                    Create account
                                </a>
                            @endif
                        @endauth

                        <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden press p-2 -mr-2 text-[var(--ink)]" aria-label="Toggle menu">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7h16M4 12h16M4 17h16"></path>
                                <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </nav>

            <div x-show="mobileMenuOpen"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="md:hidden border-t border-[var(--line)] bg-[var(--paper)]"
                 style="display: none;">
                <div class="flex flex-col px-5 py-4 gap-1 font-mono text-sm uppercase tracking-wide">
                    <a href="#how-it-works" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">How it works</a>
                    <a href="#prohibited" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Prohibited list</a>
                    @guest
                        <a href="{{ route('login') }}" class="px-3 py-2.5 text-[var(--ink-soft)] hover:text-[var(--ink)]">Sign in</a>
                    @endguest
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section class="relative pt-32 pb-16 sm:pb-24 px-5 sm:px-8 overflow-hidden">
            <!-- Photo: runners celebrating at a finish-line event, by RETRATO DEPORTIVO (@retratodeportivo), unsplash.com/photos/runners-celebrating-at-a-finish-line-event-qedI1eHsDFw -->
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1774050250283-2444f7d634d5?q=80&w=2400&auto=format&fit=crop');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(100deg, rgba(21,23,27,0.92) 0%, rgba(21,23,27,0.80) 32%, rgba(21,23,27,0.45) 55%, rgba(21,23,27,0.18) 75%, rgba(21,23,27,0.02) 92%);"></div>
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(21,23,27,0) 0%, rgba(21,23,27,0.10) 55%, rgba(21,23,27,0.35) 80%, #f4f3ef 100%);"></div>
            <div class="relative z-10 max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[1.05fr_0.95fr] gap-14 lg:gap-10 items-center">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-6">
                            Engagement &amp; motivation platform
                        </p>
                        <h1 class="font-display font-semibold text-4xl sm:text-5xl lg:text-6xl leading-[1.08] tracking-tight text-[var(--paper)] mb-6">
                            Every mechanic answers<br>one question first.
                        </h1>
                        <p class="text-lg text-[var(--paper)] leading-relaxed max-w-xl mb-10">
                            Dot.Dopemine is the ecosystem's catalog of certified engagement mechanics — progress surfaces, recognition, habit scaffolding — each recorded against an acid test before any platform can deploy it. Would we show this to the person it targets, with its intent labeled? A mechanic that fails doesn't get certified. Full stop.
                        </p>

                        @guest
                            <div class="flex flex-wrap items-center gap-4">
                                <a href="{{ route('register') }}" class="press px-7 py-3.5 bg-[var(--ink)] hover:bg-[var(--rust)] text-white font-display-upright font-semibold rounded-full transition-colors">
                                    Create account
                                </a>
                                <a href="#prohibited" class="press flex items-center gap-2 px-7 py-3.5 text-[var(--paper)] font-medium rounded-full border border-[rgba(244,243,239,0.35)] hover:border-[var(--rust-soft)] transition-colors">
                                    See what we won't build
                                </a>
                            </div>
                        @endguest
                    </div>

                    <!-- Signature element: the dopamine-molecule line diagram from the real logo mark, redrawn as hero line art -->
                    <div class="reveal" data-reveal>
                        <div class="relative rounded-[2rem] border border-[var(--line)] bg-[var(--panel)] p-6 sm:p-8 shadow-[0_30px_60px_-30px_rgba(21,23,27,0.25)]">
                            <div class="flex items-center justify-between mb-6 font-mono text-[11px] tracking-[0.12em] uppercase text-[var(--ink-soft)]">
                                <span>Mechanic Catalog</span>
                                <span>Acid test: passed</span>
                            </div>

                            <svg viewBox="0 0 400 300" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-auto" aria-hidden="true">
                                <rect x="0.5" y="0.5" width="399" height="299" rx="14" stroke="var(--line)" stroke-dasharray="4 4"/>
                                <!-- catechol ring + amine tail, echoing the logo's dopamine molecule icon -->
                                <path
                                    class="draw-path"
                                    data-reveal
                                    d="M150 90 L110 115 L110 165 L150 190 L190 165 L190 115 Z M190 115 L230 90 L270 115 M270 115 L270 145 M110 115 L70 90 M150 190 L150 230"
                                    stroke="var(--rust)"
                                    stroke-width="2.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                                <circle cx="150" cy="90" r="4" fill="var(--ink)"/>
                                <circle cx="110" cy="115" r="4" fill="var(--ink)"/>
                                <circle cx="110" cy="165" r="4" fill="var(--ink)"/>
                                <circle cx="150" cy="190" r="4" fill="var(--ink)"/>
                                <circle cx="190" cy="165" r="4" fill="var(--ink)"/>
                                <circle cx="190" cy="115" r="4" fill="var(--ink)"/>
                                <circle cx="230" cy="90" r="9" fill="var(--panel)" stroke="var(--gold-bright)" stroke-width="2.5"/>
                                <circle cx="270" cy="115" r="9" fill="var(--panel)" stroke="var(--gold-bright)" stroke-width="2.5"/>
                                <circle cx="270" cy="145" r="9" fill="var(--panel)" stroke="var(--gold-bright)" stroke-width="2.5"/>
                                <circle cx="70" cy="90" r="9" fill="var(--panel)" stroke="var(--gold-bright)" stroke-width="2.5"/>
                                <circle cx="150" cy="230" r="9" fill="var(--panel)" stroke="var(--gold-bright)" stroke-width="2.5"/>
                            </svg>

                            <div class="flex items-center gap-3 mt-6 pt-6 border-t border-[var(--line)]">
                                <span class="font-mono text-[11px] tracking-wide text-[var(--ink-soft)]">Category: milestone recognition · Status: certified</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- How it works -->
        <section id="how-it-works" class="py-24 sm:py-28 px-5 sm:px-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="max-w-2xl mb-16 reveal" data-reveal>
                    <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold)] mb-4">How it works</p>
                    <h2 class="font-display-upright font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight">
                        Three moving parts, deliberately kept small
                    </h2>
                </div>

                <div class="grid md:grid-cols-3 border-t border-[var(--line)]">
                    @php
                        $parts = [
                            ['tag' => 'Catalog', 'title' => 'Mechanic Catalog', 'body' => 'The certified inventory of engagement mechanics — progress bars, milestone recognition, mastery paths — each carrying a recorded acid-test verdict and a revocable certification status.'],
                            ['tag' => 'Gate', 'title' => 'Ethics gate', 'body' => "Certification isn't a checkbox: the model itself refuses to persist a certified status unless the acid test passed, and refuses to let a deployment start against anything that isn't certified."],
                            ['tag' => 'Ledger', 'title' => 'Wellbeing & Outcome Ledger', 'body' => 'Every deployment is measured in pairs. Engagement movement is never published or acted on alone — only alongside the outcome metric the mechanic was meant to serve.'],
                        ];
                    @endphp
                    @foreach ($parts as $i => $p)
                        <div class="row-hover border-b border-[var(--line)] {{ $i < 2 ? 'md:border-r' : '' }} px-1 py-8 sm:py-10 transition-colors reveal" data-reveal>
                            <p class="font-mono text-[11px] tracking-[0.14em] uppercase text-[var(--rust)] mb-3">{{ $p['tag'] }}</p>
                            <h3 class="font-display-upright font-semibold text-xl text-[var(--ink)] mb-2.5">{{ $p['title'] }}</h3>
                            <p class="text-[var(--ink-soft)] leading-relaxed">{{ $p['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <!-- Prohibited list -->
        <section id="prohibited" class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--panel)] border-y border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--rust)] mb-4">What we will not build</p>
                        <h2 class="font-display-upright font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                            The prohibited list isn't a footnote. It's the flagship artifact.
                        </h2>
                        <p class="text-[var(--ink-soft)] leading-relaxed max-w-sm">
                            It's where our restraint becomes legible to everyone else in the ecosystem. Additions go through Ethics Officer approval; removals require full governance review — deliberately harder than adding.
                        </p>
                    </div>

                    <div class="border-t border-[var(--line)]">
                        @php
                            $prohibited = [
                                ['pattern' => 'Raw engagement volume as a target', 'why' => 'Dwell time, session count, opens — rewards attention captured, not outcomes achieved.'],
                                ['pattern' => 'Individual streaks with loss framing', 'why' => 'Manufactures compulsion via loss aversion rather than progress.'],
                                ['pattern' => 'Person-vs-person leaderboards on rate metrics', 'why' => 'Rewards speed over safety and quality.'],
                                ['pattern' => 'Variable-ratio reward schedules', 'why' => 'Slot-machine mechanics — never deployed, blocked at the catalog.'],
                                ['pattern' => 'Abandonment / re-engagement pressure nudges', 'why' => 'Exploits incompleteness anxiety instead of genuine value.'],
                            ];
                        @endphp
                        @foreach ($prohibited as $item)
                            <div class="row-hover border-b border-[var(--line)] px-1 py-6 flex gap-4 transition-colors reveal" data-reveal>
                                <span class="font-mono text-[var(--rust)] text-sm mt-0.5 shrink-0">✕</span>
                                <div>
                                    <h3 class="font-display-upright font-semibold text-base text-[var(--ink)] mb-1">{{ $item['pattern'] }}</h3>
                                    <p class="text-sm text-[var(--ink-soft)] leading-relaxed">{{ $item['why'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- Ecosystem -->
        <section class="py-24 sm:py-28 px-5 sm:px-8 bg-[var(--ink)]">
            <div class="max-w-[1400px] mx-auto">
                <div class="grid lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] gap-12 lg:gap-20">
                    <div class="reveal" data-reveal>
                        <p class="font-mono text-xs tracking-[0.18em] uppercase text-[var(--gold-bright)] mb-4">Part of the Dot Ecosystem</p>
                        <h2 class="font-display-upright font-semibold text-3xl sm:text-4xl text-white leading-tight mb-5">
                            We hold ourselves to the rule we publish for everyone else
                        </h2>
                        <p class="text-[#c7c5c1] leading-relaxed max-w-sm">
                            An observation pack pairing engagement movement with outcome movement is publishable. Engagement movement alone is rejected at ingestion — the same prohibited-metric rule applies to us.
                        </p>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-x-10">
                        @php
                            $capabilities = [
                                ['title' => 'Structurally enforced gate', 'body' => 'Certification and deployment refuse to persist outside the ethics gate — not a validator, a model-level constraint.'],
                                ['title' => 'Team-based deployments', 'body' => 'The catalog is shared and global; which team is running which mechanic is tracked per team.'],
                                ['title' => 'Knowledge Pack publishing', 'body' => 'Observation, insight, outcome, and incident packs, published to Dot.Brain on a fixed cadence.'],
                                ['title' => 'Ecosystem single sign-on', 'body' => 'A one-time Sanctum token minted elsewhere in the ecosystem logs you straight into your dashboard.'],
                            ];
                        @endphp
                        @foreach ($capabilities as $c)
                            <div class="py-6 border-t border-[rgba(255,255,255,0.14)] reveal" data-reveal>
                                <h3 class="font-display-upright font-medium text-base text-white mb-1.5">{{ $c['title'] }}</h3>
                                <p class="text-sm text-[#c7c5c1] leading-relaxed">{{ $c['body'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative py-28 sm:py-36 px-5 sm:px-8 overflow-hidden">
            <div class="relative z-10 max-w-2xl mx-auto text-center reveal" data-reveal>
                <h2 class="font-display-upright font-semibold text-3xl sm:text-4xl text-[var(--ink)] leading-tight mb-5">
                    Bring a mechanic in. We'll run the acid test.
                </h2>
                <p class="text-[var(--ink-soft)] leading-relaxed mb-10 max-w-lg mx-auto">
                    Sign in with your Dot Ecosystem account or create one to browse the certified catalog.
                </p>

                @guest
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="{{ route('register') }}" class="press px-8 py-3.5 bg-[var(--ink)] hover:bg-[var(--rust)] text-white font-display-upright font-semibold rounded-full transition-colors">
                            Create account
                        </a>
                        <a href="{{ route('login') }}" class="press px-8 py-3.5 text-[var(--ink)] font-medium rounded-full border border-[var(--line)] hover:border-[var(--rust-soft)] transition-colors">
                            Sign in
                        </a>
                    </div>
                @endguest
            </div>
        </section>

        <!-- Footer -->
        <footer class="py-14 px-5 sm:px-8 border-t border-[var(--line)]">
            <div class="max-w-[1400px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                <a href="/" class="flex items-center">
                    <img src="{{ asset('images/logo.png') }}" alt="Dot.Dopemine" class="h-11 w-auto opacity-90">
                </a>
                <div class="flex items-center gap-6 font-mono text-xs tracking-wide uppercase text-[var(--ink-soft)]">
                    <a href="{{ route('policy.show') }}" class="hover:text-[var(--ink)] transition-colors">Privacy</a>
                    <a href="{{ route('cookies') }}" class="hover:text-[var(--ink)] transition-colors">Cookies</a>
                    <a href="{{ route('terms.show') }}" class="hover:text-[var(--ink)] transition-colors">Terms</a>
                </div>
                <p class="font-mono text-xs tracking-wide text-[var(--ink-soft)]">
                    &copy; {{ date('Y') }} Dot.Dopemine. Engagement and motivation platform for the Dot Ecosystem.
                </p>
            </div>
        </footer>

        <script>
            if (window.matchMedia('(prefers-reduced-motion: no-preference)').matches && 'IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                            io.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
                document.querySelectorAll('[data-reveal]').forEach((el) => io.observe(el));
            } else {
                document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'));
            }
        </script>
    </body>
</html>
