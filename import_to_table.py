#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Универсальный импорт CSV в таблицу MySQL/MariaDB.

Использование:
    python import_to_table.py <profile> <csv_path> [--dry-run] [--truncate] [--chunk N]
"""

import argparse
import hashlib
import os
import re
import sys
from pathlib import Path

import pandas as pd
import yaml
from sqlalchemy import create_engine, text

BASE_DIR = Path(__file__).resolve().parent
PROFILES = BASE_DIR / "import_profiles.yaml"


def find_env() -> Path:
    for candidate in (BASE_DIR / ".env", BASE_DIR.parent / ".env"):
        if candidate.exists():
            return candidate
    return BASE_DIR / ".env"


ENV_PATH = find_env()


def load_env(path: Path) -> None:
    if not path.exists():
        return
    for line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        k = k.strip()
        v = v.strip().strip('"').strip("'")
        if k.startswith("DB_") and k not in os.environ:
            os.environ[k] = v


def get_db_url() -> str:
    host = os.environ.get("DB_HOST", "127.0.0.1")
    port = os.environ.get("DB_PORT", "3306")
    name = os.environ.get("DB_DATABASE", "uti")
    user = os.environ.get("DB_USERNAME", "root")
    pw = os.environ.get("DB_PASSWORD", "")
    return f"mysql+pymysql://{user}:{pw}@{host}:{port}/{name}?charset=utf8mb4"


def load_profile(profile_name: str) -> dict:
    if not PROFILES.exists():
        sys.exit(f"❌ Не найден файл профилей: {PROFILES}")
    data = yaml.safe_load(PROFILES.read_text(encoding="utf-8"))
    if profile_name not in data:
        sys.exit(
            f"❌ Профиль '{profile_name}' не найден. "
            f"Доступны: {', '.join(data.keys())}"
        )
    return data[profile_name]


def parse_dates(series: pd.Series, formats: list) -> pd.Series:
    def _parse(v):
        if pd.isna(v):
            return pd.NaT
        for fmt in formats:
            try:
                return pd.to_datetime(v, format=fmt)
            except (ValueError, TypeError):
                pass
        return pd.NaT
    return series.map(_parse)


CYR = re.compile(r"[А-Яа-яЁё]")


def has_cyr(v) -> bool:
    return isinstance(v, str) and bool(CYR.search(v))


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("profile", help="Имя профиля из import_profiles.yaml")
    ap.add_argument("csv", help="Путь к CSV-файлу")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--truncate", action="store_true")
    ap.add_argument("--chunk", type=int, default=200)
    args = ap.parse_args()

    csv_path = Path(args.csv)
    if not csv_path.is_absolute():
        csv_path = (BASE_DIR / csv_path).resolve()
    if not csv_path.exists():
        sys.exit(f"❌ Файл не найден: {csv_path}")

    load_env(ENV_PATH)
    profile = load_profile(args.profile)

    table = args.profile
    csv_columns = profile["csv_columns"]
    table_map = profile["table_columns"]
    date_col = profile.get("date_column")
    date_fmts = profile.get("date_formats", ["%d.%m.%Y"])
    ignore_cols = set(profile.get("ignore_columns", []))
    fallback_w1 = profile.get("fallback_worker1_from")
    autofill = profile.get("auto_fill", {})

    raw = pd.read_csv(
        csv_path, sep=";", encoding="utf-8-sig", engine="python",
        header=None, quoting=0, on_bad_lines="warn", dtype=str,
        skip_blank_lines=True,
    )
    print(f"📄 Файл:  {csv_path}")
    print(f"🔎 Строк: {len(raw)}, столбцов: {raw.shape[1]}")

    raw = raw.dropna(axis=1, how="all")
    print(f"🔎 После dropna(axis=1): {raw.shape[1]} столбцов")

    max_idx = max(csv_columns) + 1
    while raw.shape[1] < max_idx:
        raw[raw.shape[1]] = None
    raw = raw.iloc[:, :max_idx]

    rename = {i: name for i, name in csv_columns.items() if i < raw.shape[1]}
    raw = raw.rename(columns=rename)

    for col in raw.columns:
        raw[col] = (
            raw[col].astype(str)
            .str.replace("\r", "", regex=False)
            .str.replace("\n", " ", regex=False)
            .str.strip()
            .replace({"": None, "nan": None, "None": None, "NaT": None})
        )

    if date_col and date_col in raw.columns:
        raw[date_col] = parse_dates(raw[date_col], date_fmts)
        before = len(raw)
        raw = raw[raw[date_col].notna()]
        print(f"⚠️  пропущено без даты: {before - len(raw)}")

    if fallback_w1 and fallback_w1 in raw.columns:
        w1_col = "worker1_raw" if "worker1_raw" in raw.columns else "worker1"
        if w1_col in raw.columns:
            mask = raw[w1_col].isna()
            can_fallback = (
                mask
                & raw[fallback_w1].notna()
                & raw[fallback_w1].apply(has_cyr)
            )
            raw.loc[can_fallback, w1_col] = raw.loc[can_fallback, fallback_w1]
            raw.loc[can_fallback, fallback_w1] = None
            print(f"🔁 worker1 восстановлен из '{fallback_w1}': {can_fallback.sum()}")

    if "worker1_raw" in raw.columns:
        before = len(raw)
        raw = raw[raw["worker1_raw"].notna()]
        print(f"⚠️  пропущено без worker1: {before - len(raw)}")

    df = pd.DataFrame()
    for csv_col, db_col in table_map.items():
        if csv_col in raw.columns and csv_col not in ignore_cols:
            df[db_col] = raw[csv_col]

    now = pd.Timestamp.utcnow()
    for k, v in autofill.items():
        if v == "now":
            df[k] = now

    HASH_COLS = [
        "record_date", "serial_number", "worker1", "worker2", "worker3",
        "pc_version", "ahrs", "compass_version", "camera_mount", "bch",
        "wings_check", "parachute_check", "asc_check", "calibration",
        "notes", "defect",
    ]

    def make_hash(row) -> str:
        parts = []
        for c in HASH_COLS:
            v = row.get(c)
            if c == "record_date" and pd.notna(v):
                v = pd.Timestamp(v).strftime("%Y-%m-%d")
            parts.append("" if pd.isna(v) else str(v))
        return hashlib.sha256("|".join(parts).encode("utf-8")).hexdigest()

    df["source_hash"] = df.apply(make_hash, axis=1)

    print(f"✅ К импорту готово строк: {len(df)}")
    if args.dry_run:
        print("🧪 DRY-RUN — в БД не пишем")
        print(df.head(3).to_string())
        return

    if df.empty:
        sys.exit("❌ Нечего импортировать")

    engine = create_engine(get_db_url(), pool_pre_ping=True)

    with engine.begin() as conn:
        if args.truncate:
            print(f"🧨 TRUNCATE {table}")
            conn.execute(text(f"TRUNCATE TABLE {table}"))
        before = conn.execute(text(f"SELECT COUNT(*) FROM {table}")).scalar()
    print(f"📊 Было строк в {table}: {before}")

    insert_cols = list(df.columns)
    cols_sql = ", ".join(insert_cols)
    ph_sql = ", ".join(f":{c}" for c in insert_cols)
    insert_sql = text(
        f"INSERT IGNORE INTO {table} ({cols_sql}) VALUES ({ph_sql})"
    )

    total_new = 0
    total_ignored = 0
    for start in range(0, len(df), args.chunk):
        chunk = df.iloc[start:start + args.chunk]
        with engine.begin() as conn:
            for _, row in chunk.iterrows():
                res = conn.execute(insert_sql, {
                    c: (None if pd.isna(row.get(c)) else row.get(c))
                    for c in insert_cols
                })
                if res.rowcount == 1:
                    total_new += 1
                else:
                    total_ignored += 1
        print(f"  → обработано {min(start + args.chunk, len(df))}/{len(df)}")

    print(f"✅ Вставлено новых: {total_new}")
    print(f"⏭️  Пропущено дублей: {total_ignored}")

    with engine.begin() as conn:
        after = conn.execute(text(f"SELECT COUNT(*) FROM {table}")).scalar()
    print(f"🎉 Готово. Было: {before}, стало: {after}, прирост: {after - before}")


if __name__ == "__main__":
    main()