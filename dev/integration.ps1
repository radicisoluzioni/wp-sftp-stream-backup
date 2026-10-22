# End-to-end test: source WordPress -> SFTP (atmoz/sftp) -> restore on an empty target.
# Usage: powershell -ExecutionPolicy Bypass -File dev\integration.ps1 [-KeepRunning]
param([switch]$KeepRunning)

$ErrorActionPreference = 'Continue'
$dev = $PSScriptRoot
Set-Location $dev
$script:failures = 0

function Section($t) { Write-Output ''; Write-Output "=== $t" }
function Check($ok, $msg) {
    if ($ok) { Write-Output "[ok]   $msg" } else { Write-Output "[FAIL] $msg"; $script:failures++ }
}
function Dc { docker compose @args 2>&1 | ForEach-Object { "$_" } }
function Src { $out = docker compose exec -T source @args 2>&1 | ForEach-Object { "$_" }; $script:rc = $LASTEXITCODE; $out }
function Tgt { $out = docker compose exec -T target @args 2>&1 | ForEach-Object { "$_" }; $script:rc = $LASTEXITCODE; $out }
function Wp { Src wp --allow-root --path=/var/www/html @args }

# Shell scripts must have LF line endings.
Get-ChildItem (Join-Path $dev 'scripts') -Filter *.sh | ForEach-Object {
    $t = [IO.File]::ReadAllText($_.FullName) -replace "`r`n", "`n"; [IO.File]::WriteAllText($_.FullName, $t)
}

Section 'Build plugin'
& (Join-Path $dev 'build.ps1') -SkipComposer
Check ($LASTEXITCODE -eq 0 -or $?) 'dist built'

Section 'SSH key for key-based auth'
if (-not (Test-Path (Join-Path $dev 'keys\id_ed25519'))) {
    docker run --rm --entrypoint sh -v "$(Join-Path $dev 'keys'):/k" atmoz/sftp:alpine -c "ssh-keygen -q -t ed25519 -N '' -C wpssb-test -f /k/id_ed25519" 2>&1 | Out-Null
}
Check (Test-Path (Join-Path $dev 'keys\id_ed25519.pub')) 'key pair present'

Section 'Start containers'
Dc down -v --remove-orphans | Out-Null
Dc up -d --build | Select-Object -Last 3
Start-Sleep -Seconds 5

Section 'Prepare source site'
Src bash /scripts/setup-source.sh | Select-Object -Last 3
Check ($script:rc -eq 0) 'source site installed and seeded'

Section 'Connection tests'
Wp eval-file /scripts/configure.php password 0 2 | Select-Object -Last 1
$out = Wp sftp-backup test
Check ($script:rc -ne 0 -and ($out -join ' ') -match 'fingerprint') 'unknown host key refused before login'
$out = Wp sftp-backup test --trust
Check ($script:rc -eq 0) ("trusted fingerprint and connected: " + ($out | Select-Object -Last 1))
Wp eval-file /scripts/configure.php badfp 0 2 | Out-Null
$out = Wp sftp-backup test
Check ($script:rc -ne 0 -and ($out -join ' ') -match 'mismatch') 'fingerprint mismatch detected'
Wp eval-file /scripts/configure.php key 0 2 | Out-Null
$out = Wp sftp-backup test
Check ($script:rc -eq 0) ("private key authentication: " + ($out | Select-Object -Last 1))

Section 'Backup 1: WP-CLI with 3 s steps'
Wp eval-file /scripts/configure.php password 0 2 | Out-Null
$out = Wp sftp-backup run --budget=3
$out | Select-String -Pattern 'Database dump completed|Files archived|Success|Error|warning' | ForEach-Object { "  $_" }
Check ($script:rc -eq 0) 'CLI backup succeeded'
$st = (Wp sftp-backup status --format=json) -join '' | ConvertFrom-Json
Check ($st.status -eq 'done' -and $st.steps -gt 2) "status done after $($st.steps) steps"

Section 'Backup 2: web (loopback chain), 3 s steps, no local writes'
Wp eval-file /scripts/configure.php password 3 2 | Out-Null
Src sh -c 'touch /tmp/wpssb-marker; sleep 1' | Out-Null
Wp eval-file /scripts/web-start.php
$deadline = (Get-Date).AddMinutes(10)
do {
    Start-Sleep -Seconds 3
    $st = (Wp sftp-backup status --format=json) -join '' | ConvertFrom-Json
    Write-Output ("  {0} {1} steps={2} db={3} files={4}" -f $st.status, $st.phase, $st.steps, $st.db_bytes, $st.files_count)
} while ($st.status -eq 'running' -and (Get-Date) -lt $deadline)
Check ($st.status -eq 'done') "web backup finished with status '$($st.status)' $($st.last_error)"
Check ($st.steps -gt 3) "web backup used $($st.steps) chained steps"
Check ($st.trigger -eq 'manual') 'trigger recorded'
$new = Src sh /scripts/newfiles.sh
Check (-not ($new | Where-Object { $_ -ne '' })) ("no files written locally during the backup " + ($new -join ', '))

Section 'Cancel while a step is running'
Wp eval-file /scripts/configure.php password 3 2 | Out-Null
$started = Wp eval-file /scripts/web-start.php
$cancelId = (($started -join ' ') -replace '.*started\s+(\S+).*', '$1')
Start-Sleep -Seconds 2
Wp sftp-backup cancel
$deadline = (Get-Date).AddSeconds(90)
do {
    Start-Sleep -Seconds 2
    $st = (Wp sftp-backup status --format=json) -join '' | ConvertFrom-Json
} while ($st.status -eq 'running' -and (Get-Date) -lt $deadline)
Check ($st.status -eq 'cancelled' -and $st.id -eq $cancelId) "job $cancelId cancelled (status '$($st.status)')"
Start-Sleep -Seconds 8
$st = (Wp sftp-backup status --format=json) -join '' | ConvertFrom-Json
Check ($st.status -eq 'cancelled') 'cancelled job not resurrected by a running step'
Tgt test -d "/sftpdata/wp-backups/$cancelId" | Out-Null
Check ($script:rc -ne 0) 'partial remote folder of the cancelled job deleted'

Section 'Backup 3: retention (keep 2)'
Wp eval-file /scripts/configure.php password 0 2 | Out-Null
Wp sftp-backup run --budget=30 | Select-Object -Last 1
$dirs = Tgt sh -c 'for d in /sftpdata/wp-backups/*/; do [ -f "$d/manifest.json" ] && echo "$d"; done'
Check (@($dirs).Count -eq 2) "2 complete backups kept on the server ($(@($dirs).Count))"
Wp sftp-backup list

Section 'Archive integrity on the SFTP server'
$latest = (@($dirs) | Sort-Object | Select-Object -Last 1).TrimEnd('/')
Tgt sh -c "gzip -t $latest/files.tar.gz && gzip -t $latest/database.sql.gz" | Out-Null
Check ($script:rc -eq 0) 'gzip -t ok for both archives'
$list = Tgt sh -c "tar -tzf $latest/files.tar.gz"
Check ($script:rc -eq 0) "tar lists $(@($list).Count) entries"
Check (@($list | Where-Object { $_ -match '^wp-content/cache/' }).Count -eq 0) 'excluded wp-content/cache not archived'
Check (@($list | Where-Object { $_ -eq 'wp-content/uploads/2026/10/big.bin' }).Count -eq 1) 'big.bin archived'
Check (@($list | Where-Object { $_ -eq 'wp-config.php' }).Count -eq 1) 'wp-config.php archived'
$manifest = (Tgt cat "$latest/manifest.json") -join '' | ConvertFrom-Json
Check ($manifest.home_url -eq 'http://localhost:8081' -and $manifest.files.entries -gt 1000) "manifest ok ($($manifest.files.entries) entries, $($manifest.database.rows) rows)"
Tgt sh -c "ls -la $latest"

Section 'Admin UI and AJAX'
Src bash /scripts/admin-check.sh
Check ($script:rc -eq 0) 'admin page and AJAX endpoints'

Section 'Restore via restore.php web UI'
Tgt bash /scripts/restore-web.sh | Select-Object -Last 4
Check ($script:rc -eq 0) 'web restore completed'
Tgt php /scripts/compare-db.php wp2 http://localhost:8082
Check ($script:rc -eq 0) 'restored database matches'
$a = Src sha1sum /var/www/html/wp-content/uploads/2026/10/big.bin
$b = Tgt sha1sum /var/www/html/wp-content/uploads/2026/10/big.bin
Check ((($a -split ' ')[0]) -eq (($b -split ' ')[0])) 'big.bin identical'
$a2 = Src sh /scripts/filelist.sh
$b = Tgt sh /scripts/filelist.sh
Check ($a2.Count -gt 1000 -and -not (Compare-Object $a2 $b)) "all $($a2.Count) files (except wp-config.php) identical"
$restoredHome = Tgt wp --allow-root --path=/var/www/html option get home
Check ($restoredHome -eq 'http://localhost:8082') "restored home = $restoredHome"
$page = Tgt curl -fsS http://localhost:8082/
Check (($page -join ' ') -match 'Source Site') 'restored site renders'
Tgt wp --allow-root --path=/var/www/html plugin list --status=active --field=name

Section 'Restore via restore.php CLI'
Tgt bash /scripts/restore-cli.sh | Select-Object -Last 5
Check ($script:rc -eq 0) 'CLI restore completed'
Tgt php /scripts/compare-db.php wp3 http://localhost:8081/sub
Check ($script:rc -eq 0) 'CLI-restored database matches'

Section 'Restore killed at random points during SQL import and URL replacement'
Tgt bash /scripts/restore-chaos.sh | Select-Object -Last 6
Check ($script:rc -eq 0) 'chaos restore completed'
Tgt php /scripts/compare-db.php wp4 http://localhost:8081/sub | Select-String 'FAIL|replaced twice|checksum wp_wpssb'
Check ($script:rc -eq 0) 'chaos-restored database matches (no duplicated rows, no double replacement)'

Write-Output ''
if ($script:failures -eq 0) { Write-Output 'ALL INTEGRATION CHECKS PASSED' } else { Write-Output "$($script:failures) CHECK(S) FAILED" }
if (-not $KeepRunning) { Dc down -v | Out-Null }
exit $script:failures
