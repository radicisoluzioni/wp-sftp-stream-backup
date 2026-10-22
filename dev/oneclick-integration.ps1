# End-to-end one-click restore tests: rollback (CLI and web) from SFTP, S3 and Nextcloud,
# and migration to an empty server with the embedded (CLI) and generic (web) installers.
# Uses only isolated local Docker test resources. Do not run together with the other integration scripts.
param([switch]$KeepRunning)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$composeArgs = @('compose', '-f', (Join-Path $PSScriptRoot 'docker-compose.yml'), '-f', (Join-Path $PSScriptRoot 'docker-compose.oneclick.yml'))
$s3 = $null
$nc = $null
$startedWordPress = $false
$password = 'correct horse battery staple'
$tmp = Join-Path ([IO.Path]::GetTempPath()) "wpssb-oneclick-$PID"

function CheckedDocker {
    $preference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $out = & docker @args 2>&1
    $code = $LASTEXITCODE
    $ErrorActionPreference = $preference
    if ($code -ne 0) { throw ($out -join "`n") }
    return $out
}
function Compose { CheckedDocker @composeArgs @args }
function Src { Compose exec -T source @args }
function Tgt { Compose exec -T target @args }
function Wp { Src wp --allow-root @args }
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
function SameFiles($label, $restoredFiles) {
    $diff = @(Compare-Object $script:sourceFiles $restoredFiles)
    if ($diff.Count) { $diff | Select-Object -First 10 | Out-String | Write-Output }
    Assert ($diff.Count -eq 0) "$label`: all $($script:sourceFiles.Count) file checksums match the backup"
}
function Rollback-Check($label) {
    Src sh /scripts/oneclick-files.sh check
    Wp eval-file /scripts/oneclick.php verify
    SameFiles $label @(Src sh /scripts/filelist.sh)
    $httpStatus = (Src curl -s -o /dev/null -w '%{http_code}' http://localhost:8081/) -join ''
    Assert ($httpStatus -eq '200') "$label`: site answers after the restore"
}
function Mutate {
    Src sh /scripts/oneclick-files.sh mutate
    Wp eval-file /scripts/oneclick.php mutate
}
function MigrationCode($repository, $path) {
    $out = Compose exec -T -e "WPSSB_MIGRATION_PASSWORD=$password" source wp --allow-root stream-backup migration-code $path "--repository=$repository" --days=1 --installer=/tmp/wpssb-installer.php
    $code = @($out | Where-Object { "$_".StartsWith('WPSSB1.') }) | Select-Object -Last 1
    if (-not $code) { throw "No migration code: $out" }
    return "$code"
}

try {
    New-Item -ItemType Directory -Force -Path $tmp | Out-Null
    & powershell -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'build.ps1') -SkipComposer
    if ($LASTEXITCODE -ne 0) { throw 'Plugin build failed' }
    $s3 = TransportHandoff (Join-Path $PSScriptRoot 's3-integration.ps1')
    $nc = TransportHandoff (Join-Path $PSScriptRoot 'nextcloud-integration.ps1')
    Compose down -v --remove-orphans | Out-Null
    Compose up -d --build | Out-Null
    $startedWordPress = $true
    CheckedDocker network connect $nc.network ((Compose ps -q source) -join '') | Out-Null
    $ready = $false
    for ($i = 0; $i -lt 120; $i++) {
        & docker @composeArgs exec -T source test -f /var/www/html/wp-includes/compat-utf8.php 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Seconds 1
    }
    Assert $ready 'WordPress core copied'
    Src sh -c 'cp -a /plugin-src/. /var/www/html/wp-content/plugins/wp-sftp-stream-backup/'
    Src bash /scripts/setup-source.sh | Select-Object -Last 1
    Wp eval-file /scripts/configure.php password 0 2 | Out-Null
    Wp sftp-backup test --trust
    Wp config set WPSSB_ALLOW_INSECURE_REMOTE true --raw | Out-Null
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
    Compose exec -T @envArgs source wp --allow-root eval-file /scripts/repositories-check.php configure
    Wp eval-file /scripts/oneclick.php snapshot
    Src chown -R www-data:www-data /var/www/html
    $script:sourceFiles = @(Src sh /scripts/filelist.sh)
    Src wp --allow-root stream-backup run | Select-Object -Last 2
    $state = ((Wp stream-backup status --format=json) -join '') | ConvertFrom-Json
    Assert ($state.status -eq 'done') 'Backup to all repositories completed'
    $paths = @{}
    foreach ($id in @('legacy-sftp', 's3-copy', 'nc-copy')) { $paths[$id] = (Wp eval-file /scripts/oneclick.php path $id) -join '' }

    # --- Rollback with WP-CLI from Nextcloud (WebDAV) ---
    Mutate
    Src wp --allow-root stream-backup restore $paths['nc-copy'] --repository=nc-copy --yes | Select-Object -Last 3
    Rollback-Check 'Nextcloud CLI rollback'

    # --- Rollback through the web runtime from S3 (what the admin button does) ---
    Mutate
    $url = (Wp eval-file /scripts/oneclick.php prepare s3-copy $paths['s3-copy']) -join ''
    Assert ($url -match '^http://localhost:8081/wpssb-restore-[0-9a-f]{16}/run\.php#[0-9a-f]{64}$') 'Runtime URL carries the token only in the fragment'
    Src chown -R www-data:www-data /var/www/html
    Src bash /scripts/oneclick-web.sh $url
    Rollback-Check 'S3 web rollback'

    # --- Rollback with WP-CLI from SFTP (segmented) ---
    Mutate
    Src wp --allow-root stream-backup restore $paths['legacy-sftp'] --repository=legacy-sftp --yes | Select-Object -Last 3
    Rollback-Check 'SFTP CLI rollback'

    Wp eval-file /scripts/oneclick.php negative legacy-sftp $paths['legacy-sftp']
    Wp eval-file /scripts/oneclick.php cleanup

    # --- Migration with the embedded installer (CLI) from SFTP into an empty server ---
    MigrationCode 'legacy-sftp' $paths['legacy-sftp'] | Out-Null
    CheckedDocker cp "$((Compose ps -q source) -join ''):/tmp/wpssb-installer.php" (Join-Path $tmp 'embedded.php') | Out-Null
    $target = (Compose ps -q target) -join ''
    Tgt sh -c 'rm -rf /var/www/html/* /var/www/html/.htaccess'
    CheckedDocker cp (Join-Path $tmp 'embedded.php') "${target}:/var/www/html/wpssb-installer.php" | Out-Null
    $ErrorActionPreference = 'Continue'
    $wrong = & docker @composeArgs exec -T -e 'WPSSB_MIGRATION_PASSWORD=wrong password!' -w /var/www/html target php wpssb-installer.php --url=http://localhost:8082 --db-host=db --db-name=wp2 --db-user=root --db-pass=root 2>&1
    $ErrorActionPreference = 'Stop'
    Assert ($LASTEXITCODE -ne 0 -and "$wrong" -match 'password') 'Wrong migration password refused'
    Compose exec -T -e "WPSSB_MIGRATION_PASSWORD=$password" -w /var/www/html target php wpssb-installer.php --url=http://localhost:8082 --db-host=db --db-name=wp2 --db-user=root --db-pass=root | Select-Object -Last 3
    Tgt sh -c 'test ! -e /var/www/html/wpssb-installer.php && ! ls -d /var/www/html/wpssb-restore-* 2>/dev/null' | Out-Null
    Write-Output '[ok] Installer and runtime removed after the migration'
    SameFiles 'SFTP CLI migration' @(Tgt sh /scripts/filelist.sh /var/www/html)
    Tgt php /scripts/compare-db.php wp2 http://localhost:8082
    Tgt chown -R www-data:www-data /var/www/html
    $page = (Tgt curl -s http://localhost:8082/) -join "`n"
    Assert ($page -match 'Source Site') 'Migrated site renders'

    # --- Migration with the generic installer (web form) from S3 into a sub-folder ---
    $code = MigrationCode 's3-copy' $paths['s3-copy']
    Tgt sh -c 'rm -rf /var/www/html/sub && mkdir /var/www/html/sub'
    CheckedDocker cp (Join-Path $root 'dist\wpssb-installer.php') "${target}:/var/www/html/sub/wpssb-installer.php" | Out-Null
    Tgt chown -R www-data:www-data /var/www/html/sub
    $form = (Tgt curl -s http://localhost:8082/sub/wpssb-installer.php) -join "`n"
    Assert ($form -match 'name="code"') 'Generic installer asks for the migration code'
    $location = (Tgt curl -s -o /dev/null -w '%{redirect_url}' -X POST `
        --data-urlencode "code=$code" --data-urlencode "password=$password" `
        --data-urlencode 'url=http://localhost:8082/sub' --data-urlencode 'db_host=db' --data-urlencode 'db_name=wp3' `
        --data-urlencode 'db_user=root' --data-urlencode 'db_pass=root' --data-urlencode 'overwrite=1' `
        http://localhost:8082/sub/wpssb-installer.php) -join ''
    Assert ($location -match '/sub/wpssb-restore-[0-9a-f]{16}/run\.php#[0-9a-f]{64}$') 'Installer redirects to the runtime'
    Tgt bash /scripts/oneclick-web.sh $location
    SameFiles 'S3 web migration' @(Tgt sh /scripts/filelist.sh /var/www/html/sub)
    Tgt php /scripts/compare-db.php wp3 http://localhost:8082/sub
    Tgt sh -c 'test ! -e /var/www/html/sub/wpssb-installer.php' | Out-Null
    $page = (Tgt curl -s http://localhost:8082/sub/) -join "`n"
    Assert ($page -match 'Source Site') 'Site migrated into a sub-folder renders'
} finally {
    Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
    if (-not $KeepRunning) {
        if ($startedWordPress) { Compose down -v --remove-orphans | Out-Null }
        foreach ($service in @($s3, $nc)) {
            if ($null -ne $service) {
                CheckedDocker rm -f $service.container | Out-Null
                CheckedDocker volume rm $service.volume | Out-Null
                CheckedDocker network rm $service.network | Out-Null
            }
        }
    }
}
Write-Output 'One-click restore integration passed.'
