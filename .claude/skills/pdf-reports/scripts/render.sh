#!/usr/bin/env bash
# Render every FD Commander PDF with page-filling data, then rasterize each page
# and build one contact sheet per report so the layout can be checked by eye.
#
# Usage (from either checkout's root):
#   .claude/skills/pdf-reports/scripts/render.sh [--short] [--filter <name>] [--dpi <n>]
#
#   --short       a few rows per report instead of several pages' worth
#   --filter      only the matching test: "submission", "WFD" or "equipment"
#   --dpi         raster resolution for the page PNGs (default 50; 70+ to read text)
#
# Output goes to storage/app/pdf-render/ (git-ignored):
#   <report>.pdf, <report>-NN.png (one per page), <report>.txt (text per page),
#   sheet-<report>.png (all pages side by side; needs python3 with Pillow on the host).
set -euo pipefail

long=1 filter="" dpi=50
while [[ $# -gt 0 ]]; do
    case "$1" in
        --short) long=0; shift ;;
        --filter) filter="$2"; shift 2 ;;
        --dpi) dpi="$2"; shift 2 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

root="$(git rev-parse --show-toplevel)"
cd "$root"
skill_dir="$root/.claude/skills/pdf-reports/scripts"
test_file="tests/Feature/ZzPdfRenderTest.php"
out_rel="storage/app/pdf-render"

rm -rf "$out_rel" && mkdir -p "$out_rel"
cp "$skill_dir/RenderPdfsTest.php" "$test_file"
trap 'rm -f "$root/$test_file"' EXIT

# Inside the container the repo is /var/www/html, so the output path is the same
# relative path on both sides.
inner=$(cat <<EOF
set -e
php artisan test --compact ${filter:+--filter="$filter"} $test_file
cd $out_rel
for f in *.pdf; do
    [ -e "\$f" ] || continue
    b=\${f%.pdf}
    gs -q -dNOPAUSE -dBATCH -sDEVICE=png16m -r$dpi -o "\$b-%02d.png" "\$f"
    gs -q -dNOPAUSE -dBATCH -sDEVICE=txtwrite -o "\$b.txt" "\$f" 2>/dev/null || true
done
EOF
)

envs=(-e DEMO_MODE=false -e DB_CONNECTION=sqlite -e PDF_RENDER_OUT="/var/www/html/$out_rel" -e PDF_RENDER_LONG="$long")

if docker inspect fielddaycommander-laravel.test-1 --format '{{range .Mounts}}{{.Source}} {{end}}' 2>/dev/null | grep -q "$root "; then
    # The demo worktree: use the running Sail container, as the sail user.
    docker exec -u sail "${envs[@]}" fielddaycommander-laravel.test-1 sh -c "$inner"
else
    # The main checkout: a one-off container.
    docker run --rm -v "$root":/var/www/html -w /var/www/html -u 1000:1000 "${envs[@]}" \
        --entrypoint sh sail-8.4/app -c "$inner"
fi

python3 - "$out_rel" <<'PY' || echo "(contact sheets skipped: needs python3 with Pillow)" >&2
import glob, os, sys
from PIL import Image
out = sys.argv[1]
for pdf in sorted(glob.glob(f"{out}/*.pdf")):
    base = pdf[:-4]
    pages = sorted(glob.glob(f"{base}-[0-9][0-9].png"))
    ims = [Image.open(p) for p in pages]
    if not ims:
        continue
    gap = 12
    sheet = Image.new("RGB", (sum(i.width for i in ims) + gap * (len(ims) - 1), max(i.height for i in ims)), (90, 90, 90))
    x = 0
    for im in ims:
        sheet.paste(im, (x, 0))
        x += im.width + gap
    sheet.save(f"{out}/sheet-{os.path.basename(base)}.png")
PY

echo
shopt -s nullglob
for pdf in "$out_rel"/*.pdf; do
    name="$(basename "${pdf%.pdf}")"
    pages=("$out_rel/$name"-[0-9][0-9].png)
    sheet=""
    if [[ -e "$out_rel/sheet-$name.png" ]]; then
        sheet="$root/$out_rel/sheet-$name.png"
    fi
    printf '%-40s %2d pages  %s\n' "$name.pdf" "${#pages[@]}" "$sheet"
done
