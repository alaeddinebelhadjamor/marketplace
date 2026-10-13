#!/usr/bin/env python3
"""
Précalcule la matrice de similarité cosinus de contenu (n x n, float32 little-endian)
à partir de content_embeddings.json. Les vecteurs étant normalisés (normalize: true
côté Node), le cosinus est le produit scalaire.

Sert uniquement à accélérer l'évaluation (ces similarités ne dépendent pas de
l'entraînement ALS) : même valeurs que le calcul cosinus PHP, à la précision float32 près.

Usage : content_similarity_matrix.py <content_embeddings.json> <sortie.bin> <sortie_skus.json>
"""
import json
import sys

import numpy as np


def main(emb_path, out_bin, out_skus):
    data = json.load(open(emb_path, encoding="utf-8"))
    mat = np.asarray(data["embeddings"], dtype=np.float64)
    norms = np.linalg.norm(mat, axis=1, keepdims=True)
    norms[norms == 0] = 1.0
    mat = mat / norms
    sim = (mat @ mat.T).astype("<f4")
    sim.tofile(out_bin)
    json.dump(data["skus"], open(out_skus, "w"))
    print(f"matrice {sim.shape[0]}x{sim.shape[1]} écrite ({sim.nbytes / 1e6:.0f} Mo), modèle={data['model']}, dim={data['dim']}")


if __name__ == "__main__":
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    main(*sys.argv[1:])
