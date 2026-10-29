Add-Type -AssemblyName System.IO.Compression
[string]$path = 'D:\apps\finops-dashboard\laravel\.env'
$sqlite = 'sqlite:'; 
Write-Output "=== BEFORE ==="
Get-Content $path | Where-Object { $_ -match '^(APP_|DB_|CACHE_|QUEUE|SESSION|APP_TIME|APP_URL|APP_LOCALE)' }
