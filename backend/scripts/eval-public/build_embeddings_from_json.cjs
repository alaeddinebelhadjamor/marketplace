/**
 * Variante hors production de scripts/recommender/build_content_embeddings.js :
 * lit une liste [{sku, product_name}] (JSON) au lieu de MySQL et écrit le
 * fichier dans un dossier de sortie explicite, sans toucher à
 * storage/app/private/recommender. Même modèle, même pooling, même normalisation.
 *
 * Usage : NODE_PATH=../recommender/node_modules node build_embeddings_from_json.cjs <skus.json> <sortie.json>
 */
const fs = require('fs');
const { pipeline } = require('@xenova/transformers');

const MODEL_NAME = 'Xenova/paraphrase-multilingual-MiniLM-L12-v2';

(async () => {
  const [, , inPath, outPath] = process.argv;
  if (!inPath || !outPath) {
    console.error('Usage: build_embeddings_from_json.cjs <skus.json> <sortie.json>');
    process.exit(1);
  }
  const rows = JSON.parse(fs.readFileSync(inPath, 'utf8'));
  console.log(`Chargement du modèle ${MODEL_NAME}...`);
  const extractor = await pipeline('feature-extraction', MODEL_NAME);

  const skus = [];
  const embeddings = [];
  for (let i = 0; i < rows.length; i++) {
    const { sku, product_name } = rows[i];
    const output = await extractor(product_name || sku, { pooling: 'mean', normalize: true });
    skus.push(sku);
    embeddings.push(Array.from(output.data));
    if ((i + 1) % 500 === 0 || i === rows.length - 1) {
      console.log(`  ${i + 1}/${rows.length} produits encodés...`);
    }
  }
  fs.writeFileSync(
    outPath,
    JSON.stringify({ built_at: new Date().toISOString(), model: MODEL_NAME, dim: embeddings[0].length, n_items: skus.length, skus, embeddings })
  );
  console.log(`Embeddings sauvegardés : ${outPath}`);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
