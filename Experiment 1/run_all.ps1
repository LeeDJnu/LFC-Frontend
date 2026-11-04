$ErrorActionPreference = "Stop"

Write-Host "============================================================"
Write-Host "Experiment 1 - Pre-entry efficiency (1000 workflows)"
Write-Host "============================================================"

if (-not (Test-Path ".\.venv\Scripts\python.exe")) {
    $Py = Get-Command py -ErrorAction SilentlyContinue
    if ($Py) {
        py -m venv .venv
    } else {
        $PythonCmd = Get-Command python -ErrorAction SilentlyContinue
        if (-not $PythonCmd) {
            throw "Python was not found. Install Python 3.13+ and try again."
        }
        python -m venv .venv
    }
}

$Python = ".\.venv\Scripts\python.exe"

Write-Host ""
Write-Host "[1/3] Installing dependencies..."
& $Python -m pip install --upgrade pip
& $Python -m pip install -r .\requirements.txt

Write-Host ""
Write-Host "[2/3] Running Experiment 1..."
& $Python .\run_experiment1.py `
    --workflows 1000 `
    --warmup 30 `
    --output-dir .\results

Write-Host ""
Write-Host "[3/3] Completed."
Write-Host "Results     : .\results"
Write-Host "Environment : .\results\experiment_environment.txt"
Write-Host "Main CSV    : .\results\primary_results.csv"
Write-Host "Raw measure : .\results\raw_measurements.csv"
Write-Host ""

Start-Process explorer.exe ".\results"
