@props(['at'])
@if ($at)<time datetime="{{ $at->copy()->utc()->toIso8601String() }}" title="{{ $at->copy()->utc()->format('Y-m-d H:i:s') }} UTC">{{ $at->copy()->utc()->format('Y-m-d H:i') }}</time>@else<span aria-label="none">&mdash;</span>@endif
