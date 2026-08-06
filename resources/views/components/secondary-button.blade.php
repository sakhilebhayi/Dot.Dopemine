<button {{ $attributes->merge(['type' => 'button', 'class' => 'press inline-flex items-center px-7 py-3.5 bg-transparent border border-[var(--line)] rounded-full font-display-upright font-medium text-[var(--ink)] hover:border-[var(--rust-soft)] focus:outline-none focus:ring-2 focus:ring-[var(--gold)] focus:ring-offset-2 disabled:opacity-25 transition-colors']) }}>
    {{ $slot }}
</button>
