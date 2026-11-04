# Experiment 2 — Parking-duration scalability (actual numeric x-axis)

## Objective
Compare post-entry cryptographic computation overhead as parking duration increases.

## Parking-duration conditions
1, 4, 12, 24, 48, 72, and 100 hours.

## Measurement design
- Warm-up: 30 complete sessions per system/condition
- Measurement: one timing block of 1,000 consecutive complete post-entry sessions
- Mean computation time = total measured time / 1,000
- No repeated trial-batch averaging
- No SD, CI, or error bars

## Proposed scheme
Per complete entry+exit session:
- Ed25519 signatures: 4
- Ed25519 verifications: 8
- AES-GCM encryptions: 2
- AES-GCM decryptions: 2
- Additional duration-dependent cryptographic operations during parking: 0

## PISA primary interpretation
- Arrival AES challenge/response
- Signed hash-chain endpoint verified once at arrival
- At hour m, the received token is hashed m times and compared with the
  already authenticated endpoint
- No new hourly signature generation
- Repeated verification of the same endpoint signature every hour is excluded
  from the primary experiment and retained only as an optional sensitivity mode

For parking duration h:

N_H(h) = h(h+1)/2.

The maximum tested duration is 100 h, therefore the PISA hash-chain state uses
t = max(HOURS) = 100.

## Figure 1 — computation time
- x-axis values are the actual numeric parking durations:
  1, 4, 12, 24, 48, 72, 100
- Point spacing is proportional to the true hour difference
- y-axis: 0–2.0 ms/session
- Proposed: blue (#1F77B4)
- PISA: orange (#FF7F0E)
- Measured values are not rescaled

## Figure 2 — additional cryptographic operations
- Uses the same actual numeric x-axis spacing
- Proposed: 0 additional duration-dependent cryptographic operations
- PISA: cumulative hash evaluations N_H(h)
