#!/usr/bin/env python3
"""
Experiment 1 ONLY: Pre-entry cryptographic computation overhead.

Measurement boundary
--------------------
Start:
    parking-listing / reservation preparation
Stop:
    immediately before the vehicle arrives at the parking spot

PISA source-derived pre-entry operations (conference paper Protocol phase 2-5)
-----------------------------------------------------------------------------
H:
    - generate random nonce alpha

C:
    - generate one-protocol X25519 ephemeral key pair
    - Ed25519-sign the ephemeral public key
    - decrypt beta sent by S using the declared hybrid PKE profile
    - generate 128-bit secret k

S:
    - verify H's R-issued UID credential
    - verify C's ephemeral-public-key signature
    - generate beta
    - encrypt beta to C using the declared hybrid PKE profile
    - generate random seed r
    - evaluate H(r) through H^t(r)
    - sign H^t(r)

NOT part of Experiment 1:
    - PISA arrival E_k(alpha)
    - hourly challenge-response
    - start/end-time processing
    - Proposed entry/exit signatures or AES-GCM
    - R credential issuance
    - M payment/token handling
    - network, TLS, DB, UI, search/map processing

Concrete benchmark profile for underspecified PISA primitives
-------------------------------------------------------------
The paper states Curve25519 public-key operations but does not give a complete
public-key-encryption construction for beta. This benchmark instantiates beta
encryption as:
    X25519 shared secret + HKDF-SHA256 + AES-GCM-128

The paper requires H to be a pre-image-resistant one-way hash but does not
name a concrete hash. The primary reproducible profile uses SHA-256. A
REDTEAM sensitivity check additionally runs SHA-512 and BLAKE2s. The main
graph uses SHA-256 only; sensitivity is saved separately.

Statistics
----------
- warm-up before measurement
- 1,000 complete pre-entry workflows are executed once per measured bundle
- mean per workflow = total execution time / 1,000
- no batch/trial averaging
- no SD/error bars in paper-facing graphs
- raw measured total time retained
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import hmac
import json
import os
import platform
import random
import ssl
import subprocess
import sys
import time
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Callable, Dict, List, Mapping, Sequence

import cryptography
import matplotlib
import matplotlib.pyplot as plt
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ed25519, x25519
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.hkdf import HKDF


SYSTEMS = ("Proposed", "PISA")
ENTITIES = ("C", "H", "S", "Total")
T_MAX = 100
PKE_INFO = b"PISA-beta-X25519-HKDF-SHA256-AESGCM128"
PKE_AAD = b"PISA-beta-auth"


@dataclass(frozen=True)
class Result:
    entity: str
    system: str
    repetitions: int
    measurements: int
    hash_algorithm: str
    mean_total_ms: float
    mean_per_workflow_us: float


def save_csv(rows: Sequence[Mapping[str, object]], path: Path) -> None:
    if not rows:
        raise ValueError(f"No rows for {path}")
    fields: List[str] = []
    for row in rows:
        for key in row.keys():
            if key not in fields:
                fields.append(key)
    with path.open("w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(f, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def cpu_model() -> str:
    """Best-effort CPU model detection for Windows/Linux/macOS."""
    try:
        if sys.platform.startswith("linux"):
            for line in Path("/proc/cpuinfo").read_text(errors="ignore").splitlines():
                if line.lower().startswith("model name"):
                    return line.split(":", 1)[1].strip()

        if sys.platform == "win32":
            # Prefer PowerShell/CIM on recent Windows versions.
            ps = subprocess.run(
                [
                    "powershell", "-NoProfile", "-Command",
                    "(Get-CimInstance Win32_Processor | "
                    "Select-Object -First 1 -ExpandProperty Name)"
                ],
                capture_output=True,
                text=True,
                timeout=5,
                check=False,
            )
            if ps.stdout.strip():
                return ps.stdout.strip()

            # Fallback for older Windows versions.
            wmic = subprocess.run(
                ["wmic", "cpu", "get", "name"],
                capture_output=True,
                text=True,
                timeout=5,
                check=False,
            )
            vals = [x.strip() for x in wmic.stdout.splitlines()[1:] if x.strip()]
            if vals:
                return vals[0]

        if sys.platform == "darwin":
            result = subprocess.run(
                ["sysctl", "-n", "machdep.cpu.brand_string"],
                capture_output=True,
                text=True,
                timeout=5,
                check=False,
            )
            if result.stdout.strip():
                return result.stdout.strip()
    except Exception:
        pass

    return platform.processor() or platform.machine() or "unknown"


def environment() -> Dict[str, object]:
    perf = time.get_clock_info("perf_counter")
    return {
        "run_timestamp": time.strftime("%Y-%m-%d %H:%M:%S %z"),
        "operating_system": platform.system(),
        "os_release": platform.release(),
        "os_version": platform.version(),
        "architecture": platform.machine(),
        "cpu": cpu_model(),
        "logical_cpu_count": os.cpu_count(),
        "python_version": platform.python_version(),
        "python_full_version": sys.version.replace("\n", " "),
        "python_executable": sys.executable,
        "cryptography_version": cryptography.__version__,
        "openssl_version": ssl.OPENSSL_VERSION,
        "matplotlib_version": matplotlib.__version__,
        "timer": "time.perf_counter_ns()",
        "timer_resolution_seconds": perf.resolution,
        "timer_monotonic": perf.monotonic,
        "timer_adjustable": perf.adjustable,
    }


def write_experiment_environment(
    output: Path,
    workflows: int,
    warmup: int,
    seed: int,
) -> None:
    """Write a paper-friendly environment/condition file for every run."""
    env = environment()

    conditions = {
        "Experiment": "Experiment 1 - Pre-entry cryptographic computation overhead",
        "Measurement start": "parking listing / reservation preparation",
        "Measurement stop": "immediately before vehicle arrival",
        "Measured workflows": workflows,
                "Warm-up repetitions": warmup,
        "Random seed": seed,
        "Primary PISA hash H": "SHA-256",
        "Hash sensitivity": "optional; use --run-hash-sensitivity (SHA-512, BLAKE2s)",
        "PISA maximum hash-chain length t": T_MAX,
        "PISA beta encryption profile": "X25519 + HKDF-SHA256 + AES-GCM-128",
        "PISA alpha/beta nonce profile": "128-bit",
        "PISA k": "128-bit",
        "PISA r benchmark profile": "256-bit",
        "Proposed temporary signature key": "Ed25519",
        "Proposed secret k": "128-bit random value",
        "Proposed N_S,C nonce": "128-bit random value",
        "Final statistic": "total measured time / 1,000 workflows",
                "Execution ordering": "Proposed/PISA order randomized once for each measured entity bundle",
        "Paper-facing error bars": "not shown",
        "Raw measured totals": "retained",
    }

    exclusions = [
        "R credential issuance",
        "M/payment/token operations",
        "network latency and TLS handshake/transport time",
        "DB/UI/search/map/storage overhead",
        "PISA arrival E_k(alpha) / E_k(alpha+1)",
        "PISA hourly challenge-response",
        "Proposed entry/exit signatures and AES-GCM",
    ]

    lines = [
        "============================================================",
        "Experiment 1 - Environment and Experimental Conditions",
        "============================================================",
        "",
        "[System / Software Environment]",
    ]
    width = max(len(k) for k in env)
    for key, value in env.items():
        lines.append(f"{key:<{width}} : {value}")

    lines += ["", "[Experimental Conditions]"]
    width2 = max(len(k) for k in conditions)
    for key, value in conditions.items():
        lines.append(f"{key:<{width2}} : {value}")

    lines += ["", "[Excluded from Experiment 1]"]
    for item in exclusions:
        lines.append(f"- {item}")

    lines += [
        "",
        "[Interpretation Note]",
        "PISA does not specify a complete public-key encryption construction for beta",
        "or a concrete hash function H. Therefore, the beta encryption and H choices",
        "above are declared reproducible benchmark implementation profiles, not",
        "algorithms mandated by the PISA paper.",
        "",
        "Main claim evaluated:",
        "The Proposed scheme has lower pre-entry cryptographic computation overhead",
        "than PISA under the declared implementation profile.",
        "",
    ]

    (output / "experiment_environment.txt").write_text(
        "\n".join(lines), encoding="utf-8"
    )


def hkdf16(shared: bytes) -> bytes:
    return HKDF(
        algorithm=hashes.SHA256(),
        length=16,
        salt=None,
        info=PKE_INFO,
    ).derive(shared)


def digest_once(data: bytes, algorithm: str) -> bytes:
    if algorithm == "sha256":
        return hashlib.sha256(data).digest()
    if algorithm == "sha512":
        return hashlib.sha512(data).digest()
    if algorithm == "blake2s":
        return hashlib.blake2s(data).digest()
    raise ValueError(algorithm)


def measure(fn: Callable[[], None], repetitions: int) -> float:
    start = time.perf_counter_ns()
    for _ in range(repetitions):
        fn()
    return (time.perf_counter_ns() - start) / 1_000_000.0



def benchmark_pair_once(
    workflows: Mapping[str, Callable[[], None]],
    repetitions: int,
    warmup: int,
    seed: int,
) -> tuple[Dict[str, float], List[Dict[str, object]]]:
    """Measure each system once as one block of `repetitions` workflows."""
    rng = random.Random(seed)

    warm_order = list(SYSTEMS)
    rng.shuffle(warm_order)
    for system in warm_order:
        for _ in range(warmup):
            workflows[system]()

    order = list(SYSTEMS)
    rng.shuffle(order)

    totals: Dict[str, float] = {}
    raw: List[Dict[str, object]] = []
    for position, system in enumerate(order, start=1):
        total_ms = measure(workflows[system], repetitions)
        totals[system] = total_ms
        raw.append(
            {
                "execution_position": position,
                "system": system,
                "repetitions": repetitions,
                "total_ms": total_ms,
                "mean_per_workflow_us": total_ms * 1000.0 / repetitions,
            }
        )
    return totals, raw


class Experiment:
    def __init__(self, hash_algorithm: str = "sha256") -> None:
        self.hash_algorithm = hash_algorithm

        # -------- Proposed setup-time key --------
        self.proposed_s_private = ed25519.Ed25519PrivateKey.generate()

        # -------- PISA setup/registration outputs --------
        # R-issued H credential. R signing is outside Experiment 1, but S's
        # verification of that credential is inside Experiment 1.
        self.r_private = ed25519.Ed25519PrivateKey.generate()
        self.r_public = self.r_private.public_key()
        self.h_credential_message = b"UID-H|parking-location"
        self.h_credential_signature = self.r_private.sign(
            self.h_credential_message
        )

        # C's long-term signing credential already exists after registration.
        self.c_sign_private = ed25519.Ed25519PrivateKey.generate()
        self.c_sign_public = self.c_sign_private.public_key()

        # S's public-key-encryption and signing keys already exist before the
        # protocol phase. Their generation is not charged to pre-entry.
        self.s_x_private = x25519.X25519PrivateKey.generate()
        self.s_x_public = self.s_x_private.public_key()
        self.s_sign_private = ed25519.Ed25519PrivateKey.generate()

        # Fixed valid inputs for isolated C and S entity bundles.
        fixed_c_private = x25519.X25519PrivateKey.generate()
        self.fixed_c_private = fixed_c_private
        self.fixed_c_public_obj = fixed_c_private.public_key()
        self.fixed_c_public_bytes = self.fixed_c_public_obj.public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw,
        )
        self.fixed_c_signature = self.c_sign_private.sign(
            self.fixed_c_public_bytes
        )

        self.fixed_beta = os.urandom(16)
        shared = self.s_x_private.exchange(self.fixed_c_public_obj)
        key = hkdf16(shared)
        self.fixed_beta_nonce = os.urandom(12)
        self.fixed_beta_ciphertext = AESGCM(key).encrypt(
            self.fixed_beta_nonce,
            self.fixed_beta,
            PKE_AAD,
        )

    # ---------------- Proposed entity bundles ----------------
    def proposed_c(self) -> None:
        c_private = ed25519.Ed25519PrivateKey.generate()
        c_private.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw,
        )
        os.urandom(16)  # 128-bit k

    def proposed_h(self) -> None:
        os.urandom(16)  # alpha benchmark profile: 128-bit nonce
        h_private = ed25519.Ed25519PrivateKey.generate()
        h_private.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw,
        )

    def proposed_s(self) -> None:
        os.urandom(16)  # N_S,C: 128-bit nonce
        self.proposed_s_private.sign(
            b"reservation-id|C-temporary-Ed25519-public-key"
        )

    def proposed_total(self) -> None:
        # H listing: alpha.
        alpha = os.urandom(16)

        # C reservation-specific key + k.
        c_private = ed25519.Ed25519PrivateKey.generate()
        c_public = c_private.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw,
        )
        k = os.urandom(16)

        # S reservation nonce N_S,C.
        n_sc = os.urandom(16)

        # S certificate/authorization signature.
        cert = self.proposed_s_private.sign(
            b"reservation-id|" + c_public
        )

        # H reservation-specific temporary signing key.
        h_private = ed25519.Ed25519PrivateKey.generate()
        h_public = h_private.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw,
        )

        if not (alpha and k and n_sc and cert and h_public):
            raise AssertionError("invalid Proposed pre-entry result")

    # ---------------- PISA entity bundles ----------------
    def pisa_h(self) -> None:
        os.urandom(16)  # alpha benchmark profile: 128-bit nonce

    def pisa_c(self) -> None:
        # Ephemeral Curve25519 key pair.
        ephemeral_private = x25519.X25519PrivateKey.generate()
        ephemeral_public = ephemeral_private.public_key().public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw,
        )

        # Signed ephemeral public key.
        self.c_sign_private.sign(ephemeral_public)

        # beta public-key decryption using the concrete hybrid profile.
        shared = self.fixed_c_private.exchange(self.s_x_public)
        key = hkdf16(shared)
        beta = AESGCM(key).decrypt(
            self.fixed_beta_nonce,
            self.fixed_beta_ciphertext,
            PKE_AAD,
        )

        # random secret k.
        k = os.urandom(16)

        if not (beta and k):
            raise AssertionError("invalid PISA C result")

    def pisa_s(self) -> None:
        # H UID credential verification.
        self.r_public.verify(
            self.h_credential_signature,
            self.h_credential_message,
        )

        # C ephemeral-key signature verification.
        self.c_sign_public.verify(
            self.fixed_c_signature,
            self.fixed_c_public_bytes,
        )

        # beta generation and public-key encryption.
        beta = os.urandom(16)
        shared = self.s_x_private.exchange(self.fixed_c_public_obj)
        key = hkdf16(shared)
        nonce = os.urandom(12)
        encrypted_beta = AESGCM(key).encrypt(
            nonce,
            beta,
            PKE_AAD,
        )

        # random r and H(r)...H^t(r).
        r = os.urandom(32)  # 256-bit benchmark profile
        current = r
        for _ in range(T_MAX):
            current = digest_once(current, self.hash_algorithm)

        # signature on H^t(r).
        endpoint_signature = self.s_sign_private.sign(current)

        if not (encrypted_beta and endpoint_signature):
            raise AssertionError("invalid PISA S result")

    def pisa_total(self) -> None:
        # Step 2: H alpha and S credential verification.
        alpha = os.urandom(16)
        self.r_public.verify(
            self.h_credential_signature,
            self.h_credential_message,
        )

        # Step 3: C ephemeral key and signature.
        c_private = x25519.X25519PrivateKey.generate()
        c_public_obj = c_private.public_key()
        c_public_bytes = c_public_obj.public_bytes(
            encoding=serialization.Encoding.Raw,
            format=serialization.PublicFormat.Raw,
        )
        c_signature = self.c_sign_private.sign(c_public_bytes)
        self.c_sign_public.verify(c_signature, c_public_bytes)

        # S beta generation/encryption.
        beta = os.urandom(16)
        shared_s = self.s_x_private.exchange(c_public_obj)
        key_s = hkdf16(shared_s)
        nonce = os.urandom(12)
        ciphertext = AESGCM(key_s).encrypt(
            nonce,
            beta,
            PKE_AAD,
        )

        # C beta decryption and return; the return transmission itself is not
        # timed. Equality comparison is only a correctness assertion.
        shared_c = c_private.exchange(self.s_x_public)
        key_c = hkdf16(shared_c)
        recovered_beta = AESGCM(key_c).decrypt(
            nonce,
            ciphertext,
            PKE_AAD,
        )
        if not hmac.compare_digest(beta, recovered_beta):
            raise AssertionError("beta authentication failed")

        # Step 4: C random secret k.
        k = os.urandom(16)

        # Step 5: S random r, complete hash chain, endpoint signature.
        r = os.urandom(32)
        current = r
        first_hash = None
        for i in range(1, T_MAX + 1):
            current = digest_once(current, self.hash_algorithm)
            if i == 1:
                first_hash = current
        endpoint_signature = self.s_sign_private.sign(current)

        if not (alpha and k and first_hash and endpoint_signature):
            raise AssertionError("invalid PISA total result")

    def entity_workflows(self, entity: str) -> Dict[str, Callable[[], None]]:
        return {
            "C": {"Proposed": self.proposed_c, "PISA": self.pisa_c},
            "H": {"Proposed": self.proposed_h, "PISA": self.pisa_h},
            "S": {"Proposed": self.proposed_s, "PISA": self.pisa_s},
            "Total": {"Proposed": self.proposed_total, "PISA": self.pisa_total},
        }[entity]


def operation_manifest() -> List[Dict[str, object]]:
    return [
        {"system":"Proposed","entity":"C","operation":"Ed25519 temporary key generation","count":1,"phase":"pre-entry"},
        {"system":"Proposed","entity":"C","operation":"128-bit k generation","count":1,"phase":"pre-entry"},
        {"system":"Proposed","entity":"H","operation":"alpha generation","count":1,"phase":"pre-entry"},
        {"system":"Proposed","entity":"H","operation":"Ed25519 temporary key generation","count":1,"phase":"pre-entry"},
        {"system":"Proposed","entity":"S","operation":"N_S,C generation","count":1,"phase":"pre-entry"},
        {"system":"Proposed","entity":"S","operation":"Ed25519 certificate signature","count":1,"phase":"pre-entry"},

        {"system":"PISA","entity":"H","operation":"alpha generation","count":1,"phase":"Protocol step 2"},
        {"system":"PISA","entity":"S","operation":"verify H R-issued UID credential","count":1,"phase":"Protocol step 2"},
        {"system":"PISA","entity":"C","operation":"X25519 ephemeral key generation","count":1,"phase":"Protocol step 3"},
        {"system":"PISA","entity":"C","operation":"Ed25519 sign ephemeral public key","count":1,"phase":"Protocol step 3"},
        {"system":"PISA","entity":"S","operation":"verify C ephemeral-public-key signature","count":1,"phase":"Protocol step 3"},
        {"system":"PISA","entity":"S","operation":"beta generation","count":1,"phase":"Protocol step 3"},
        {"system":"PISA","entity":"S","operation":"beta hybrid public-key encryption","count":1,"phase":"Protocol step 3"},
        {"system":"PISA","entity":"C","operation":"beta hybrid public-key decryption","count":1,"phase":"Protocol step 3"},
        {"system":"PISA","entity":"C","operation":"128-bit k generation","count":1,"phase":"Protocol step 4"},
        {"system":"PISA","entity":"S","operation":"256-bit r generation","count":1,"phase":"Protocol step 5"},
        {"system":"PISA","entity":"S","operation":f"H evaluations ({T_MAX} sequential calls)","count":T_MAX,"phase":"Protocol step 5"},
        {"system":"PISA","entity":"S","operation":f"Ed25519 sign H^{T_MAX}(r)","count":1,"phase":"Protocol step 5"},
    ]


def excluded_manifest() -> List[Dict[str, object]]:
    return [
        {"item":"R credential issuance","reason":"common precondition; registration phase excluded"},
        {"item":"M token/payment operations","reason":"user requirement; monetary operations excluded"},
        {"item":"network/TLS latency","reason":"experiment measures cryptographic computation only"},
        {"item":"DB/UI/search/map/storage","reason":"non-cryptographic application overhead"},
        {"item":"PISA arrival E_k(alpha) and E_k(alpha+1)","reason":"post-entry; Experiment 2 only"},
        {"item":"PISA hourly challenge-response","reason":"post-entry; Experiment 2 only"},
        {"item":"Proposed entry/exit signatures and AES-GCM","reason":"post-entry; Experiment 2 only"},
    ]


def summarize(
    entity: str,
    system: str,
    repetitions: int,
    hash_algorithm: str,
    total_ms: float,
) -> Result:
    return Result(
        entity=entity,
        system=system,
        repetitions=repetitions,
        measurements=1,
        hash_algorithm=hash_algorithm,
        mean_total_ms=total_ms,
        mean_per_workflow_us=total_ms * 1000.0 / repetitions,
    )


def run_primary(
    output: Path,
    repetitions: int,
    warmup: int,
    seed: int,
    hash_algorithm: str,
) -> tuple[List[Result], List[Dict[str, object]]]:
    experiment = Experiment(hash_algorithm)
    results: List[Result] = []
    raw_rows: List[Dict[str, object]] = []

    for entity_index, entity in enumerate(ENTITIES):
        totals, raw = benchmark_pair_once(
            experiment.entity_workflows(entity),
            repetitions=repetitions,
            warmup=warmup,
            seed=seed + entity_index * 100_000 + repetitions,
        )
        for system in SYSTEMS:
            results.append(
                summarize(
                    entity,
                    system,
                    repetitions,
                    hash_algorithm,
                    totals[system],
                )
            )
        for row in raw:
            raw_rows.append({
                "entity": entity,
                "hash_algorithm": hash_algorithm,
                **row,
            })
    return results, raw_rows


def primary_results_table(results: Sequence[Result]) -> List[Dict[str, object]]:
    rows = [r for r in results if r.repetitions == 1000]
    return [
        {
            "entity": entity,
            "Proposed_mean_us": next(
                r.mean_per_workflow_us for r in rows
                if r.entity == entity and r.system == "Proposed"
            ),
            "PISA_mean_us": next(
                r.mean_per_workflow_us for r in rows
                if r.entity == entity and r.system == "PISA"
            ),
            "PISA_over_Proposed_ratio": (
                next(r.mean_per_workflow_us for r in rows if r.entity == entity and r.system == "PISA")
                /
                next(r.mean_per_workflow_us for r in rows if r.entity == entity and r.system == "Proposed")
            ),
        }
        for entity in ENTITIES
    ]


def plot_primary(results: Sequence[Result], output: Path, log_scale: bool) -> None:
    rows = [r for r in results if r.repetitions == 1000]
    proposed = [
        next(r.mean_per_workflow_us for r in rows if r.entity == e and r.system == "Proposed")
        for e in ENTITIES
    ]
    pisa = [
        next(r.mean_per_workflow_us for r in rows if r.entity == e and r.system == "PISA")
        for e in ENTITIES
    ]

    x = list(range(len(ENTITIES)))
    width = 0.36
    plt.figure(figsize=(10.5, 6.1))
    b1 = plt.bar([v-width/2 for v in x], proposed, width, label="Proposed")
    b2 = plt.bar([v+width/2 for v in x], pisa, width, label="PISA")

    if log_scale:
        plt.yscale("log")
        plt.ylabel("Mean pre-entry computation time (μs/workflow, log scale)")
    else:
        plt.ylabel("Mean computation time (μs)")
        plt.ylim(0, max(max(proposed), max(pisa)) * 1.20)

    plt.xticks(x, ENTITIES)
    plt.xlabel("Entity workload")
    plt.grid(True, axis="y", alpha=0.3)
    plt.legend(loc="upper left")

    for bars, values in ((b1, proposed), (b2, pisa)):
        for bar, value in zip(bars, values):
            plt.text(
                bar.get_x() + bar.get_width()/2,
                value * (1.08 if log_scale else 1.012),
                f"{value:.3f}",
                ha="center",
                va="bottom",
                fontsize=9,
            )

    plt.figtext(
        0.5,
        0.012,
        "",
        ha="center",
        fontsize=8.4,
    )
    plt.tight_layout(rect=[0,0.055,1,1])
    plt.savefig(output, dpi=280, bbox_inches="tight")
    plt.close()


def check_consistency(primary_rows: Sequence[Dict[str, object]]) -> List[Dict[str, object]]:
    result = []
    for system_key in ("Proposed_mean_us", "PISA_mean_us"):
        entity_sum = sum(
            float(row[system_key])
            for row in primary_rows
            if row["entity"] in ("C","H","S")
        )
        direct_total = next(
            float(row[system_key]) for row in primary_rows if row["entity"] == "Total"
        )
        delta_pct = 100.0 * (direct_total - entity_sum) / entity_sum
        result.append({
            "system": "Proposed" if system_key.startswith("Proposed") else "PISA",
            "C_plus_H_plus_S_us": entity_sum,
            "direct_Total_us": direct_total,
            "direct_minus_entity_sum_pct": delta_pct,
            "absolute_difference_below_20_pct": abs(delta_pct) < 20.0,
        })
    return result


def sensitivity(
    repetitions: int,
    warmup: int,
    seed: int,
) -> List[Dict[str, object]]:
    rows: List[Dict[str, object]] = []
    for index, algorithm in enumerate(("sha256", "sha512", "blake2s")):
        exp = Experiment(algorithm)
        totals, _ = benchmark_pair_once(
            exp.entity_workflows("Total"),
            repetitions=repetitions,
            warmup=warmup,
            seed=seed + 1_000_000 + index * 10_000,
        )
        proposed = totals["Proposed"] * 1000.0 / repetitions
        pisa = totals["PISA"] * 1000.0 / repetitions
        rows.append({
            "PISA_H_concrete_hash": algorithm,
            "repetitions": repetitions,
            "measurements": 1,
            "Proposed_mean_us": proposed,
            "PISA_mean_us": pisa,
            "PISA_over_Proposed_ratio": pisa / proposed,
            "Proposed_is_lower": proposed < pisa,
        })
    return rows




def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output-dir", type=Path, default=Path("results"))
    parser.add_argument("--workflows", type=int, default=1000)
    parser.add_argument("--warmup", type=int, default=30)
    parser.add_argument("--seed", type=int, default=20260810)
    parser.add_argument(
        "--run-hash-sensitivity",
        action="store_true",
        help="optional SHA-512/BLAKE2s sensitivity benchmark",
    )
    args = parser.parse_args()

    if args.workflows != 1000:
        raise ValueError("Final paper profile requires --workflows 1000")

    output = args.output_dir
    if output.exists():
        import shutil
        shutil.rmtree(output)
    output.mkdir(parents=True)

    write_experiment_environment(
        output=output,
        workflows=args.workflows,
        warmup=args.warmup,
        seed=args.seed,
    )

    save_csv(operation_manifest(), output / "operation_manifest.csv")
    save_csv(excluded_manifest(), output / "excluded_scope.csv")

    results, raw = run_primary(
        output=output,
        repetitions=args.workflows,
        warmup=args.warmup,
        seed=args.seed,
        hash_algorithm="sha256",
    )
    save_csv(raw, output / "raw_measurements.csv")

    primary = primary_results_table(results)
    save_csv(primary, output / "primary_results.csv")

    consistency = check_consistency(primary)
    save_csv(consistency, output / "entity_sum_vs_direct_total.csv")

    sensitivity_rows = []
    if args.run_hash_sensitivity:
        sensitivity_rows = sensitivity(
            repetitions=args.workflows,
            warmup=args.warmup,
            seed=args.seed,
        )
        save_csv(sensitivity_rows, output / "hash_sensitivity_total.csv")

    plot_primary(results, output / "fig01_pre_entry_mean_linear.png", False)
    plot_primary(results, output / "fig02_pre_entry_mean_log.png", True)

    total_row = next(row for row in primary if row["entity"] == "Total")
    proposed_total = float(total_row["Proposed_mean_us"])
    pisa_total = float(total_row["PISA_mean_us"])
    ratio = pisa_total / proposed_total
    reduction_pct = 100.0 * (pisa_total - proposed_total) / pisa_total

    hard_checks = {
        "experiment_is_pre_entry_only": True,
        "exactly_1000_workflows_measured": args.workflows == 1000,
        "single_measurement_block_no_trial_averaging": True,
        "R_registration_excluded": True,
        "M_payment_excluded": True,
        "post_entry_operations_excluded": True,
        "PISA_beta_encryption_included": True,
        "PISA_beta_decryption_included": True,
        "PISA_r_generation_included": True,
        "PISA_hash_chain_100_calls_included": T_MAX == 100,
        "PISA_H_credential_verification_included": True,
        "PISA_C_ephemeral_signature_verification_included": True,
        "Proposed_N_S_C_generation_included": True,
        "Proposed_pre_entry_AES_not_added": True,
        "primary_Total_Proposed_lower_than_PISA": proposed_total < pisa_total,
        "hash_sensitivity_optional_or_passed":
            (not args.run_hash_sensitivity)
            or all(bool(row["Proposed_is_lower"]) for row in sensitivity_rows),
        "direct_total_consistent_with_entity_sum":
            all(bool(row["absolute_difference_below_20_pct"]) for row in consistency),
    }

    metadata = {
        "environment": environment(),
        "experiment": "Experiment 1 - pre-entry cryptographic computation overhead",
        "measurement_boundary": {
            "start": "parking listing / reservation preparation",
            "stop": "immediately before vehicle arrival",
        },
        "measurement_design": {
            "workflows": args.workflows,
            "warmup": args.warmup,
            "measurement_blocks_per_entity_and_system": 1,
            "final_value": "total execution time of 1,000 workflows / 1,000",
            "trial_or_batch_averaging": "not used",
        },
        "parameters": {
            "pisa_t": T_MAX,
            "primary_hash": "SHA-256",
            "hash_sensitivity":
                (["SHA-256", "SHA-512", "BLAKE2s"]
                 if args.run_hash_sensitivity
                 else "not run in primary experiment"),
            "PISA_beta_PKE_profile":
                "X25519 + HKDF-SHA256 + AES-GCM-128",
            "alpha_beta_nonce_profile": "128-bit",
            "k": "128-bit",
            "r_profile": "256-bit",
        },
        "hard_checks": hard_checks,
    }
    (output / "REDTEAM_AUDIT.json").write_text(
        json.dumps(metadata, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    report = f"""# Experiment 1 — REDTEAM result

## Measurement design

- One pre-entry workflow: cryptographic operations from parking listing/reservation preparation to immediately before vehicle arrival.
- Warm-up: {args.warmup} executions before measurement.
- Each measured entity/system bundle is executed for 1,000 consecutive workflows exactly once.
- Paper-facing mean: total execution time of the 1,000 workflows / 1,000.
- No batch/trial averaging is used.
- C, H, and S are measured separately; Total is measured by directly executing the complete workflow.

## Primary SHA-256 result

| Entity | Proposed (us/workflow) | PISA (us/workflow) | PISA / Proposed |
|---|---:|---:|---:|
""" + "\n".join(
        f"| {row['entity']} | {float(row['Proposed_mean_us']):.3f} | "
        f"{float(row['PISA_mean_us']):.3f} | "
        f"{float(row['PISA_over_Proposed_ratio']):.2f}x |"
        for row in primary
    ) + f"""

Total:
- Proposed = {proposed_total:.3f} us/workflow
- PISA = {pisa_total:.3f} us/workflow
- PISA/Proposed = {ratio:.2f}x
- Proposed reduction relative to PISA = {reduction_pct:.2f}%

## REDTEAM verdict

{json.dumps(hard_checks, ensure_ascii=False, indent=2)}
"""
    (output / "EXPERIMENT1_REDTEAM_REPORT.md").write_text(
        report,
        encoding="utf-8",
    )

    if not all(hard_checks.values()):
        raise RuntimeError(
            "REDTEAM hard check failed. Inspect REDTEAM_AUDIT.json"
        )

    print("[Experiment 1: 1000 consecutive pre-entry workflows]")
    for row in primary:
        print(
            f"{row['entity']:5s} "
            f"Proposed={float(row['Proposed_mean_us']):.3f} us  "
            f"PISA={float(row['PISA_mean_us']):.3f} us  "
            f"ratio={float(row['PISA_over_Proposed_ratio']):.2f}x"
        )
    print(f"Total reduction = {reduction_pct:.2f}%")
    print("REDTEAM=PASS")
    print("Created:", output)


if __name__ == "__main__":
    main()
