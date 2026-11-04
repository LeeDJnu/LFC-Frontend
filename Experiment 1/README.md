# Experiment 1 — Pre-entry efficiency benchmark

최종 논문용 측정 구조:

- warm-up = 30회
- 측정 대상 = 주차 공간 등록/예약 준비부터 차량 입차 직전까지의 암호학적 연산
- 각 Proposed/PISA workload를 **1,000회 연속 수행**
- 평균 계산비용 = **1,000회 총 수행시간 / 1,000**
- batch/trial 반복 평균은 사용하지 않음
- C, H, S는 주체별로 별도 측정
- Total은 C+H+S의 단순 합이 아니라 전체 pre-entry workflow를 직접 실행하여 측정

네트워크/TLS 지연, DB/UI/search, 결제, 실제 입차 이후 연산은 제외합니다.


## PISA hash-chain setting

실험 1과 실험 2의 최대 주차시간 조건을 일관되게 유지하기 위해,
PISA의 해시 체인 최대 길이는 **t = 100**으로 설정합니다.
따라서 PISA의 입차 전 단계에서 서버 S는 r을 생성한 뒤
H(r)부터 H^100(r)까지의 해시 체인을 구성하고 H^100(r)에 서명합니다.

## Windows 실행

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\run_all.ps1
```

실행하면 다음이 자동 수행됩니다.

1. `.venv` 생성
2. `requirements.txt` 설치
3. warm-up 수행
4. Proposed/PISA의 각 workload를 1,000회 연속 실행
5. 총 수행시간 / 1,000으로 workflow당 평균 계산시간 산출
6. 결과 CSV, 환경정보, REDTEAM audit, 그래프 생성

## 결과 파일

- `experiment_environment.txt`
- `primary_results.csv`
- `raw_measurements.csv`
- `entity_sum_vs_direct_total.csv`
- `operation_manifest.csv`
- `excluded_scope.csv`
- `REDTEAM_AUDIT.json`
- `EXPERIMENT1_REDTEAM_REPORT.md`
- `fig01_pre_entry_mean_linear.png`
- `fig02_pre_entry_mean_log.png`

그래프 내부의 상단 제목은 제거되어 있으며, 논문에서 Figure caption으로 제목을 붙이는 것을 전제로 합니다.

## Optional hash sensitivity

```powershell
.\.venv\Scripts\python.exe .\run_experiment1.py `
    --workflows 1000 `
    --warmup 30 `
    --run-hash-sensitivity `
    --output-dir .\results_sensitivity
```
