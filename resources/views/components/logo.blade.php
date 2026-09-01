@props(['size' => 'size-6'])

{{-- The Imagination Mining Co. pickaxe logomark, used as a mask so it can be
     painted with theme colors (the navy original would vanish in dark mode). --}}
<span {{ $attributes->merge(['class' => $size.' inline-block shrink-0 bg-gradient-to-br from-accent-from to-accent-to']) }}
      style="-webkit-mask-image: url('{{ asset('images/logomark.png') }}'); mask-image: url('{{ asset('images/logomark.png') }}'); -webkit-mask-size: contain; mask-size: contain; -webkit-mask-repeat: no-repeat; mask-repeat: no-repeat; -webkit-mask-position: center; mask-position: center;"
      role="img" aria-label="Imagination Mining Company"></span>
