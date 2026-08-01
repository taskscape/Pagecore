$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$php = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $repoRoot 'php\php.exe' }
$importer = Join-Path $repoRoot 'scripts\import-wordpress.php'
$fixture = Join-Path $repoRoot 'tests\fixtures\import-status.sql'
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('pagecore-import-transaction-' + [guid]::NewGuid().ToString('N'))

function Invoke-Import([string[]] $Arguments) {
    $oldPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & $php $importer @Arguments 2>$null | Out-Null
    $code = $LASTEXITCODE
    $ErrorActionPreference = $oldPreference
    return $code
}

function Get-TreeDigest([string] $Path) {
    $lines = Get-ChildItem -LiteralPath $Path -Recurse -File | Sort-Object FullName | ForEach-Object {
        $relative = $_.FullName.Substring($Path.Length).TrimStart('\').Replace('\', '/')
        $fileSha = [Security.Cryptography.SHA256]::Create()
        try { $fileHash = [BitConverter]::ToString($fileSha.ComputeHash([IO.File]::ReadAllBytes($_.FullName))).Replace('-', '') } finally { $fileSha.Dispose() }
        $relative + ':' + $fileHash
    }
    $payload = [Text.Encoding]::UTF8.GetBytes(($lines -join "`n"))
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return [BitConverter]::ToString($sha.ComputeHash($payload)).Replace('-', '') } finally { $sha.Dispose() }
}

try {
    New-Item -ItemType Directory -Path $testRoot | Out-Null
    $target = Join-Path $testRoot 'content'
    $common = @("--sql=$fixture", "--out-content=$target", '--copy-uploads=0')

    if ((Invoke-Import ($common + '--unknown-option=1')) -eq 0 -or (Test-Path -LiteralPath $target)) { throw 'Unknown option was not rejected before writes.' }
    if ((Invoke-Import ($common + '--post-url=javascript:alert(1)')) -eq 0 -or (Test-Path -LiteralPath $target)) { throw 'Unsafe URL was not rejected before writes.' }

    $dryTarget = Join-Path $testRoot 'dry-content'
    if ((Invoke-Import @("--sql=$fixture", "--out-content=$dryTarget", '--copy-uploads=0', '--dry-run=1')) -ne 0) { throw 'Dry run failed.' }
    if (Test-Path -LiteralPath $dryTarget) { throw 'Dry run changed its target.' }

    if ((Invoke-Import $common) -ne 0) { throw 'Initial transactional import failed.' }
    $firstDigest = Get-TreeDigest $target
    if ((Invoke-Import $common) -eq 0) { throw 'Non-empty target did not require --force=1.' }
    if ((Get-TreeDigest $target) -ne $firstDigest) { throw 'Rejected rerun changed the existing target.' }
    if ((Invoke-Import ($common + '--force=1')) -ne 0) { throw 'Forced deterministic rerun failed.' }
    if ((Get-TreeDigest $target) -ne $firstDigest) { throw 'Forced rerun was not byte-for-byte deterministic.' }

    $env:PAGECORE_IMPORT_FAIL_BASENAME = 'imported-config-fragment.php'
    try {
        if ((Invoke-Import ($common + '--force=1')) -eq 0) { throw 'Injected write failure unexpectedly succeeded.' }
    } finally { Remove-Item Env:PAGECORE_IMPORT_FAIL_BASENAME -ErrorAction SilentlyContinue }
    if ((Get-TreeDigest $target) -ne $firstDigest) { throw 'Injected failure changed the promoted target.' }

    $largeSql = Join-Path $testRoot 'large-statement.sql'
    $padding = 'x' * 2048
    Set-Content -LiteralPath $largeSql -Encoding ASCII -NoNewline -Value "INSERT INTO ``wp_posts`` VALUES (1,1,'2026-01-01','2026-01-01','$padding','Title','','publish','','','','slug','','','2026-01-01','2026-01-01','',0,'',0,'post','',0);"
    $boundedTarget = Join-Path $testRoot 'bounded-content'
    if ((Invoke-Import @("--sql=$largeSql", "--out-content=$boundedTarget", '--copy-uploads=0', '--max-statement-bytes=1024')) -eq 0) { throw 'Oversized SQL statement bypassed the configured bound.' }
    if (Test-Path -LiteralPath $boundedTarget) { throw 'Rejected oversized statement changed its target.' }

    $stagingLeaks = Get-ChildItem -LiteralPath $testRoot -Force | Where-Object Name -Like '.pagecore-import-*'
    if ($stagingLeaks) { throw 'Importer left staging directories after completion or failure.' }
    Write-Host 'PASS: importer validation, staging, rollback, bounds, and deterministic reruns'
} finally {
    if (Test-Path -LiteralPath $testRoot) {
        $resolved = (Resolve-Path -LiteralPath $testRoot).Path
        $tempBase = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\') + '\'
        if (-not $resolved.StartsWith($tempBase, [StringComparison]::OrdinalIgnoreCase) -or (Split-Path -Leaf $resolved) -notlike 'pagecore-import-transaction-*') { throw "Refusing to remove unexpected path: $resolved" }
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}
