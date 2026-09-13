<#
.SYNOPSIS
Validate a built deployment against Pagecore's production guard before upload.

.DESCRIPTION
Pagecore fails closed in production when the configuration, content, backups,
uploads or rate-limit directory resolve below DOCUMENT_ROOT, and when the
configuration still carries development or demo values. Neither condition can
be reached through the bundled development server, which ignores .htaccess and
runs with PAGECORE_DEVELOPMENT=1 — so a site can pass every local test and
still return 500 on every production request.

This script boots the engine in the production posture against a candidate
public root and private configuration, and reports what a host would report.

.PARAMETER PublicRoot
The directory that will become DOCUMENT_ROOT.

.PARAMETER ConfigFile
The private config.php, which must live outside PublicRoot.

.EXAMPLE
scripts\Test-ProductionLayout.ps1 -PublicRoot .\deploy\public_html -ConfigFile .\deploy\pagecore-private\config.php
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $PublicRoot,
    [Parameter(Mandatory = $true)][string] $ConfigFile
)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$php = if ($env:PAGECORE_PHP_EXE) { $env:PAGECORE_PHP_EXE } else { Join-Path $repoRoot 'php\php.exe' }
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { throw "PHP executable not found at $php" }

$publicPath = (Resolve-Path -LiteralPath $PublicRoot).Path
$configPath = (Resolve-Path -LiteralPath $ConfigFile).Path
$engine = Join-Path $publicPath 'cms\engine.php'
if (-not (Test-Path -LiteralPath $engine -PathType Leaf)) { throw "No cms/engine.php under $publicPath" }

# The in-root default config must not ship: it would itself be a violation.
$strayConfig = Join-Path $publicPath 'cms\config.php'
if (Test-Path -LiteralPath $strayConfig -PathType Leaf) {
    throw "cms/config.php is present in the public root. Production must load a config from outside DOCUMENT_ROOT."
}

# Boot with no PAGECORE_DEVELOPMENT, exactly as a host would.
$probe = @'
$publicRoot = $argv[1];
$configFile = $argv[2];
$_SERVER["DOCUMENT_ROOT"] = $publicRoot;
$_SERVER["HTTPS"] = "on";
$_SERVER["HTTP_HOST"] = "example.com";
$_SERVER["REMOTE_ADDR"] = "203.0.113.9";
$_SERVER["REQUEST_URI"] = "/";
putenv("PAGECORE_DEVELOPMENT");
putenv("PAGECORE_CONFIG=" . $configFile);
require $publicRoot . "/cms/engine.php";

$problems = array();
if ($GLOBALS["cmsDevelopment"]) { $problems[] = "engine resolved to development mode"; }
foreach (cms_private_storage_violations($publicRoot, $GLOBALS["cmsConfigFile"]) as $violation) {
    $problems[] = "inside DOCUMENT_ROOT: " . $violation;
}
foreach (array("development_only" => false, "demo_credentials" => false,
    "require_https" => true, "cookie_secure" => true) as $key => $expected) {
    if (cms_cfg($key, null) !== $expected) {
        $problems[] = sprintf("%s should be %s", $key, var_export($expected, true));
    }
}
if (strpos((string) cms_cfg("site_url", ""), "https://") !== 0) { $problems[] = "site_url must be https"; }
foreach (array("content_dir", "uploads_dir") as $key) {
    if (!is_dir(cms_cfg($key, ""))) { $problems[] = $key . " does not exist: " . cms_cfg($key, ""); }
}
foreach (array("content_dir", "uploads_dir", "login_rate_limit_dir") as $key) {
    $dir = cms_cfg($key, cms_cfg("content_dir") . "/.state");
    if (is_dir($dir) && !is_writable($dir)) { $problems[] = $key . " is not writable: " . $dir; }
}
echo $problems ? "FAIL\n" . implode("\n", $problems) . "\n" : "OK\n";
exit($problems ? 1 : 0);
'@
$probeFile = Join-Path ([System.IO.Path]::GetTempPath()) ('pagecore-layout-' + [guid]::NewGuid().ToString('N') + '.php')
[System.IO.File]::WriteAllText($probeFile, "<?php`n" + $probe, (New-Object System.Text.UTF8Encoding($false)))
try {
    $output = & $php $probeFile $publicPath $configPath 2>&1 | Out-String
} finally {
    Remove-Item -LiteralPath $probeFile -Force -ErrorAction SilentlyContinue
}

if ($LASTEXITCODE -ne 0) {
    throw "Production layout check failed:`n$($output.Trim())"
}
Write-Host "Production layout check passed for $publicPath"
