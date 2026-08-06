<button {{ $attributes->merge(['type' => 'submit', 'class' => 'press inline-flex items-center px-7 py-3.5 bg-[var(--ink)] hover:bg-[var(--rust)] text-white font-display-upright font-semibold rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-[var(--gold)] focus:ring-offset-2 disabled:opacity-50']) }}>
    {{ $slot }}
</button>
