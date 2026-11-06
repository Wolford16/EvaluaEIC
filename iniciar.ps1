param([int]$Puerto = 8080, [switch]$RedLocal)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$ejecutablePhp = $env:EVALUAEIC_PHP
if (-not $ejecutablePhp) {
    foreach ($candidatoPhp in @('C:\xampp\php\php.exe', 'C:\php\php.exe')) {
        if (Test-Path -LiteralPath $candidatoPhp) { $ejecutablePhp = $candidatoPhp; break }
    }
}
if (-not $ejecutablePhp) { $ejecutablePhp = (Get-Command php -ErrorAction Stop).Source }
if (-not (Test-Path -LiteralPath 'terceros\autoload.php')) {
    throw 'Faltan las bibliotecas. Sigue el paso de Composer en LEEME.md.'
}
& $ejecutablePhp herramientas/preparar.php
if ($LASTEXITCODE -ne 0) { throw 'Revisa la configuración de PHP y de la base de datos.' }
$direccionEscucha = if ($RedLocal) { '0.0.0.0' } else { '127.0.0.1' }
$directorioTemporales = Join-Path $PSScriptRoot 'almacenamiento\temporales'
New-Item -ItemType Directory -Path $directorioTemporales -Force | Out-Null
Write-Host "EvaluaEIC disponible en http://localhost:$Puerto" -ForegroundColor Cyan
Write-Host 'Mantén esta ventana abierta. Para detener el servidor, presiona Ctrl+C.'
if ($RedLocal) { Write-Host 'Configura APLICACION_URL en configuracion/servidor.php con la IP local del equipo y permite el puerto en la red privada.' }
& $ejecutablePhp -d upload_max_filesize=12M -d post_max_size=16M -d memory_limit=256M -d max_execution_time=210 -d display_errors=0 -d log_errors=1 -d "upload_tmp_dir=$directorioTemporales" -d "error_log=$PSScriptRoot\almacenamiento\servidor.log" -S "${direccionEscucha}:$Puerto" -t publico servidor.php
