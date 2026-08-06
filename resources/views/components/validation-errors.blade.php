@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-[rgba(168,70,43,0.35)] bg-[rgba(168,70,43,0.06)] px-4 py-3']) }}>
        <div class="font-body font-medium text-sm text-[var(--rust)]">{{ __('Whoops! Something went wrong.') }}</div>

        <ul class="mt-2 list-disc list-inside text-sm text-[var(--rust)]">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
