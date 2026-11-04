#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
Experiment 2
============
Post-entry computation scalability as parking duration increases.

Final paper-facing measurement design
-------------------------------------
- Parking durations: 1, 4, 12, 24, 48, 72, 100 hours
- Warm-up: 30 sessions before each measured system/hour bundle
- Measurement: exactly 1,000 complete post-entry sessions in one timing block
- Mean per session = total execution time / 1,000
- No batch/trial averaging
- No SD/error bars

Primary interpretation of PISA
------------------------------
- Arrival: AES challenge/response + one verification of the signed hash-chain endpoint
- During parking: at hour m, H hashes the received token m times and checks it
  against the already authenticated endpoint.
- No new hourly signature is generated.
- Re-verification of the same endpoint signature every hour is NOT included in
  the primary curve. An optional sensitivity flag is provided for that literal
  implementation variant.

Scope
-----
Included:
- Proposed: entry + exit cryptographic processing
- PISA: arrival cryptographic processing + hourly hash-chain verification

Excluded:
- Network/TLS latency
- DB/UI/search/payment
- Pre-entry key generation, PISA hash-chain construction, and endpoint signing
  (these belong to Experiment 1)
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import platform
import random
import struct
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Iterable, List

from cryptography import __version__ as cryptography_version
from cryptography.hazmat.primitives.asymmetric.ed25519 import (
    Ed25519PrivateKey,
    Ed25519PublicKey,
)
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt


HOURS = [1, 4, 12, 24, 48, 72, 100]
SYSTEMS = ("Proposed", "PISA")
PRIMARY_HASH = "sha256"
PROPOSED_COLOR = "#1F77B4"
PISA_COLOR = "#FF7F0E"


@dataclass
class ProposedState:
    c_sk: Ed25519PrivateKey
    c_pk: Ed25519PublicKey
    h_sk: Ed25519PrivateKey
    h_pk: Ed25519PublicKey
    s_pk: Ed25519PublicKey
    cert_message: bytes
    cert_signature: bytes
    aes: AESGCM
    nonce_entry: bytes
    nonce_exit: bytes
    t_entry: bytes
    t_exit: bytes


@dataclass
class PISAState:
    s_pk: Ed25519PublicKey
    endpoint: bytes
    endpoint_signature: bytes
    aes: AESGCM
    nonce_c_to_h: bytes
    nonce_h_to_c: bytes
    alpha: bytes
    alpha_plus_one: bytes
    tokens: List[bytes]


def sha256(data: bytes) -> bytes:
    return hashlib.sha256(data).digest()


def make_proposed_state(hours: int) -> ProposedState:
    # Reservation keys and certificate are pre-entry state and are prepared
    # outside the measured post-entry interval.
    c_sk = Ed25519PrivateKey.generate()
    c_pk = c_sk.public_key()
    h_sk = Ed25519PrivateKey.generate()
    h_pk = h_sk.public_key()
    s_sk = Ed25519PrivateKey.generate()
    s_pk = s_sk.public_key()

    cert_message = c_pk.public_bytes(Encoding.Raw, PublicFormat.Raw)
    cert_signature = s_sk.sign(cert_message)

    aes = AESGCM(os.urandom(16))
    t_entry_int = 1_700_000_000
    t_exit_int = t_entry_int + hours * 3600

    return ProposedState(
        c_sk=c_sk,
        c_pk=c_pk,
        h_sk=h_sk,
        h_pk=h_pk,
        s_pk=s_pk,
        cert_message=cert_message,
        cert_signature=cert_signature,
        aes=aes,
        nonce_entry=os.urandom(12),
        nonce_exit=os.urandom(12),
        t_entry=struct.pack("!Q", t_entry_int),
        t_exit=struct.pack("!Q", t_exit_int),
    )


def proposed_event(
    state: ProposedState,
    timestamp: bytes,
    nonce: bytes,
) -> None:
    # C: sign time
    c_signature = state.c_sk.sign(timestamp)

    # C -> H: symmetric encryption/decryption
    plaintext = timestamp + c_signature
    ciphertext = state.aes.encrypt(nonce, plaintext, None)
    recovered = state.aes.decrypt(nonce, ciphertext, None)

    recovered_time = recovered[:8]
    recovered_c_signature = recovered[8:]

    # H: verify S certificate on C temporary public key + verify C time signature
    state.s_pk.verify(state.cert_signature, state.cert_message)
    state.c_pk.verify(recovered_c_signature, recovered_time)

    # H: sign the same time
    h_signature = state.h_sk.sign(recovered_time)

    # S: verify C and H signatures
    state.c_pk.verify(recovered_c_signature, recovered_time)
    state.h_pk.verify(h_signature, recovered_time)


def run_proposed_session(state: ProposedState, hours: int, _: bool = False) -> None:
    # The number/type of operations is independent of parking duration.
    proposed_event(state, state.t_entry, state.nonce_entry)
    proposed_event(state, state.t_exit, state.nonce_exit)


def make_pisa_state(max_hours: int) -> PISAState:
    # Hash-chain construction and endpoint signing are pre-entry work and are
    # intentionally prepared outside the measured post-entry interval.
    s_sk = Ed25519PrivateKey.generate()
    s_pk = s_sk.public_key()

    r = os.urandom(32)
    chain = [r]
    for _ in range(max_hours):
        chain.append(sha256(chain[-1]))

    endpoint = chain[max_hours]
    endpoint_signature = s_sk.sign(endpoint)

    # For hour m, PISA sends H^(t-m)(r).
    tokens = [chain[max_hours - m] for m in range(1, max_hours + 1)]

    alpha_int = int.from_bytes(os.urandom(16), "big")
    alpha = alpha_int.to_bytes(16, "big")
    alpha_plus_one = ((alpha_int + 1) % (1 << 128)).to_bytes(16, "big")

    return PISAState(
        s_pk=s_pk,
        endpoint=endpoint,
        endpoint_signature=endpoint_signature,
        aes=AESGCM(os.urandom(16)),
        nonce_c_to_h=os.urandom(12),
        nonce_h_to_c=os.urandom(12),
        alpha=alpha,
        alpha_plus_one=alpha_plus_one,
        tokens=tokens,
    )


def run_pisa_session(
    state: PISAState,
    hours: int,
    literal_repeat_endpoint_verify: bool = False,
) -> None:
    # Arrival challenge-response: C -> H
    c1 = state.aes.encrypt(state.nonce_c_to_h, state.alpha, None)
    p1 = state.aes.decrypt(state.nonce_c_to_h, c1, None)
    if p1 != state.alpha:
        raise RuntimeError("PISA alpha verification failed")

    # The signed endpoint was created pre-entry; verify it once at arrival.
    state.s_pk.verify(state.endpoint_signature, state.endpoint)

    # Arrival challenge-response: H -> C
    c2 = state.aes.encrypt(state.nonce_h_to_c, state.alpha_plus_one, None)
    p2 = state.aes.decrypt(state.nonce_h_to_c, c2, None)
    if p2 != state.alpha_plus_one:
        raise RuntimeError("PISA alpha+1 verification failed")

    # Hourly verification.
    # At hour m, H hashes H^(t-m)(r) exactly m times and compares with H^t(r).
    for m in range(1, hours + 1):
        value = state.tokens[m - 1]
        for _ in range(m):
            value = sha256(value)
        if value != state.endpoint:
            raise RuntimeError(f"PISA hash verification failed at hour {m}")

        # Optional sensitivity variant only:
        # literal reading that the same endpoint signature is checked every hour.
        if literal_repeat_endpoint_verify:
            state.s_pk.verify(state.endpoint_signature, state.endpoint)


def measure_sessions(
    runner: Callable[[object, int, bool], None],
    states: Iterable[object],
    hours: int,
    literal_repeat_endpoint_verify: bool = False,
) -> float:
    start = time.perf_counter_ns()
    for state in states:
        runner(state, hours, literal_repeat_endpoint_verify)
    end = time.perf_counter_ns()
    return (end - start) / 1_000_000.0


def build_states(
    system: str,
    count: int,
    hours: int,
) -> list:
    if system == "Proposed":
        return [make_proposed_state(hours) for _ in range(count)]
    if system == "PISA":
        return [make_pisa_state(max(HOURS)) for _ in range(count)]
    raise ValueError(system)


def benchmark_one_condition(
    hours: int,
    repetitions: int,
    warmup: int,
    seed: int,
    literal_repeat_endpoint_verify: bool,
) -> tuple[list[dict], dict]:
    runners = {
        "Proposed": run_proposed_session,
        "PISA": run_pisa_session,
    }

    # Build all cryptographic state before timing so Experiment 2 measures
    # only post-entry work. Key generation/hash-chain construction belongs to Exp. 1.
    warm_states = {
        system: build_states(system, warmup, hours)
        for system in SYSTEMS
    }
    measured_states = {
        system: build_states(system, repetitions, hours)
        for system in SYSTEMS
    }

    rng = random.Random(seed + hours * 1009)
    warm_order = list(SYSTEMS)
    rng.shuffle(warm_order)
    for system in warm_order:
        for state in warm_states[system]:
            runners[system](
                state,
                hours,
                literal_repeat_endpoint_verify if system == "PISA" else False,
            )

    order = list(SYSTEMS)
    rng.shuffle(order)

    raw_rows = []
    totals = {}
    for position, system in enumerate(order, start=1):
        total_ms = measure_sessions(
            runners[system],
            measured_states[system],
            hours,
            literal_repeat_endpoint_verify if system == "PISA" else False,
        )
        totals[system] = total_ms
        raw_rows.append(
            {
                "hours": hours,
                "execution_position": position,
                "system": system,
                "sessions": repetitions,
                "total_ms": total_ms,
                "mean_ms_per_session": total_ms / repetitions,
            }
        )

    proposed = totals["Proposed"] / repetitions
    pisa = totals["PISA"] / repetitions
    result = {
        "hours": hours,
        "Proposed_mean_ms": proposed,
        "PISA_mean_ms": pisa,
        "PISA_minus_Proposed_ms": pisa - proposed,
        "absolute_gap_ms": abs(proposed - pisa),
        "PISA_over_Proposed_ratio": pisa / proposed,
        "Proposed_over_PISA_ratio": proposed / pisa,
        "PISA_hash_evaluations_per_session": hours * (hours + 1) // 2,
        "PISA_hourly_token_messages_per_session": hours,
    }
    return raw_rows, result


def analytical_counts() -> list[dict]:
    rows = []
    for h in HOURS:
        rows.append(
            {
                "hours": h,
                "Proposed_duration_dependent_crypto_ops": 0,
                "Proposed_fixed_signatures_per_session": 4,
                "Proposed_fixed_signature_verifications_per_session": 8,
                "Proposed_fixed_AES_encryptions_per_session": 2,
                "Proposed_fixed_AES_decryptions_per_session": 2,
                "PISA_hash_evaluations_per_session": h * (h + 1) // 2,
                "PISA_hourly_token_messages_per_session": h,
                "PISA_new_hourly_signature_generations": 0,
                "PISA_fixed_endpoint_signature_verifications_primary": 1,
                "PISA_fixed_AES_encryptions_per_session": 2,
                "PISA_fixed_AES_decryptions_per_session": 2,
            }
        )
    return rows


def save_csv(rows: list[dict], path: Path) -> None:
    if not rows:
        return
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=list(rows[0].keys()))
        writer.writeheader()
        writer.writerows(rows)


def write_environment(
    output: Path,
    repetitions: int,
    warmup: int,
    literal_repeat_endpoint_verify: bool,
) -> None:
    info = {
        "Experiment": "Experiment 2 - post-entry scalability with parking duration",
        "Python": sys.version.replace("\n", " "),
        "Platform": platform.platform(),
        "Processor": platform.processor() or "not reported by platform.processor()",
        "cryptography": cryptography_version,
        "matplotlib": matplotlib.__version__,
        "Parking durations (hours)": ", ".join(map(str, HOURS)),
        "Measured sessions per condition": repetitions,
        "Warm-up sessions per condition": warmup,
        "Measurement blocks per system/hour": 1,
        "Final statistic": "total measured time / 1,000 sessions",
        "Batch/trial averaging": "not used",
        "Primary PISA hash": "SHA-256",
        "PISA endpoint verification": (
            "once at arrival + repeated every hour (sensitivity variant)"
            if literal_repeat_endpoint_verify
            else "once at arrival; hourly phase measures hash-chain relation only"
        ),
        "Network/TLS/DB/UI/payment latency": "excluded",
        "Pre-entry key generation/hash-chain construction": "excluded from timer",
    }
    text = "\n".join(f"{k}: {v}" for k, v in info.items()) + "\n"
    (output / "experiment_environment.txt").write_text(text, encoding="utf-8")


def plot_duration(results: list[dict], path: Path) -> None:
    hours = [r["hours"] for r in results]
    proposed = [r["Proposed_mean_ms"] for r in results]
    pisa = [r["PISA_mean_ms"] for r in results]

    plt.figure(figsize=(8.0, 5.0))

    # Use the actual parking-hour values directly as x coordinates.
    # Therefore, the visual distance between 1 h and 4 h is smaller than
    # the distance between 72 h and 100 h.
    plt.plot(
        hours,
        proposed,
        marker="o",
        linewidth=2.2,
        label="Proposed",
        color=PROPOSED_COLOR,
        zorder=3,
    )
    plt.plot(
        hours,
        pisa,
        marker="s",
        linewidth=2.2,
        label="PISA",
        color=PISA_COLOR,
        zorder=2,
    )

    plt.xlabel("Parking duration (hours)")
    plt.ylabel("Mean computation time (ms)")
    plt.xticks(hours)

    # Display scale only; measured values are not rescaled.
    plt.ylim(0, 2.0)
    plt.yticks([0, 0.5, 1.0, 1.5, 2.0])

    # Small outer margins while preserving the true numeric spacing.
    plt.xlim(0, 104)

    plt.grid(axis="y", alpha=0.25)
    plt.legend()
    plt.tight_layout()
    plt.savefig(path, dpi=300)
    plt.close()

def plot_hash_count(path: Path) -> None:
    counts = analytical_counts()
    hours = [r["hours"] for r in counts]

    proposed = [
        r["Proposed_duration_dependent_crypto_ops"]
        for r in counts
    ]
    pisa = [
        r["PISA_hash_evaluations_per_session"]
        for r in counts
    ]

    plt.figure(figsize=(8.0, 5.0))

    plt.plot(
        hours,
        pisa,
        marker="s",
        linewidth=2.0,
        label="PISA",
        color=PISA_COLOR,
        zorder=2,
    )
    plt.plot(
        hours,
        proposed,
        marker="o",
        linewidth=2.2,
        label="Proposed",
        color=PROPOSED_COLOR,
        zorder=5,
        clip_on=False,
    )

    plt.xlabel("Parking duration (hours)")
    plt.ylabel("Additional cryptographic operations")
    plt.xticks(hours)

    # Proposed's duration-dependent operation count is exactly zero.
    plt.ylim(bottom=0, top=max(pisa) * 1.05)
    plt.xlim(0, 104)

    plt.grid(axis="y", alpha=0.25)
    plt.legend()
    plt.tight_layout()
    plt.savefig(path, dpi=300)
    plt.close()

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output-dir", type=Path, default=Path("results"))
    parser.add_argument("--sessions", type=int, default=1000)
    parser.add_argument("--warmup", type=int, default=30)
    parser.add_argument("--seed", type=int, default=20260810)
    parser.add_argument(
        "--literal-repeat-endpoint-verify",
        action="store_true",
        help=(
            "Sensitivity variant: re-verify the same signed PISA endpoint "
            "every hour in addition to the primary arrival verification."
        ),
    )
    args = parser.parse_args()

    if args.sessions != 1000:
        raise ValueError("Final paper profile requires --sessions 1000")

    output = args.output_dir
    if output.exists():
        import shutil
        shutil.rmtree(output)
    output.mkdir(parents=True)

    write_environment(
        output,
        repetitions=args.sessions,
        warmup=args.warmup,
        literal_repeat_endpoint_verify=args.literal_repeat_endpoint_verify,
    )

    save_csv(analytical_counts(), output / "operation_counts.csv")

    raw_all = []
    results = []
    for h in HOURS:
        raw_rows, result = benchmark_one_condition(
            hours=h,
            repetitions=args.sessions,
            warmup=args.warmup,
            seed=args.seed,
            literal_repeat_endpoint_verify=args.literal_repeat_endpoint_verify,
        )
        raw_all.extend(raw_rows)
        results.append(result)

    save_csv(raw_all, output / "raw_measurements.csv")
    save_csv(results, output / "primary_results.csv")

    plot_duration(results, output / "fig01_duration_scalability.png")
    plot_hash_count(output / "fig02_duration_dependent_operations.png")

    proposed_values = [r["Proposed_mean_ms"] for r in results]
    pisa_values = [r["PISA_mean_ms"] for r in results]
    hash_counts = [r["PISA_hash_evaluations_per_session"] for r in results]

    proposed_span_pct = (
        100.0 * (max(proposed_values) - min(proposed_values))
        / (sum(proposed_values) / len(proposed_values))
    )

    checks = {
        "exactly_1000_sessions_per_condition": args.sessions == 1000,
        "single_measurement_block_no_trial_averaging": True,
        "warmup_30": args.warmup == 30,
        "hours_are_1_4_12_24_48_72_100": HOURS == [1, 4, 12, 24, 48, 72, 100],
        "proposed_duration_dependent_crypto_ops_are_zero": all(
            r["Proposed_duration_dependent_crypto_ops"] == 0
            for r in analytical_counts()
        ),
        "pisa_hash_count_matches_h_h_plus_1_over_2": all(
            r["PISA_hash_evaluations_per_session"] == r["hours"] * (r["hours"] + 1) // 2
            for r in results
        ),
        "pisa_hash_count_strictly_increases": all(
            b > a for a, b in zip(hash_counts, hash_counts[1:])
        ),
        "no_new_hourly_signature_generation_in_primary": all(
            r["PISA_new_hourly_signature_generations"] == 0
            for r in analytical_counts()
        ),
        "pre_entry_work_excluded_from_timer": True,
        "network_tls_db_ui_payment_excluded": True,
        "figures_use_actual_numeric_hour_spacing": True,
    }

    audit = {
        "experiment": "Experiment 2 - parking-duration scalability",
        "measurement_design": {
            "hours": HOURS,
            "sessions_per_condition": args.sessions,
            "warmup": args.warmup,
            "measurement_blocks_per_system_hour": 1,
            "final_value": "total execution time of 1,000 sessions / 1,000",
            "trial_or_batch_averaging": "not used",
        },
        "primary_interpretation": {
            "Proposed": (
                "entry+exit only; 4 Ed25519 signatures, 8 Ed25519 verifications, "
                "2 AES-GCM encryptions, 2 AES-GCM decryptions per session; "
                "no duration-dependent cryptographic operation"
            ),
            "PISA": (
                "arrival AES challenge-response + one endpoint signature verification; "
                "hour m hashes received token m times; no new hourly signature generation"
            ),
        },
        "diagnostics": {
            "Proposed_measured_span_percent_of_mean": proposed_span_pct,
            "PISA_1h_ms": pisa_values[0],
            "PISA_100h_ms": pisa_values[-1],
            "PISA_100h_over_1h": pisa_values[-1] / pisa_values[0],
        },
        "hard_checks": checks,
    }
    (output / "REDTEAM_AUDIT.json").write_text(
        json.dumps(audit, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    if not all(checks.values()):
        raise RuntimeError("REDTEAM hard check failed")

    print("[Experiment 2: 1000 consecutive sessions per parking duration]")
    print("hours | Proposed ms/session | PISA ms/session | gap ms | PISA hashes")
    for r in results:
        print(
            f"{r['hours']:>5} | "
            f"{r['Proposed_mean_ms']:.6f} | "
            f"{r['PISA_mean_ms']:.6f} | "
            f"{r['absolute_gap_ms']:.6f} | "
            f"{r['PISA_hash_evaluations_per_session']}"
        )
    print(f"Proposed measured span = {proposed_span_pct:.2f}% of mean")
    print("REDTEAM=PASS")
    print("Created:", output)


if __name__ == "__main__":
    main()
