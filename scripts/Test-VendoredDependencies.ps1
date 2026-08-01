$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$manifest = Get-Content -LiteralPath (Join-Path $repoRoot 'cms\lib\Parsedown.version.json') -Raw | ConvertFrom-Json
$source = Get-Content -LiteralPath (Join-Path $repoRoot 'cms\lib\Parsedown.php') -Raw
$normalizedBytes = [Text.Encoding]::UTF8.GetBytes($source.Replace("`r`n", "`n"))
$sha = [Security.Cryptography.SHA256]::Create()
try { $actual = [BitConverter]::ToString($sha.ComputeHash($normalizedBytes)).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
if ($actual -ne $manifest.sha256) { throw "Vendored Parsedown checksum mismatch: $actual" }
if ($source -notmatch "const version = '$([regex]::Escape($manifest.version))'") { throw 'Vendored Parsedown version does not match its manifest.' }
Write-Host "Vendored dependency passed: $($manifest.name) $($manifest.version)"
