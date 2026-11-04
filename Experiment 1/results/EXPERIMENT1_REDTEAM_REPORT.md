# Experiment 1 — REDTEAM result

## Measurement design

- One pre-entry workflow: cryptographic operations from parking listing/reservation preparation to immediately before vehicle arrival.
- Warm-up: 30 executions before measurement.
- Each measured entity/system bundle is executed for 1,000 consecutive workflows exactly once.
- Paper-facing mean: total execution time of the 1,000 workflows / 1,000.
- No batch/trial averaging is used.
- C, H, and S are measured separately; Total is measured by directly executing the complete workflow.

## Primary SHA-256 result

| Entity | Proposed (us/workflow) | PISA (us/workflow) | PISA / Proposed |
|---|---:|---:|---:|
| C | 25.600 | 75.199 | 2.94x |
| H | 25.458 | 0.084 | 0.00x |
| S | 22.826 | 219.940 | 9.64x |
| Total | 74.143 | 299.704 | 4.04x |

Total:
- Proposed = 74.143 us/workflow
- PISA = 299.704 us/workflow
- PISA/Proposed = 4.04x
- Proposed reduction relative to PISA = 75.26%

## REDTEAM verdict

{
  "experiment_is_pre_entry_only": true,
  "exactly_1000_workflows_measured": true,
  "single_measurement_block_no_trial_averaging": true,
  "R_registration_excluded": true,
  "M_payment_excluded": true,
  "post_entry_operations_excluded": true,
  "PISA_beta_encryption_included": true,
  "PISA_beta_decryption_included": true,
  "PISA_r_generation_included": true,
  "PISA_hash_chain_100_calls_included": true,
  "PISA_H_credential_verification_included": true,
  "PISA_C_ephemeral_signature_verification_included": true,
  "Proposed_N_S_C_generation_included": true,
  "Proposed_pre_entry_AES_not_added": true,
  "primary_Total_Proposed_lower_than_PISA": true,
  "hash_sensitivity_optional_or_passed": true,
  "direct_total_consistent_with_entity_sum": true
}
