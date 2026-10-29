# بازدیدِ کاربری از یک اپِ ویندوزی — نصب یا اجرا، باز کردن، دیدن، شاهد گرفتن.
#
# مالک: Assistant-User-Simulator (قابلیتِ «محیطِ شبیه‌سازیِ ویندوز»).
# مصرف‌کننده: .github/workflows/user-sim-windows.yml
# ⛔ کد نمی‌خواند و دربارهٔ کد حکم نمی‌دهد — فقط کاری را می‌کند که یک آدم می‌کند.
#    قفل: user-simulator-never-reads-code
# ⛔ شکستِ خاموش ممنوع: هر گام ردش را می‌گذارد؛ خطای واقعی با ::error:: قرمز می‌شود.
# ⛔ هرگز اجرا نشده تا امروز — محصولِ ویندوزی هنوز وجود ندارد. <!-- absent-verified: ۱۴۰۵-۰۶-۱۵ -->
param(
  [Parameter(Mandatory=$true)][string]$AppPath,
  [string]$SilentArgs = "",
  [string]$DwellSeconds = "12"
)
$ErrorActionPreference = "Stop"
$out = "user-sim-out"
New-Item -ItemType Directory -Force -Path $out | Out-Null
$report = Join-Path $out "visit-report.md"

function Fail($m) { Write-Host "::error::$m"; Add-Content $report "- ⛔ $m"; exit 1 }
function Shot($name) {
  Add-Type -AssemblyName System.Windows.Forms, System.Drawing
  $b = [System.Windows.Forms.SystemInformation]::VirtualScreen
  $bmp = New-Object System.Drawing.Bitmap $b.Width, $b.Height
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.CopyFromScreen($b.Location, [System.Drawing.Point]::Empty, $b.Size)
  $bmp.Save((Join-Path $out "$name.png"))
  Write-Host "▪ شاهد: $name.png"
}

@(
  "# گزارشِ بازدیدِ کاربری — ویندوز", "",
  "| قلم | مقدار |", "|---|---|",
  "| فایل | ``$(Split-Path $AppPath -Leaf)`` |",
  "| آرگومانِ نصبِ بی‌صدا | $(if ($SilentArgs) { "``$SilentArgs``" } else { "— (اجرای مستقیم)" }) |", ""
) | Set-Content $report

if (-not (Test-Path $AppPath)) { Fail "فایل پیدا نشد: $AppPath" }
Write-Host "▪ اندازه: $((Get-Item $AppPath).Length) بایت"

Shot "00-desktop"

if ($SilentArgs) {
  Write-Host "▪ نصبِ بی‌صدا"
  $p = Start-Process -FilePath $AppPath -ArgumentList $SilentArgs -PassThru -Wait
  if ($p.ExitCode -ne 0) { Fail "نصب با کدِ $($p.ExitCode) شکست خورد — کاربر حتی نتوانست اپ را روی دستگاهش بگذارد." }
  Add-Content $report "- ✅ نصب شد"
  Write-Host "▪ نصب شد"
} else {
  Write-Host "▪ اجرای مستقیم (بی نصب)"
  Add-Content $report "- ▪ بی نصب، مستقیم اجرا شد"
}

Write-Host "▪ باز کردنِ اپ مثلِ یک کاربر"
$proc = Start-Process -FilePath $AppPath -PassThru -ErrorAction SilentlyContinue
if (-not $proc) { Fail "اپ باز نشد — کاربر روی فایل زد و هیچ اتفاقی نیفتاد." }
Start-Sleep -Seconds ([int]$DwellSeconds)
Shot "01-first-screen"

$alive = -not $proc.HasExited
@(
  "", "## آنچه دیده شد", "",
  $(if ($alive) { "✅ اپ بعد از $DwellSeconds ثانیه هنوز باز بود." }
    else { "⛔ **اپ خودش بسته شد** — کدِ خروج: $($proc.ExitCode)" }),
  "", "## شاهدها", "",
  "| لحظه | فایل |", "|---|---|",
  "| میزکار پیش از باز کردن | ``00-desktop.png`` |",
  "| اولین صفحهٔ اپ | ``01-first-screen.png`` |", "",
  "⛔ **این گزارش خودش حکم نیست.** حکم را ``Assistant-User-Simulator`` بعد از **دیدنِ**",
  "همین تصویرها می‌دهد و در ``Memory-User-Simulator/<Project>/05-لاگِ-تست.md`` می‌نویسد."
) | Add-Content $report

if ($alive) { $proc | Stop-Process -Force -ErrorAction SilentlyContinue }
else { Write-Host "::error::اپ خودش بسته شد — visit-report.md را ببین."; exit 1 }
Write-Host "▪ بازدید تمام شد؛ شاهدها در $out/"
