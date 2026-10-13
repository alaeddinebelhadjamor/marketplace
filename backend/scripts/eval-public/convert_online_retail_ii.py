#!/usr/bin/env python3
"""
Convertit Online Retail II (UCI, CC BY 4.0) au format de la table `orders`
du marketplace : (order_id, sku, product_name, qty, price, vendor_id, processed_at).

Sortie : un fichier SQLite autonome (jamais la base MySQL réelle).
Chaque filtre est compté et écrit dans un rapport JSON (traçabilité du rapport PFE).

Usage : convert_online_retail_ii.py <online_retail_II.xlsx> <sortie.sqlite> <rapport.json>
"""
import json
import re
import sqlite3
import sys
from collections import Counter, defaultdict
from datetime import datetime

import openpyxl

# Codes produits : 5 chiffres + 0 à 2 lettres (ex. 85123A). Exclut POST, D, M,
# BANK CHARGES, DOT, ADJUST, TEST001, gift_0001_*, AMAZONFEE, CRUK, etc.
PRODUCT_CODE = re.compile(r"^\d{5}[A-Za-z]{0,2}$")

VENDOR_ID = 1  # le jeu n'a pas de vendeur : un vendeur unique fictif


def main(xlsx, out_sqlite, out_report):
    stats = Counter()
    wb = openpyxl.load_workbook(xlsx, read_only=True, data_only=True)
    rows = []
    sheet_max_date = None
    for ws in wb.worksheets:
        stats[f"sheet:{ws.title}:rows"] = 0
        kept_from_sheet = []
        for i, r in enumerate(ws.iter_rows(values_only=True)):
            if i == 0:
                continue  # en-tête
            inv, code, desc, qty, dt, price = r[0], r[1], r[2], r[3], r[4], r[5]
            stats[f"sheet:{ws.title}:rows"] += 1
            kept_from_sheet.append((inv, code, desc, qty, dt, price))
        # Les deux feuilles se recouvrent en décembre 2010 : on ne garde de la
        # feuille suivante que les lignes strictement postérieures à la précédente.
        if sheet_max_date is not None:
            before = len(kept_from_sheet)
            kept_from_sheet = [x for x in kept_from_sheet if x[4] and x[4] > sheet_max_date]
            stats["overlap_rows_dropped"] += before - len(kept_from_sheet)
        dates = [x[4] for x in kept_from_sheet if x[4]]
        if dates:
            m = max(dates)
            sheet_max_date = m if sheet_max_date is None else max(sheet_max_date, m)
        rows.extend(kept_from_sheet)
    wb.close()
    stats["rows_after_overlap"] = len(rows)

    baskets = defaultdict(dict)  # order_id -> sku -> [qty, price, date]
    names = defaultdict(Counter)
    for inv, code, desc, qty, dt, price in rows:
        inv = str(inv).strip()
        if inv.upper().startswith("C"):
            stats["drop_cancellation_invoice"] += 1
            continue
        if code is None or not PRODUCT_CODE.match(str(code).strip()):
            stats["drop_non_product_code"] += 1
            continue
        if qty is None or qty <= 0 or price is None or price <= 0:
            stats["drop_nonpositive_qty_or_price"] += 1
            continue
        if not desc or not str(desc).strip():
            stats["drop_missing_description"] += 1
            continue
        sku = str(code).strip().upper()
        names[sku][str(desc).strip()] += 1
        cur = baskets[inv].get(sku)
        if cur is None:
            baskets[inv][sku] = [int(qty), float(price), dt]
        else:  # même (facture, SKU) sur plusieurs lignes : on somme les quantités
            cur[0] += int(qty)
            stats["merged_duplicate_lines"] += 1

    if out_sqlite:
        con = sqlite3.connect(out_sqlite)
        con.executescript(
            """
            DROP TABLE IF EXISTS orders;
            CREATE TABLE orders (
                order_id VARCHAR NOT NULL,
                sku VARCHAR NOT NULL,
                product_name VARCHAR NOT NULL,
                qty INTEGER NOT NULL,
                price NUMERIC NOT NULL,
                vendor_id INTEGER NOT NULL,
                processed_at DATETIME,
                PRIMARY KEY (order_id, sku)
            );
            """
        )
        batch = []
        for inv, items in baskets.items():
            for sku, (qty, price, dt) in items.items():
                name = names[sku].most_common(1)[0][0]
                ts = dt.strftime("%Y-%m-%d %H:%M:%S") if isinstance(dt, datetime) else None
                batch.append((f"ORII-{inv}", sku, name, qty, price, VENDOR_ID, ts))
        con.executemany("INSERT INTO orders VALUES (?,?,?,?,?,?,?)", batch)
        con.commit()
        n_lines = con.execute("SELECT COUNT(*) FROM orders").fetchone()[0]
        n_orders = con.execute("SELECT COUNT(DISTINCT order_id) FROM orders").fetchone()[0]
        n_skus = con.execute("SELECT COUNT(DISTINCT sku) FROM orders").fetchone()[0]
        n_multi = con.execute(
            "SELECT COUNT(*) FROM (SELECT order_id FROM orders GROUP BY order_id HAVING COUNT(*) >= 2)"
        ).fetchone()[0]
        dmin, dmax = con.execute("SELECT MIN(processed_at), MAX(processed_at) FROM orders").fetchone()
        con.close()
        summary = {
            "source": "UCI Online Retail II (CC BY 4.0)",
            "converted_at": datetime.now().isoformat(timespec="seconds"),
            "orders_lines": n_lines,
            "orders_baskets": n_orders,
            "baskets_with_2plus_skus": n_multi,
            "distinct_skus": n_skus,
            "date_min": dmin,
            "date_max": dmax,
            "filters": dict(stats),
        }
        with open(out_report, "w", encoding="utf-8") as f:
            json.dump(summary, f, indent=2, ensure_ascii=False)
        print(json.dumps(summary, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    main(*sys.argv[1:])
