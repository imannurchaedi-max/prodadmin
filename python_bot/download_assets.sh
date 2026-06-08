#!/usr/bin/env bash
# Download semua library lokal ke assets/
# Jalankan sekali dari root project.
set -e

ASSETS="assets"
mkdir -p "$ASSETS/webfonts"
mkdir -p "$ASSETS/fonts"

echo "=== Downloading Bootstrap 5.3.0 ==="
curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" -o "$ASSETS/bootstrap.min.css"
curl -sL "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" -o "$ASSETS/bootstrap.bundle.min.js"

echo "=== Downloading SweetAlert2 11 ==="
curl -sL "https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js" -o "$ASSETS/sweetalert2.all.min.js"

echo "=== Downloading Chart.js ==="
curl -sL "https://cdn.jsdelivr.net/npm/chart.js/dist/chart.umd.min.js" -o "$ASSETS/chart.min.js"

echo "=== Downloading Sortable.js 1.15.0 ==="
curl -sL "https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" -o "$ASSETS/Sortable.min.js"

echo "=== Downloading xlsx (SheetJS) ==="
curl -sL "https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js" -o "$ASSETS/xlsx.full.min.js"

echo "=== Downloading html2canvas 1.4.1 ==="
curl -sL "https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js" -o "$ASSETS/html2canvas.min.js"

echo "=== Downloading Animate.css 4.1.1 ==="
curl -sL "https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css" -o "$ASSETS/animate.min.css"

echo "=== Downloading Font Awesome 6.4.0 ==="
curl -sL "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" -o "$ASSETS/fontawesome.min.css"

# Webfonts (font files yang dipakai FontAwesome)
FA_BASE="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts"
for f in fa-brands-400.woff2 fa-regular-400.woff2 fa-solid-900.woff2 fa-v4compatibility.woff2; do
    echo "  webfont: $f"
    curl -sL "$FA_BASE/$f" -o "$ASSETS/webfonts/$f"
done

# Fix path webfonts di fontawesome.min.css agar relatif ke assets/webfonts/
sed -i 's|../webfonts/|webfonts/|g' "$ASSETS/fontawesome.min.css"

echo "=== Downloading Inter font (subset) ==="
# Download Inter dari Google Fonts API sebagai file woff2
curl -sL "https://fonts.gstatic.com/s/inter/v13/UcCO3FwrK3iLTeHuS_fvQtMwCp50KnMw2boKoduKmMEVuLyfAZ9hiA.woff2" -o "$ASSETS/fonts/Inter-300.woff2"
curl -sL "https://fonts.gstatic.com/s/inter/v13/UcCO3FwrK3iLTeHuS_fvQtMwCp50KnMw2boKoduKmMEVuI6fAZ9hiA.woff2" -o "$ASSETS/fonts/Inter-400.woff2"
curl -sL "https://fonts.gstatic.com/s/inter/v13/UcCO3FwrK3iLTeHuS_fvQtMwCp50KnMw2boKoduKmMEVuFuYAZ9hiA.woff2" -o "$ASSETS/fonts/Inter-500.woff2"
curl -sL "https://fonts.gstatic.com/s/inter/v13/UcCO3FwrK3iLTeHuS_fvQtMwCp50KnMw2boKoduKmMEVuGKYAZ9hiA.woff2" -o "$ASSETS/fonts/Inter-600.woff2"
curl -sL "https://fonts.gstatic.com/s/inter/v13/UcCO3FwrK3iLTeHuS_fvQtMwCp50KnMw2boKoduKmMEVuDyYAZ9hiA.woff2" -o "$ASSETS/fonts/Inter-700.woff2"
curl -sL "https://fonts.gstatic.com/s/inter/v13/UcCO3FwrK3iLTeHuS_fvQtMwCp50KnMw2boKoduKmMEVuBCYAZ9hiA.woff2" -o "$ASSETS/fonts/Inter-800.woff2"

echo ""
echo "=== Selesai. Verifikasi file: ==="
ls -lh "$ASSETS"/*.min.css "$ASSETS"/*.min.js "$ASSETS"/*.js 2>/dev/null || true
ls -lh "$ASSETS/webfonts/"*.woff2 2>/dev/null || true
ls -lh "$ASSETS/fonts/"*.woff2 2>/dev/null || true
