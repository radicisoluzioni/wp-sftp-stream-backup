# End-to-end multi-repository tests. Uses only isolated local Docker test resources.
param([switch]$Quick, [switch]$KeepRunning)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$compose = Join-Path $PSScriptRoot 'docker-compose.yml'
$s3 = $null
$nc = $null
$startedWordPress = $false

function CheckedDocker {
    $preference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $out = & docker @args 2>&1
    $code = $LASTEXITCODE
    $ErrorActionPreference = $preference
    if ($code -ne 0) { throw ($out -join "`n") }
    return $out
}
function Src {
    CheckedDocker compose -f $compose exec -T source @args
}
function Wp {
    Src wp --allow-root @args
}
function State {
    return ((Wp stream-backup status --format=json) -join '') | ConvertFrom-Json
}
function Assert($ok, $message) {
    if (-not $ok) { throw $message }
    Write-Output "[ok] $message"
}
function TransportHandoff($script) {
    $out = & powershell -ExecutionPolicy Bypass -File $script -KeepRunning
    if ($LASTEXITCODE -ne 0) { throw "Transport integration failed: $script" }
    $json = @($out | Where-Object { "$_".StartsWith('{') }) | Select-Object -Last 1
    if (-not $json) { throw "Missing transport handoff JSON: $script" }
    return "$json" | ConvertFrom-Json
}

try {
    & powershell -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'build.ps1') -SkipComposer
    if ($LASTEXITCODE -ne 0) { throw 'Plugin build failed' }
    $s3 = TransportHandoff (Join-Path $PSScriptRoot 's3-integration.ps1')
    $nc = TransportHandoff (Join-Path $PSScriptRoot 'nextcloud-integration.ps1')
    CheckedDocker compose -f $compose down -v --remove-orphans | Out-Null
    CheckedDocker compose -f $compose up -d --build | Out-Null
    $startedWordPress = $true
    $sourceContainer = (CheckedDocker compose -f $compose ps -q source) -join ''
    CheckedDocker network connect $nc.network $sourceContainer | Out-Null
    # Wait for the entrypoint to finish copying core, not merely start Apache.
    $ready = $false
    for ($i = 0; $i -lt 120; $i++) {
        & docker compose -f $compose exec -T source test -f /var/www/html/wp-includes/compat-utf8.php 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Seconds 1
    }
    Assert $ready 'WordPress core copied'
    Src bash /scripts/setup-source.sh | Select-Object -Last 2
    Wp eval-file /scripts/configure.php password 0 2 | Out-Null
    Wp sftp-backup test --trust
    Wp config set WPSSB_ALLOW_INSECURE_REMOTE true --raw | Out-Null
    Wp eval-file /scripts/repository-settings-test.php
    Wp eval-file /scripts/lock-check.php
    $envArgs = @(
        '-e', "WPSSB_TEST_S3_ENDPOINT=$($s3.endpoint)",
        '-e', "WPSSB_TEST_S3_BUCKET=$($s3.bucket)",
        '-e', "WPSSB_TEST_S3_ACCESS=$($s3.access_key)",
        '-e', "WPSSB_TEST_S3_SECRET=$($s3.secret_key)",
        '-e', "WPSSB_TEST_NEXTCLOUD_URL=$($nc.network_url)",
        '-e', "WPSSB_TEST_NEXTCLOUD_USER=$($nc.username)",
        '-e', "WPSSB_TEST_NEXTCLOUD_PASSWORD=$($nc.app_password)",
        '-e', "WPSSB_TEST_FILEDROP_URL=$($nc.public_pw_url.Replace($nc.container_url, $nc.network_url))",
        '-e', "WPSSB_TEST_FILEDROP_PASSWORD=$($nc.public_password)"
    )
    CheckedDocker compose -f $compose exec -T @envArgs source wp --allow-root eval-file /scripts/repositories-check.php configure
    if (-not $Quick) { Wp eval-file /scripts/repositories-check.php large }
    foreach ($id in @('s3-copy','nc-copy')) { Wp stream-backup test "--repository=$id" }
    Wp stream-backup test --repository=drop-copy --allow-public-probe
    Src touch /tmp/wpssb-marker
    Src php -d memory_limit=128M /usr/local/bin/wp --allow-root stream-backup run --budget=3 | Select-Object -Last 6
    $state = State
    Assert ($state.status -eq 'done') 'Five-repository backup completed'
    Assert ($state.peak_memory -lt 134217728) "Peak PHP memory $($state.peak_memory) bytes, below 128 MiB"
    $newFiles = @(Src sh /scripts/newfiles.sh)
    Assert ($newFiles.Count -eq 0) 'No local files written during the backup'
    Assert ($state.repositories.'drop-copy'.status -eq 'accepted') 'File Drop is accepted, not falsely verified'

    # A real lost acknowledgement must restart only that replica under new names.
    Wp eval-file /scripts/repositories-check.php drop-loss-run
    # Exercise the same segmented/replication state machine through loopback + cron.
    Wp eval-file /scripts/configure.php password 3 2 | Out-Null
    Src touch /tmp/wpssb-marker
    Wp eval-file /scripts/web-start.php
    $deadline = (Get-Date).AddMinutes(10)
    do {
        Start-Sleep -Seconds 3
        $state = State
    } while ($state.status -eq 'running' -and (Get-Date) -lt $deadline)
    Assert ($state.status -eq 'done' -and $state.trigger -eq 'manual') 'Web loopback completes all repository copies'
    Assert (@(Src sh /scripts/newfiles.sh).Count -eq 0) 'Web backup writes no local files'
    # One unavailable destination must not prevent successful copies to the others.
    Wp eval-file /scripts/repositories-check.php fail-run
    Assert ((State).status -eq 'partial') 'Independent destination outage produces partial completion'
    Src php -d memory_limit=128M /usr/local/bin/wp --allow-root stream-backup retry | Select-Object -Last 5
    Assert ((State).status -eq 'done') 'Retry resumes failed copies from the same primary'
    $retained = @(CheckedDocker compose -f $compose exec -T target find /sftpdata/segmented -name manifest.json)
    Assert ($retained.Count -eq 2) 'Per-repository retention keeps two complete primary copies'
    Wp eval-file /scripts/repositories-check.php verify
    Src bash /scripts/admin-check.sh
    $state = State
    CheckedDocker compose -f $compose exec -T target bash /scripts/restore-parts.sh $state.id
    $sourceFiles = @(Src sh /scripts/filelist.sh)
    $restoredFiles = @(CheckedDocker compose -f $compose exec -T target sh /scripts/filelist.sh "/var/www/parts/$($state.id)")
    Assert (@(Compare-Object $sourceFiles $restoredFiles).Count -eq 0) "All $($sourceFiles.Count) restored file checksums match"
    $share = "/nc/data/$($nc.username)/files/$($nc.public_pw_path)"
    CheckedDocker run --rm --network wpssb-dev_default `
        -v "$($PSScriptRoot)\scripts:/scripts:ro" -v "$($nc.volume):/nc:ro" `
        wpssb-dev-target bash /scripts/restore-drop.sh $state.id $state.repositories.'drop-copy'.generation $share
} finally {
    if (-not $KeepRunning) {
        if ($startedWordPress) { CheckedDocker compose -f $compose down -v --remove-orphans | Out-Null }
        foreach ($service in @($s3, $nc)) {
            if ($null -ne $service) {
                CheckedDocker rm -f $service.container | Out-Null
                CheckedDocker volume rm $service.volume | Out-Null
                CheckedDocker network rm $service.network | Out-Null
            }
        }
    }
}
Write-Output 'Multi-repository integration passed.'
