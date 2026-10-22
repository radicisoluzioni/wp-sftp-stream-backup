# Real-server transport test: NextcloudStorage against the official Nextcloud image.
# Isolated resources (exact names): container wpssb-nc-it, volume wpssb-nc-it-data, network wpssb-nc-it-net.
# Usage: powershell -ExecutionPolicy Bypass -File dev\nextcloud-integration.ps1 [-Image nextcloud:30-apache] [-PhpVersions 7.4,8.3] [-HostPort 0] [-KeepRunning]
# Default: everything is removed at the end. -KeepRunning (explicit integration handoff) leaves the server up,
# creates "WPSSB Handoff" fixtures and prints one compact JSON object (test-only secrets) as the LAST stdout line.
# Nextcloud is published on 127.0.0.1:<HostPort> (0 = random); other containers use http://host.docker.internal:<port>.
param(
    [string]$Image = 'nextcloud:30-apache',
    [int]$HostPort = 0,
    [string[]]$PhpVersions = @('7.4', '8.3'),
    [switch]$KeepRunning
)

$ErrorActionPreference = 'Continue'
$dev = $PSScriptRoot
$root = Join-Path (Split-Path $dev -Parent) 'wp-sftp-stream-backup'
$net = 'wpssb-nc-it-net'; $nc = 'wpssb-nc-it'; $vol = 'wpssb-nc-it-data'
$script:failures = 0

function Section($t) { Write-Output ''; Write-Output "=== $t" }
function Check($ok, $msg) {
    if ($ok) { Write-Output "[ok]   $msg" } else { Write-Output "[FAIL] $msg"; $script:failures++ }
}
function Occ { $out = docker exec -u www-data @args 2>&1 | ForEach-Object { "$_" }; $script:rc = $LASTEXITCODE; $out }
function Cleanup {
    docker rm -f $nc 2>&1 | Out-Null
    docker volume rm $vol 2>&1 | Out-Null
    docker network rm $net 2>&1 | Out-Null
}
function NewSecret { 'It-' + ([guid]::NewGuid().ToString('N')) + '-9x' }

Section "Start $Image"
Cleanup
docker network create $net | Out-Null
docker volume create $vol | Out-Null
docker run -d --name $nc --network $net -p "127.0.0.1:${HostPort}:80" -v "${vol}:/var/www/html" $Image | Out-Null
Check ($LASTEXITCODE -eq 0) "container $nc started"

$ready = $false
for ($i = 0; $i -lt 90; $i++) {
    $st = Occ $nc php occ status --output=json
    if ($script:rc -eq 0 -and ($st -join '') -match '"installed":false') { $ready = $true; break }
    Start-Sleep -Seconds 2
}
Check $ready 'occ available'

Section 'Install via occ (sqlite)'
$adminPass = NewSecret; $userPass = NewSecret; $user = 'wpssb-it'
Occ $nc php occ maintenance:install --database sqlite --admin-user admin --admin-pass $adminPass | Select-Object -Last 1
Check ($script:rc -eq 0) 'maintenance:install'
$i = 1
foreach ($d in @($nc, 'host.docker.internal', 'localhost', '127.0.0.1')) { Occ $nc php occ config:system:set trusted_domains $i --value=$d | Out-Null; $i++ }
Occ $nc php occ config:system:set auth.bruteforce.protection.enabled --type=boolean --value=false | Out-Null
Occ $nc php occ config:system:set ratelimit.protection.enabled --type=boolean --value=false | Out-Null
$ver = Occ $nc php occ status --output=json
Write-Output "       $($ver -join '')"
Occ -e "OC_PASS=$userPass" $nc php occ user:add --password-from-env $user | Select-Object -Last 1
Check ($script:rc -eq 0) "user $user created"
$tok = Occ -e "OC_PASS=$userPass" $nc php occ user:auth-tokens:add --password-from-env $user
$appPass = ($tok | Where-Object { $_ -match '^[A-Za-z0-9]{40,}$' } | Select-Object -Last 1)
Check ([bool]$appPass) 'app password generated'

foreach ($v in $PhpVersions) {
    Section "Transport test on php:$v-cli"
    docker run --rm --network $net -v "${root}:/plugin:ro" -v "$(Join-Path $dev 'scripts'):/scripts:ro" `
        -e "NC_URL=http://$nc" -e "NC_USER=$user" -e "NC_APP_PASS=$appPass" -e "RUN_ID=php$v" `
        "php:$v-cli" php /scripts/nextcloud-transport.php 2>&1 | ForEach-Object { "$_" }
    Check ($LASTEXITCODE -eq 0) "transport test php $v"
}

$handoff = $null
if ($KeepRunning) {
    Section 'Handoff fixtures'
    $port = ((docker port $nc 80/tcp) -split "
" | Select-Object -First 1) -replace '.*:', ''
    Check ($port -match '^\d+$') "published on 127.0.0.1:$port"
    $probe = docker run --rm --add-host host.docker.internal:host-gateway curlimages/curl -s "http://host.docker.internal:$port/status.php" 2>&1
    Check (($probe -join '') -match '"installed":true') "reachable from containers at http://host.docker.internal:$port"
    $out = docker run --rm --network $net -v "${root}:/plugin:ro" -v "$(Join-Path $dev 'scripts'):/scripts:ro" `
        -e "NC_URL=http://$nc" -e "NC_USER=$user" -e "NC_APP_PASS=$appPass" -e 'HANDOFF=1' `
        php:8.3-cli php /scripts/nextcloud-transport.php 2>&1 | ForEach-Object { "$_" }
    $out | Where-Object { $_ -notmatch '^HANDOFF_JSON=' }
    $line = $out | Where-Object { $_ -match '^HANDOFF_JSON=' } | Select-Object -Last 1
    Check ([bool]$line) 'handoff shares created'
    if ($line) {
        $fx = ($line -replace '^HANDOFF_JSON=', '') | ConvertFrom-Json
        $base = "http://host.docker.internal:$port"
        $handoff = [ordered]@{
            container        = $nc
            network          = $net
            volume           = $vol
            image            = $Image
            host_port        = [int]$port
            host_url         = "http://127.0.0.1:$port"
            container_url    = $base
            network_url      = "http://$nc"
            insecure_http    = 'define WPSSB_ALLOW_INSECURE_REMOTE true'
            admin_user       = 'admin'
            admin_password   = $adminPass
            username         = $user
            user_password    = $userPass
            app_password     = $appPass
            auth_path        = $fx.auth_path
            public_open_url  = "$base/s/$($fx.drop_open_token)"
            public_pw_url    = "$base/s/$($fx.drop_pw_token)"
            public_password  = $fx.drop_pw_password
            public_open_path = 'WPSSB Handoff/drop-open'
            public_pw_path   = 'WPSSB Handoff/drop-pw'
            cleanup          = "docker rm -f $nc; docker volume rm $vol; docker network rm $net"
        }
    }
} else { Section 'Cleanup'; Cleanup; Write-Output 'removed container, volume, network' }

Write-Output ''
if ($script:failures) { Write-Output "NEXTCLOUD INTEGRATION FAILED: $($script:failures)" } else { Write-Output 'NEXTCLOUD INTEGRATION PASSED' }
if ($handoff) { Write-Output ($handoff | ConvertTo-Json -Compress) }
if ($script:failures) { exit 1 }