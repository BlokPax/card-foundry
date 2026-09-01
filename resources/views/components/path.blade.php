@props(['path', 'display' => null])

{{-- A filesystem path as a visible token: the tail always readable, the
     front truncating, the full path on hover. Pass `display` to show a
     friendlier form (e.g. the manifest-relative path) while keeping the
     absolute path in the tooltip. Clicking copies the absolute path. --}}
@php $shown = $display ?? $path; @endphp
<span {{ $attributes->merge(['class' => 'path-token cursor-pointer']) }}
      x-data="{ copied: false }"
      data-path="{{ $path }}"
      x-on:click="navigator.clipboard?.writeText($el.dataset.path); copied = true; setTimeout(() => copied = false, 1200)"
      title="Click to copy · {{ $path }}">
    @if (str_contains($shown, '/'))
        <span class="min-w-0 truncate text-fg-subtle">{{ dirname($shown) }}/</span><span class="shrink-0 font-medium text-fg">{{ basename($shown) }}</span>
    @else
        <span class="shrink-0 font-medium text-fg">{{ $shown }}</span>
    @endif
    <span x-show="copied" x-cloak class="shrink-0 pl-1 text-[10px] font-semibold text-ok-fg">copied</span>
</span>
