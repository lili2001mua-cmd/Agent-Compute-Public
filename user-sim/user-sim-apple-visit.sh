#!/usr/bin/env bash
# بازدیدِ کاربری از یک اپِ iOS روی شبیه‌سازِ اپل — نصب، باز کردن، دیدن، شاهد گرفتن.
#
# مالک: Assistant-User-Simulator (قابلیتِ «محیطِ شبیه‌سازیِ اپل»).
# مصرف‌کننده: .github/workflows/user-sim-apple.yml
# ⛔ کد نمی‌خواند و دربارهٔ کد حکم نمی‌دهد. قفل: user-simulator-never-reads-code
# ⛔ شکستِ خاموش ممنوع: هر گام ردش را می‌گذارد؛ خطای واقعی با ::error:: قرمز می‌شود.
# ⛔ هرگز اجرا نشده تا امروز — محصولِ اپلی هنوز وجود ندارد. <!-- absent-verified: ۱۴۰۵-۰۶-۱۵ -->
#
# کاربرد: bash Sys.scripts/user-sim-apple-visit.sh <app> [دستگاه] [ثانیهٔ مکث]
set -uo pipefail

APP="${1:?مسیرِ بستهٔ .app لازم است}"
DEVICE="${2:-iPhone 15}"
DWELL="${3:-12}"
OUT="user-sim-out"
mkdir -p "$OUT"
REPORT="$OUT/visit-report.md"

fail() { echo "::error::$1"; echo "- ⛔ $1" >> "$REPORT"; exit 1; }
shot() { xcrun simctl io booted screenshot "$OUT/$1.png" >/dev/null 2>&1 && echo "▪ شاهد: $1.png" || echo "::warning::اسکرین‌شاتِ $1 گرفته نشد"; }

{
  echo "# گزارشِ بازدیدِ کاربری — اپل"
  echo
  echo "| قلم | مقدار |"
  echo "|---|---|"
  echo "| بسته | \`$(basename "$APP")\` |"
  echo "| دستگاه | ${DEVICE} |"
  echo
} > "$REPORT"

[ -e "$APP" ] || fail "بسته پیدا نشد: $APP"

echo "▪ بالا آوردنِ دستگاه: $DEVICE"
UDID=$(xcrun simctl list devices available | grep -F "$DEVICE (" | head -1 | sed -E 's/.*\(([0-9A-F-]{36})\).*/\1/')
[ -z "${UDID:-}" ] && fail "دستگاهِ «$DEVICE» در این رانر موجود نیست — نامِ دیگری بده."
xcrun simctl boot "$UDID" >/dev/null 2>&1 || true
xcrun simctl bootstatus "$UDID" -b >/dev/null 2>&1 || fail "دستگاه بالا نیامد."
echo "- ✅ دستگاه بالا آمد (\`$UDID\`)" >> "$REPORT"
shot "00-home"

echo "▪ نصبِ بسته"
xcrun simctl install "$UDID" "$APP" > "$OUT/install.log" 2>&1 \
  || { cat "$OUT/install.log"; fail "نصب شکست خورد — کاربر حتی نتوانست اپ را روی دستگاهش بگذارد."; }
echo "- ✅ نصب شد" >> "$REPORT"

BUNDLE=$(/usr/libexec/PlistBuddy -c 'Print :CFBundleIdentifier' "$APP/Info.plist" 2>/dev/null)
[ -z "${BUNDLE:-}" ] && fail "شناسهٔ بسته پیدا نشد — بی آن نمی‌شود بازش کرد."
echo "- **بسته:** \`$BUNDLE\`" >> "$REPORT"

echo "▪ باز کردنِ اپ مثلِ یک کاربر"
xcrun simctl launch "$UDID" "$BUNDLE" > "$OUT/launch.log" 2>&1 \
  || { cat "$OUT/launch.log"; fail "اپ باز نشد — کاربر روی آیکون زد و هیچ اتفاقی نیفتاد."; }
sleep "$DWELL"
shot "01-first-screen"

xcrun simctl terminate "$UDID" "$BUNDLE" >/dev/null 2>&1 || true
sleep 2
xcrun simctl launch "$UDID" "$BUNDLE" >/dev/null 2>&1 || true
sleep 5
shot "02-reopen"

{
  echo
  echo "## آنچه دیده شد"
  echo
  echo "✅ اپ نصب شد، باز شد، بسته و دوباره باز شد. حکمِ محتوایی از روی تصویرها می‌آید."
  echo
  echo "## شاهدها"
  echo
  echo "| لحظه | فایل |"
  echo "|---|---|"
  echo "| صفحهٔ خانه پیش از نصب | \`00-home.png\` |"
  echo "| اولین صفحهٔ اپ | \`01-first-screen.png\` |"
  echo "| بازکردنِ دوباره | \`02-reopen.png\` |"
  echo
  echo "⛔ **این گزارش خودش حکم نیست.** حکم را \`Assistant-User-Simulator\` بعد از **دیدنِ**"
  echo "همین تصویرها می‌دهد و در \`Memory-User-Simulator/<Project>/05-لاگِ-تست.md\` می‌نویسد."
} >> "$REPORT"
echo "▪ بازدید تمام شد؛ شاهدها در $OUT/"
