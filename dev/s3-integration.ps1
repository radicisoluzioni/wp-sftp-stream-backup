# Local-only S3 transport integration (no commercial credentials).
# Usage: powershell -ExecutionPolicy Bypass -File dev\s3-integration.ps1 [-KeepRunning]
# Publishes an ephemeral test port; -KeepRunning prints cleanup commands, then compact handoff JSON as the last stdout line.
param(
    [switch]$KeepRunning,
    [string]$MinioImage = 'minio/minio:latest',
    [string]$PhpImage = 'php:7.4-cli'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$id = [Guid]::NewGuid().ToString('N').Substring(0, 12)
$container = "wpssb-s3-$id"
$network = "wpssb-s3-net-$id"
$volume = "wpssb-s3-data-$id"
$bucket = "wpssb-test-$id"
$accessKey = "wpssbtest$id"
$secretKey = [Guid]::NewGuid().ToString('N') + 'localonly'
$createdNetwork = $false
$createdVolume = $false
$createdContainer = $false
$success = $false

function DockerChecked {
    $output = & docker @args 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ("docker command failed: " + ($output -join "`n"))
    }
    return $output
}

try {
    DockerChecked network create --label wpssb.local-s3=$id $network | Out-Null
    $createdNetwork = $true
    DockerChecked volume create --label wpssb.local-s3=$id $volume | Out-Null
    $createdVolume = $true
    DockerChecked run -d --name $container --label wpssb.local-s3=$id `
        --network $network --network-alias s3 `
        -p '0:9000' `
        -e "MINIO_ROOT_USER=$accessKey" -e "MINIO_ROOT_PASSWORD=$secretKey" `
        --mount "type=volume,source=$volume,target=/data" `
        $MinioImage server /data --console-address ':9001' | Out-Null
    $createdContainer = $true
    $mapping = (DockerChecked port $container '9000/tcp' | Select-Object -First 1).Trim()
    $port = [int]($mapping -replace '^.*:', '')
    $endpoint = "http://localhost:$port"
    $ready = $false
    for ($attempt = 0; $attempt -lt 60; $attempt++) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri "$endpoint/minio/health/ready" -TimeoutSec 2
            if ($response.StatusCode -eq 200) { $ready = $true; break }
        } catch {
            Start-Sleep -Seconds 1
        }
    }
    if (-not $ready) { throw 'The isolated MinIO instance did not become ready.' }

    $mcHost = "MC_HOST_local=http://${accessKey}:${secretKey}@localhost:9000"
    DockerChecked exec -e $mcHost $container mc mb "local/$bucket" | Out-Null
    # Seed a remote object just over the transport limit without writing a client-side file.
    DockerChecked exec -e $mcHost -e "S3_TEST_BUCKET=$bucket" $container /bin/sh -c `
        'head -c 8388609 /dev/zero | mc pipe "local/$S3_TEST_BUCKET/integration/oversized"' | Out-Null

    Write-Output "MinIO ready: $endpoint (isolated test credentials; ephemeral host port)."
    DockerChecked run --rm --read-only --network $network `
        --mount "type=bind,source=$root,target=/work,readonly" --workdir /work `
        -e "S3_ENDPOINT=http://host.docker.internal:$port" -e 'S3_REGION=us-east-1' `
        -e "S3_BUCKET=$bucket" -e "S3_ACCESS_KEY=$accessKey" -e "S3_SECRET_KEY=$secretKey" `
        $PhpImage php -d memory_limit=96M dev/scripts/s3-transport.php | Write-Output
    $success = $true

    if ($KeepRunning) {
        Write-Output 'Local integration only: define WPSSB_ALLOW_INSECURE_REMOTE=true in the calling WordPress runtime.'
        Write-Output "Cleanup: docker rm -f -v $container; docker volume rm $volume; docker network rm $network"
        [ordered]@{
            endpoint = "http://host.docker.internal:$port"
            host_endpoint = $endpoint
            port = $port
            region = 'us-east-1'
            bucket = $bucket
            access_key = $accessKey
            secret_key = $secretKey
            session_token = ''
            path_style = $true
            container = $container
            network = $network
            volume = $volume
            WPSSB_ALLOW_INSECURE_REMOTE = $true
        } | ConvertTo-Json -Compress
    }
} finally {
    if (-not ($KeepRunning -and $success)) {
        if ($createdContainer) { & docker rm -f -v $container 2>&1 | Out-Null }
        if ($createdVolume) { & docker volume rm $volume 2>&1 | Out-Null }
        if ($createdNetwork) { & docker network rm $network 2>&1 | Out-Null }
    }
}
