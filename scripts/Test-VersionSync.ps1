$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$package = Get-Content -LiteralPath (Join-Path $repoRoot 'package.json') -Raw | ConvertFrom-Json
$lockText = Get-Content -LiteralPath (Join-Path $repoRoot 'package-lock.json') -Raw
$lockVersions = [regex]::Matches($lockText, '"version"\s*:\s*"([0-9]+\.[0-9]+\.[0-9]+)"')
if ($lockVersions.Count -lt 2) { throw 'Root package-lock versions were not found.' }
$engine = Get-Content -LiteralPath (Join-Path $repoRoot 'cms\engine.php') -Raw
$match = [regex]::Match($engine, "PAGECORE_VERSION',\s*'([^']+)'")
if (-not $match.Success) { throw 'PAGECORE_VERSION was not found.' }
$expected = $package.version
$versions = @($expected, $lockVersions[0].Groups[1].Value, $lockVersions[1].Groups[1].Value, $match.Groups[1].Value)
if (($versions | Select-Object -Unique).Count -ne 1) { throw "Version mismatch: $($versions -join ', ')" }
$browser = Get-Content -LiteralPath (Join-Path $repoRoot 'tests\sample-site.spec.js') -Raw
if (($browser | Select-String -AllMatches -Pattern 'Pagecore ([0-9]+\.[0-9]+\.[0-9]+)').Matches.Value -notcontains "Pagecore $expected") {
    throw "Browser version assertion does not contain Pagecore $expected"
}
Write-Host "Version synchronization passed: $expected"
