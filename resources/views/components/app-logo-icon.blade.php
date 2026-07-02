{{-- Single SVG; light/dark is handled by swapping CSS variables via the `dark:` variant --}}
<svg
    {{ $attributes->merge(['class' => '
        [--w-back-a:#1c2740] [--w-back-b:#131b2c]
        [--w-front-a:#35476e] [--w-front-b:#22304a]
        [--w-strap:#26334e] [--w-rim-o:0.07] [--w-lip-o:0.12] [--w-strap-hl-o:0.10]
        dark:[--w-back-a:#46578a] dark:[--w-back-b:#333f63]
        dark:[--w-front-a:#66799f] dark:[--w-front-b:#4a5a80]
        dark:[--w-strap:#55668f] dark:[--w-rim-o:0.14] dark:[--w-lip-o:0.18] dark:[--w-strap-hl-o:0.16]
    ']) }}
    viewBox="56 128 400 302" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="backGrad" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" style="stop-color: var(--w-back-a)"/>
      <stop offset="100%" style="stop-color: var(--w-back-b)"/>
    </linearGradient>
    <linearGradient id="frontGrad" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" style="stop-color: var(--w-front-a)"/>
      <stop offset="100%" style="stop-color: var(--w-front-b)"/>
    </linearGradient>
    <radialGradient id="btnGrad" cx="40%" cy="35%" r="70%">
      <stop offset="0%" stop-color="#f8dc8c"/>
      <stop offset="100%" stop-color="#b8892e"/>
    </radialGradient>
  </defs>

  {{-- Back panel --}}
  <path d="M70,180 a40,40 0 0 1 40,-40 h292 a40,40 0 0 1 40,40 v200 h-372 z" fill="url(#backGrad)"/>
  <path d="M78,180 a32,32 0 0 1 32,-32 h292 a32,32 0 0 1 30,21" fill="none" stroke="#ffffff" style="stroke-opacity: var(--w-rim-o)" stroke-width="3"/>

  {{-- Card fan --}}
  <rect x="118" y="160" width="290" height="170" rx="18" fill="#7d5fff"/>
  <rect x="108" y="178" width="290" height="170" rx="18" fill="#000000" fill-opacity="0.18"/>
  <rect x="108" y="184" width="290" height="170" rx="18" fill="#2fbf8f"/>
  <rect x="98" y="202" width="290" height="170" rx="18" fill="#000000" fill-opacity="0.18"/>
  <rect x="98" y="208" width="290" height="170" rx="18" fill="#f5a623"/>
  <rect x="88" y="226" width="290" height="170" rx="18" fill="#000000" fill-opacity="0.18"/>
  <rect x="88" y="232" width="290" height="170" rx="18" fill="#e94f4f"/>

  {{-- Front pocket --}}
  <path d="M70,252 h372 v10 h-372 z" fill="#000000" fill-opacity="0.22"/>
  <path d="M70,258 h372 v122 a40,40 0 0 1 -40,40 h-292 a40,40 0 0 1 -40,-40 z" fill="url(#frontGrad)"/>
  <path d="M70,258 h372 v3 h-372 z" fill="#ffffff" style="fill-opacity: var(--w-lip-o)"/>
  <path d="M86,274 h340 v92 a28,28 0 0 1 -28,28 h-284 a28,28 0 0 1 -28,-28 z"
        fill="none" stroke="#ffffff" stroke-opacity="0.14" stroke-width="3"
        stroke-dasharray="2 11" stroke-linecap="round"/>

  {{-- Strap and clasp --}}
  <path d="M442,296 h-88 a40,40 0 0 0 0,80 h88 z" fill="#000000" fill-opacity="0.25" transform="translate(-4,6)"/>
  <path d="M442,290 h-88 a40,40 0 0 0 0,80 h88 z" style="fill: var(--w-strap)"/>
  <path d="M442,290 h-88 a40,40 0 0 0 -37,25" fill="none" stroke="#ffffff" style="stroke-opacity: var(--w-strap-hl-o)" stroke-width="3"/>
  <circle cx="354" cy="330" r="23" fill="#000000" fill-opacity="0.25"/>
  <circle cx="352" cy="328" r="21" fill="url(#btnGrad)"/>
  <circle cx="352" cy="328" r="13" fill="none" stroke="#00000033" stroke-width="3"/>
</svg>
