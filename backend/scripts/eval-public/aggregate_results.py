#!/usr/bin/env python3
"""
Agrège les résultats seed_*.json : moyenne, écart-type et intervalle de confiance
à 95 % (loi de Student, n-1 degrés de liberté) par méthode et par métrique, plus la
différence appariée (même graine) entre chaque méthode et la popularité.

Usage : aggregate_results.py <dossier_resultats> [sortie.md]
"""
import glob
import json
import math
import statistics
import sys

# Quantile t de Student bilatéral 95 % pour df = 1..10
T95 = {1: 12.706, 2: 4.303, 3: 3.182, 4: 2.776, 5: 2.571, 6: 2.447, 7: 2.365, 8: 2.306, 9: 2.262, 10: 2.228}
METHODS = [("popularity", "Popularité"), ("cooccurrence", "Co-occurrence"), ("als", "ALS seul"), ("content", "Contenu seul"), ("hybrid", "Hybride")]
METRICS = [("hit10", "HR@10"), ("ndcg10", "NDCG@10"), ("mrr", "MRR"), ("coverage10", "Couverture@10")]


def ci(values):
    n = len(values)
    m = statistics.mean(values)
    if n < 2:
        return m, 0.0, 0.0
    sd = statistics.stdev(values)
    return m, sd, T95[n - 1] * sd / math.sqrt(n)


def main(folder, out):
    runs = [json.load(open(f, encoding="utf-8")) for f in sorted(glob.glob(f"{folder}/seed_*.json"))]
    n = len(runs)
    lines = [f"Résultats agrégés sur {n} graines : {[r['seed'] for r in runs]}", ""]
    lines.append("| Méthode | " + " | ".join(lbl for _, lbl in METRICS) + " |")
    lines.append("|---|" + "---|" * len(METRICS))
    for key, label in METHODS:
        cells = []
        for mk, _ in METRICS:
            m, sd, h = ci([r["test"][key][mk] for r in runs])
            cells.append(f"{m*100:.2f} ± {h*100:.2f} %")
        lines.append(f"| {label} | " + " | ".join(cells) + " |")
    lines += ["", "Différence appariée (méthode − popularité), moyenne ± IC95 % en points :", ""]
    lines.append("| Méthode | " + " | ".join(lbl for _, lbl in METRICS[:3]) + " |")
    lines.append("|---|" + "---|" * 3)
    for key, label in METHODS[1:]:
        cells = []
        for mk, _ in METRICS[:3]:
            d = [r["test"][key][mk] - r["test"]["popularity"][mk] for r in runs]
            m, sd, h = ci(d)
            sig = "" if abs(m) > h else " (n.s.)"
            cells.append(f"{m*100:+.2f} ± {h*100:.2f}{sig}")
        lines.append(f"| {label} | " + " | ".join(cells) + " |")
    lines += ["", "Hybride − ALS seul (apport du contenu), points :", ""]
    for mk, lbl in METRICS[:3]:
        d = [r["test"]["hybrid"][mk] - r["test"]["als"][mk] for r in runs]
        m, sd, h = ci(d)
        lines.append(f"- {lbl} : {m*100:+.2f} ± {h*100:.2f}" + ("" if abs(m) > h else " (n.s.)"))
    lines += ["", "K retenus sur validation (par graine) :", ""]
    for r in runs:
        lines.append(f"- graine {r['seed']} : K(ALS)={r['k_als']}, K(hybride)={r['k_hybrid']}")
    lines += ["", "Validation (NDCG@10 moyen sur graines) par K :", "", "| K | ALS | Hybride |", "|---|---|---|"]
    for k in sorted({int(k) for r in runs for k in r["validation_by_k"]}):
        a = [r["validation_by_k"][str(k)]["metrics"]["als"]["ndcg10"] for r in runs if str(k) in r["validation_by_k"]]
        h = [r["validation_by_k"][str(k)]["metrics"]["hybrid"]["ndcg10"] for r in runs if str(k) in r["validation_by_k"]]
        lines.append(f"| {k} | {statistics.mean(a)*100:.2f} % | {statistics.mean(h)*100:.2f} % |")
    lines += ["", f"Paires de test évaluables (moyenne) : {statistics.mean(r['test_pairs_evaluable'] for r in runs):.0f}"]
    text = "\n".join(lines)
    print(text)
    if out:
        open(out, "w", encoding="utf-8").write(text + "\n")


if __name__ == "__main__":
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else None)
