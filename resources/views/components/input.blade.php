@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'font-body border-[var(--line)] bg-[var(--panel)] text-[var(--ink)] focus:border-[var(--gold)] focus:ring-[var(--gold)] rounded-lg shadow-sm']) !!}>
