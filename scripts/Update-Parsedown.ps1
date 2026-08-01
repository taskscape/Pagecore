$ErrorActionPreference = 'Stop'

$Version = '1.8.0'
$ExpectedSha256 = '34075a6f176841dbb91ca6a26ac7455b5bb576e368cfadb8249edb79d67b1a06'
$SourceUrl = "https://raw.githubusercontent.com/erusev/parsedown/$Version/Parsedown.php"
$RepoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$BaseTarget = Join-Path $RepoRoot 'cms\lib\Parsedown.php'
$TemporaryFile = Join-Path ([System.IO.Path]::GetTempPath()) ("pagecore-parsedown-$([guid]::NewGuid().ToString('N')).php")

try {
    Invoke-WebRequest -UseBasicParsing -Uri $SourceUrl -OutFile $TemporaryFile
    $actualSha256 = (Get-FileHash -LiteralPath $TemporaryFile -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualSha256 -ne $ExpectedSha256) {
        throw "Parsedown checksum mismatch. Expected $ExpectedSha256 but received $actualSha256."
    }

    $downloadedSource = Get-Content -Raw -LiteralPath $TemporaryFile
    if ($downloadedSource -notmatch "const version = '$([regex]::Escape($Version))'") {
        throw "Downloaded Parsedown file does not declare version $Version."
    }

    Copy-Item -LiteralPath $TemporaryFile -Destination $BaseTarget -Force
    Write-Host "Updated $BaseTarget to Parsedown $Version ($ExpectedSha256)"

} finally {
    if (Test-Path -LiteralPath $TemporaryFile) {
        Remove-Item -LiteralPath $TemporaryFile -Force
    }
}
