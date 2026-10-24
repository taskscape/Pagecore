#Requires -Version 5.1
<#
.SYNOPSIS
    Sets a new admin password in the Pagecore configuration file.

.DESCRIPTION
    Prompts for a new admin password, generates a secure password hash using
    the local PHP installation, and updates the 'password_hash' entry in the
    configuration file.

    The script looks for PHP in the following order:
    1. The PAGECORE_PHP_EXE environment variable.
    2. C:\Tools\PHP\php.exe
    3. The system PATH.

.NOTES
    The configuration file is located at sample-site/config.php by default.
    A timestamped backup of the configuration file is created before any
    changes are applied.
#>

[CmdletBinding()]
param(
    [string]$ConfigPath = (Join-Path $PSScriptRoot '..\sample-site\config.php')
)

$ErrorActionPreference = 'Stop'

# --- Resolve PHP executable ---
$phpExe = $null
if ($env:PAGECORE_PHP_EXE -and (Test-Path $env:PAGECORE_PHP_EXE)) {
    $phpExe = $env:PAGECORE_PHP_EXE
} elseif (Test-Path 'C:\Tools\PHP\php.exe') {
    $phpExe = 'C:\Tools\PHP\php.exe'
} else {
    $phpExe = (Get-Command 'php.exe' -ErrorAction SilentlyContinue).Source
}

if (-not $phpExe) {
    Write-Error 'PHP executable not found. Please install PHP in C:\Tools\PHP or add it to the system PATH.'
    exit 1
}

# --- Validate configuration file ---
if (-not (Test-Path $ConfigPath)) {
    Write-Error "Configuration file not found at: $ConfigPath"
    exit 1
}

# --- Prompt for new password ---
$securePassword = Read-Host -Prompt 'Enter new admin password' -AsSecureString
$secureConfirm  = Read-Host -Prompt 'Confirm new admin password' -AsSecureString

# Convert SecureStrings to plain text for comparison and hashing
$bstr1 = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
$bstr2 = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureConfirm)
$password = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr1)
$confirm  = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr2)
[System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr1)
[System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr2)

if ($password -ne $confirm) {
    Write-Error 'Passwords do not match.'
    exit 1
}

if ($password.Length -lt 8) {
    Write-Error 'Password must be at least 8 characters long.'
    exit 1
}

# --- Generate password hash using PHP ---
$escapedPassword = $password.Replace("'", "\'")
$hashCommand = "echo password_hash('$escapedPassword', PASSWORD_BCRYPT);"
$newHash = & $phpExe -r $hashCommand

if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($newHash)) {
    Write-Error 'Failed to generate password hash using PHP.'
    exit 1
}

# --- Update configuration file ---
$backupPath = "$ConfigPath.bak-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
Copy-Item -Path $ConfigPath -Destination $backupPath

try {
    $content = Get-Content -Path $ConfigPath -Raw
    $pattern = "('password_hash'\s*=>\s*')[^']*(')"
    $replacement = "`$1$newHash`$2"
    $updatedContent = [regex]::Replace($content, $pattern, $replacement)

    if ($updatedContent -eq $content) {
        Write-Error "Could not find 'password_hash' entry in $ConfigPath."
        exit 1
    }

    Set-Content -Path $ConfigPath -Value $updatedContent -NoNewline -Encoding UTF8
    Write-Host "Admin password updated successfully in $ConfigPath"
    Write-Host "Backup saved to: $backupPath"
} catch {
    # Restore backup on failure
    Copy-Item -Path $backupPath -Destination $ConfigPath -Force
    Write-Error "Failed to update configuration: $_"
    exit 1
}
