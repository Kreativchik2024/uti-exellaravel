import pandas as pd

df = pd.read_csv(
    "storage/import/cross3_8.csv",
    sep=";", encoding="utf-8-sig", engine="python",
    header=None, quoting=0, on_bad_lines="warn", dtype=str,
    skip_blank_lines=True,
).dropna(axis=1, how="all").iloc[:, :17]

print("=== Строки, где col15 (unused) не пуст ===")
print(df[df[15].notna()][[0, 1, 2, 15, 16]].to_string())