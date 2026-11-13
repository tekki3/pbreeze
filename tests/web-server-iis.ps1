param(
    [string] $Php = (Get-Command php.exe -ErrorAction Stop).Source
)

$ErrorActionPreference = 'Stop'
Import-Module WebAdministration

# Run as administrator on a disposable Windows host with IIS CGI + URL Rewrite.
$name = 'PBreezeTest-' + [Guid]::NewGuid().ToString('N')
$directory = Join-Path ([IO.Path]::GetTempPath()) $name
$phpCgi = Join-Path (Split-Path $Php) 'php-cgi.exe'
if (-not (Test-Path $phpCgi)) {
    throw 'The PHP distribution must include php-cgi.exe (use an NTS build).'
}
$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
$listener.Start()
$port = $listener.LocalEndpoint.Port
$listener.Stop()
$fastCgiAdded = $false
$arguments = "-d `"session.save_path=$directory/sessions`" -d upload_max_filesize=3M -d post_max_size=8M -d display_errors=0"

try {
    & $Php "$PSScriptRoot/web-server-prepare.php" $directory
    if ($LASTEXITCODE -ne 0) { throw 'Fixture preparation failed.' }
    New-Item -ItemType Directory -Path "$directory/sessions" | Out-Null
    New-WebAppPool -Name $name | Out-Null
    Set-ItemProperty "IIS:\AppPools\$name" -Name managedRuntimeVersion -Value ''

    Add-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter 'system.webServer/fastCgi' -Name '.' -Value @{
        fullPath = $phpCgi
        arguments = $arguments
        activityTimeout = 330
        requestTimeout = 330
        instanceMaxRequests = 1000
    }
    $fastCgiAdded = $true
    foreach ($entry in @{
        PHPRC = (Split-Path $Php)
        PHP_FCGI_MAX_REQUESTS = '1000'
    }.GetEnumerator()) {
        Add-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' `
            -Filter "system.webServer/fastCgi/application[@fullPath='$phpCgi' and @arguments='$arguments']/environmentVariables" `
            -Name '.' -Value @{ name = $entry.Key; value = $entry.Value }
    }

    New-Website -Name $name -PhysicalPath "$directory/public" -IPAddress '127.0.0.1' -Port $port -ApplicationPool $name | Out-Null
    Add-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $name `
        -Filter 'system.webServer/handlers' -Name '.' -AtIndex 0 -Value @{
        name = 'PBreezePHP'
        path = 'index.php'
        verb = '*'
        modules = 'FastCgiModule'
        scriptProcessor = "$phpCgi|$arguments"
        resourceType = 'File'
        requireAccess = 'Script'
    }
    # Avoid WebDAV intercepting PUT/DELETE before the framework sees them.
    foreach ($section in @(
        @{ filter = 'system.webServer/modules'; name = 'WebDAVModule' },
        @{ filter = 'system.webServer/handlers'; name = 'WebDAV' }
    )) {
        $entries = @(Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $name -Filter "$($section.filter)/add")
        if ($entries | Where-Object { $_.name -eq $section.name }) {
            Remove-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $name -Filter $section.filter -Name '.' `
                -AtElement @{ name = $section.name }
        }
    }
    Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $name `
        -Filter 'system.webServer/security/authentication/anonymousAuthentication' -Name userName -Value ''

    & icacls.exe $directory /grant "IIS AppPool\${name}:(OI)(CI)RX" /T /Q | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Could not grant fixture read access.' }
    foreach ($path in @('storage', 'database', 'sessions')) {
        & icacls.exe "$directory/$path" /grant "IIS AppPool\${name}:(OI)(CI)M" /T /Q | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Could not grant fixture data access.' }
    }
    $ready = $false
    for ($i = 0; $i -lt 60; $i++) {
        try {
            $response = Invoke-WebRequest "http://127.0.0.1:$port/health" -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -eq 200) { $ready = $true; break }
        } catch {
            if ($i -eq 59) { throw }
            Start-Sleep -Milliseconds 500
        }
    }
    if (-not $ready) { throw 'IIS did not become ready.' }
    & $Php "$PSScriptRoot/web-server-smoke.php" "http://127.0.0.1:$port" $directory
    if ($LASTEXITCODE -ne 0) { throw 'IIS compatibility tests failed.' }
} finally {
    if (Test-Path "IIS:\Sites\$name") { Remove-Website -Name $name }
    if (Test-Path "IIS:\AppPools\$name") {
        if ((Get-WebAppPoolState -Name $name).Value -eq 'Started') { Stop-WebAppPool -Name $name }
        Remove-WebAppPool -Name $name
    }
    if ($fastCgiAdded) {
        Remove-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter 'system.webServer/fastCgi' `
            -Name '.' -AtElement @{ fullPath = $phpCgi; arguments = $arguments }
    }
    if (Test-Path $directory) {
        # Delete only the exact GUID-named fixture created by this invocation.
        Remove-Item -LiteralPath $directory -Recurse -Force
    }
}
