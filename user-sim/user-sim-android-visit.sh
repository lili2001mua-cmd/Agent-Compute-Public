#!/usr/bin/env bash
# بازدیدِ کاربری از یک اپِ اندرویدی روی شبیه‌ساز — نصب، باز کردن، دیدن، شاهد گرفتن.
#
# مالک: Assistant-User-Simulator (قابلیتِ «محیطِ شبیه‌سازیِ اندروید»).
# مصرف‌کننده: .github/workflows/user-sim-android.yml — این اسکریپت داخلِ گامِ
#   reactivecircus/android-emulator-runner اجرا می‌شود، یعنی شبیه‌ساز از قبل بالا آمده.
#
# ⛔ این اسکریپت کد نمی‌خواند و دربارهٔ کد حکم نمی‌دهد. فقط کاری را می‌کند که یک آدم
#    می‌کند: نصب می‌کند، باز می‌کند، نگاه می‌کند، عکس می‌گیرد.
#    قفل: user-simulator-never-reads-code
#
# ⛔ شکستِ خاموش ممنوع: هر گام ردش را با echo می‌گذارد، و خطای واقعی با ::error::
#    اجرا را قرمز می‌کند. «باز نشد» و «نتوانستم نصب کنم» دو چیزِ متفاوت‌اند.
#
# کاربرد: bash Sys.scripts/user-sim-android-visit.sh <apk> [ثانیهٔ مکث] [یادداشت]
set -uo pipefail

APK="${1:?مسیرِ apk لازم است}"
DWELL="${2:-12}"
NOTE="${3:-}"
OUT="user-sim-out"
mkdir -p "$OUT"
REPORT="$OUT/visit-report.md"

fail() { echo "::error::$1"; echo "- ⛔ $1" >> "$REPORT"; exit 1; }
shot() { adb exec-out screencap -p > "$OUT/$1.png" 2>/dev/null && echo "▪ شاهد: $1.png" || echo "::warning::اسکرین‌شاتِ $1 گرفته نشد"; }

{
  echo "# گزارشِ بازدیدِ کاربری — اندروید"
  echo
  echo "| قلم | مقدار |"
  echo "|---|---|"
  echo "| بستهٔ نصبی | \`$(basename "$APK")\` |"
  echo "| مکثِ بعد از باز شدن | ${DWELL} ثانیه |"
  echo "| یادداشتِ سناریو | ${NOTE:-—} |"
  echo
} > "$REPORT"

echo "▪ منتظرِ آماده‌شدنِ دستگاه"
adb wait-for-device || fail "دستگاهِ شبیه‌سازی‌شده اصلاً بالا نیامد."

echo "▪ نصبِ بسته"
if ! adb install -r -g "$APK" > "$OUT/install.log" 2>&1; then
  cat "$OUT/install.log"
  fail "نصب شکست خورد — کاربر حتی نتوانست اپ را روی دستگاهش بگذارد. متنِ کامل در install.log ."
fi
echo "- ✅ نصب شد" >> "$REPORT"
echo "▪ نصب شد"

PKG=$(unzip -p "$APK" AndroidManifest.xml 2>/dev/null | strings | grep -oE '[a-z][a-z0-9_]*(\.[a-z0-9_]+){2,}' | head -1)
if [ -z "${PKG:-}" ]; then
  PKG=$(adb shell pm list packages -3 | sed 's/package://' | tr -d '\r' | tail -1)
fi
[ -z "${PKG:-}" ] && fail "نامِ بستهٔ اپ پیدا نشد — بی آن نمی‌شود بازش کرد."
echo "▪ بسته: $PKG"
echo "- **بسته:** \`$PKG\`" >> "$REPORT"

adb logcat -c 2>/dev/null || true
shot "00-launcher"

echo "▪ باز کردنِ اپ مثلِ یک کاربر"
if ! adb shell monkey -p "$PKG" -c android.intent.category.LAUNCHER 1 > "$OUT/launch.log" 2>&1; then
  cat "$OUT/launch.log"
  fail "اپ باز نشد — کاربر روی آیکون زد و هیچ اتفاقی نیفتاد."
fi
sleep "$DWELL"
shot "01-first-screen"

# چرخشِ سبکِ کاربر: یک بار برگشت، یک بار دوباره باز کردن — ماندگاریِ حالت سنجیده می‌شود.
adb shell input keyevent KEYCODE_BACK 2>/dev/null || true
sleep 2
shot "02-after-back"
adb shell monkey -p "$PKG" -c android.intent.category.LAUNCHER 1 >/dev/null 2>&1 || true
sleep 5
shot "03-reopen"

CRASH=$(adb logcat -d 2>/dev/null | grep -iE "FATAL EXCEPTION|ANR in|force[- ]clos" | head -20)
adb logcat -d > "$OUT/logcat-full.txt" 2>/dev/null || true

{
  echo
  echo "## آنچه دیده شد"
  echo
  if [ -n "$CRASH" ]; then
    echo "⛔ **اپ در حینِ بازدید از کار افتاد.** نشانه‌های خام:"
    echo '```'
    echo "$CRASH"
    echo '```'
  else
    echo "✅ در طولِ این بازدید اپ نخوابید و پیامِ «متوقف شد» نداد."
  fi
  echo
  echo "## شاهدها"
  echo
  echo "| لحظه | فایل |"
  echo "|---|---|"
  echo "| صفحهٔ لانچر پیش از باز کردن | \`00-launcher.png\` |"
  echo "| اولین صفحهٔ اپ | \`01-first-screen.png\` |"
  echo "| بعد از زدنِ دکمهٔ برگشت | \`02-after-back.png\` |"
  echo "| بازکردنِ دوباره | \`03-reopen.png\` |"
  echo
  echo "⛔ **این گزارش خودش حکم نیست.** حکمِ «کجا کار می‌کند و کجا نه» را"
  echo "\`Assistant-User-Simulator\` بعد از **دیدنِ** همین تصویرها می‌دهد و در"
  echo "\`Memory-User-Simulator/<Project>/05-لاگِ-تست.md\` می‌نویسد."
} >> "$REPORT"

if [ -n "$CRASH" ]; then
  echo "::error::اپ در حینِ بازدید از کار افتاد — visit-report.md را ببین."
  exit 1
fi
echo "▪ بازدید تمام شد؛ شاهدها در $OUT/"
