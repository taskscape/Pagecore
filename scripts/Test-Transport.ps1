$ErrorActionPreference = 'Stop'

$RepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$PhpCandidate = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $RepoRoot 'php\php.exe' }

& $PhpCandidate (Join-Path $RepoRoot 'tests\transport.php')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$Listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, 0)
$Listener.Start()
$Port = ([System.Net.IPEndPoint] $Listener.LocalEndpoint).Port
$Listener.Stop()

$PreviousConfig = $env:PAGECORE_CONFIG
$PreviousDevelopment = $env:PAGECORE_DEVELOPMENT
$LogOut = [System.IO.Path]::GetTempFileName()
$LogErr = [System.IO.Path]::GetTempFileName()
$Process = $null
try {
    $env:PAGECORE_CONFIG = Join-Path $RepoRoot 'tests\transport-config.php'
    $env:PAGECORE_DEVELOPMENT = '1'
    $Process = Start-Process -FilePath $PhpCandidate -ArgumentList @(
        '-S', "127.0.0.1:$Port", '-t', $RepoRoot, (Join-Path $RepoRoot 'sample-site\router.php')
    ) -PassThru -WindowStyle Hidden -RedirectStandardOutput $LogOut -RedirectStandardError $LogErr

    $Ready = $false
    for ($Attempt = 0; $Attempt -lt 50; $Attempt++) {
        try {
            $Response = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$Port/cms/login.php" `
                -Headers @{ 'X-Forwarded-Proto' = 'https' } -TimeoutSec 2
            $Ready = $true
            break
        } catch {
            Start-Sleep -Milliseconds 100
        }
    }
    if (-not $Ready) { throw "Transport test server did not start: $(Get-Content -Raw $LogErr)" }

    $Cookie = [string] $Response.Headers['Set-Cookie']
    if ($Cookie -notmatch '(?i)Secure' -or $Cookie -notmatch '(?i)HttpOnly' -or $Cookie -notmatch '(?i)SameSite=Lax') {
        throw "Production session cookie flags are incomplete: $Cookie"
    }
    if ([string] $Response.Headers['Strict-Transport-Security'] -notmatch '^max-age=31536000') {
        throw 'Trusted-proxy HTTPS response did not emit HSTS.'
    }

    try {
        Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$Port/cms/login.php" -TimeoutSec 2 | Out-Null
        throw 'Production HTTP request was not rejected.'
    } catch {
        if ($_.Exception.Response.StatusCode.value__ -ne 400) { throw }
    }
} finally {
    if ($Process -and -not $Process.HasExited) { Stop-Process -Id $Process.Id -Force }
    $env:PAGECORE_CONFIG = $PreviousConfig
    $env:PAGECORE_DEVELOPMENT = $PreviousDevelopment
    Remove-Item -LiteralPath $LogOut, $LogErr -Force -ErrorAction SilentlyContinue
}

Write-Output 'PASS: HTTP harness emits secure cookie/HSTS headers and rejects production HTTP'
