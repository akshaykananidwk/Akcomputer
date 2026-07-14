<?php
// Minimal self-hosted line-icon set (no icon font / CDN - keeps the app
// dependency-free like the rest of this project). Feather-style: 24x24
// viewBox, stroke-based, currentColor so CSS controls the color.
function icon($name, $size = 20) {
    $paths = [
        'home' => '<polyline points="3 10 12 3 21 10"/><path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10"/>',
        'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="9" y1="12" x2="15" y2="12"/>',
        'box' => '<path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v10l9 5 9-5V8"/><line x1="12" y1="13" x2="12" y2="23"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 20v-1.5a5 5 0 0 1 5-5h1a5 5 0 0 1 5 5V20"/><circle cx="17.5" cy="9" r="2.4"/><path d="M15.7 20v-1.3a4 4 0 0 1 5.8-3.6"/>',
        'truck' => '<rect x="1" y="7" width="13" height="9" rx="1"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17.5" cy="18" r="1.6"/>',
        'bar-chart' => '<line x1="6" y1="20" x2="6" y2="12"/><line x1="12" y1="20" x2="12" y2="6"/><line x1="18" y1="20" x2="18" y2="15"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="16.5" cy="14" r="1.2"/>',
        'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
        'return' => '<path d="M4 12a8 8 0 1 1 2.3 5.6"/><polyline points="4 17 4 12 9 12"/>',
        'gear' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 5.2 2 7.5 2 7.5H4S6 14.2 6 9z"/><path d="M10.5 19a1.7 1.7 0 0 0 3 0"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><line x1="20" y1="20" x2="15.8" y2="15.8"/>',
        'chevron-right' => '<polyline points="9 6 15 12 9 18"/>',
        'log-out' => '<path d="M9 20H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h4"/><polyline points="16 16 20 12 16 8"/><line x1="20" y1="12" x2="9" y2="12"/>',
        'grid' => '<rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/>',
        'tool' => '<circle cx="7" cy="7" r="3.3"/><rect x="10.8" y="9.5" width="2.4" height="11" rx="1.2" transform="rotate(45 12 15)"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/><path d="M12 3a13.5 13.5 0 0 1 3.2 9A13.5 13.5 0 0 1 12 21 13.5 13.5 0 0 1 8.8 12 13.5 13.5 0 0 1 12 3z"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="13" rx="2"/><path d="M15 20V6a1.6 1.6 0 0 0-1.6-1.6h-2.8A1.6 1.6 0 0 0 9 6v14"/>',
        'archive' => '<rect x="2" y="4" width="20" height="4.5" rx="1"/><path d="M4 8.5V19a1.5 1.5 0 0 0 1.5 1.5h13A1.5 1.5 0 0 0 20 19V8.5"/><line x1="10" y1="12.5" x2="14" y2="12.5"/>',
        'menu' => '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'handshake' => '<polyline points="17 3 21 7 17 11"/><path d="M21 7H9a4 4 0 0 0-4 4"/><polyline points="7 21 3 17 7 13"/><path d="M3 17h12a4 4 0 0 0 4-4"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1"/><line x1="9" y1="7" x2="9" y2="7.01"/><line x1="15" y1="7" x2="15" y2="7.01"/><line x1="9" y1="11" x2="9" y2="11.01"/><line x1="15" y1="11" x2="15" y2="11.01"/><line x1="9" y1="15" x2="9" y2="15.01"/><line x1="15" y1="15" x2="15" y2="15.01"/>',
        'arrow-down' => '<line x1="12" y1="5" x2="12" y2="19"/><polyline points="6 13 12 19 18 13"/>',
        'arrow-up' => '<line x1="12" y1="19" x2="12" y2="5"/><polyline points="6 11 12 5 18 11"/>',
        'book' => '<path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v17H6.5A2.5 2.5 0 0 0 4 21.5z"/><path d="M4 4.5v17"/><line x1="20" y1="19" x2="6.5" y2="19"/>',
    ];
    $body = $paths[$name] ?? $paths['grid'];
    return '<svg class="ico-svg" width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
}
