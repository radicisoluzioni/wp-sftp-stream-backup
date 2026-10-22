# Builds the distributable plugin into ..\dist\wp-sftp-stream-backup and a zip file.
# Requires Docker (Composer runs in a container) unless -SkipComposer is given.
param([switch]$SkipComposer)

$ErrorActionPreference = 'Stop'
$root   = Split-Path -Parent $PSScriptRoot
$plugin = Join-Path $root 'wp-sftp-stream-backup'
$dist   = Join-Path $root 'dist'
$target = Join-Path $dist 'wp-sftp-stream-backup'

if (-not $SkipComposer) {
    # Strauss loses files when it works directly on a Windows bind mount: build inside the container, then copy back.
    $cmd = 'rm -rf /tmp/b && mkdir /tmp/b && cp -r /app/composer.json /app/composer.lock /app/bin /tmp/b/ && cd /tmp/b ' +
           '&& composer install --no-interaction --ignore-platform-req=ext-* && rm -rf /app/vendor /app/vendor-prefixed && cp -r vendor vendor-prefixed /app/'
    $ErrorActionPreference = 'Continue'
    docker run --rm -v "${plugin}:/app" composer:2 sh -c $cmd 2>&1 | Out-Null
    $code = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    if ($code -ne 0) { throw 'composer install failed' }
}
if (-not (Test-Path (Join-Path $plugin 'vendor-prefixed\autoload.php'))) { throw 'vendor-prefixed is missing: run composer install first' }
$orig = (Get-ChildItem -Recurse -File (Join-Path $plugin 'vendor\phpseclib')).Count
$pref = (Get-ChildItem -Recurse -File (Join-Path $plugin 'vendor-prefixed\phpseclib')).Count
if ($orig -ne $pref) { throw "vendor-prefixed is incomplete ($pref of $orig phpseclib files): run build.ps1 without -SkipComposer" }

if (Test-Path $target) { Remove-Item -Recurse -Force $target }
New-Item -ItemType Directory -Force -Path $target | Out-Null

$include = @('wp-sftp-stream-backup.php', 'uninstall.php', 'readme.txt', 'src', 'assets', 'restore', 'vendor-prefixed', 'languages')
foreach ($item in $include) {
    $src = Join-Path $plugin $item
    if (Test-Path $src) { Copy-Item -Recurse -Force $src (Join-Path $target $item) }
}

$zip = Join-Path $dist 'wp-sftp-stream-backup.zip'
if (Test-Path $zip) { Remove-Item -Force $zip }
# bsdtar writes "/" separators, unlike Compress-Archive on Windows PowerShell 5.1.
& tar.exe -a -c -f $zip -C $dist 'wp-sftp-stream-backup'
if ($LASTEXITCODE -ne 0) { throw 'zip creation failed' }

# Generic migration installer (the migration code is pasted in its form).
$installer = Join-Path $dist 'wpssb-installer.php'
if (Test-Path $installer) { Remove-Item -Force $installer }
$ErrorActionPreference = 'Continue'
docker run --rm -v "${target}:/plugin:ro" -v "${dist}:/out" -v "${PSScriptRoot}:/dev-scripts:ro" php:7.4-cli php /dev-scripts/make-installer.php /plugin /out/wpssb-installer.php 2>&1 | Out-Null
$code = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
if ($code -ne 0 -or -not (Test-Path $installer)) { throw 'installer creation failed' }
Write-Output "Built $target, $zip and $installer"
