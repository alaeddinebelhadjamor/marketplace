/**
 * Calcule, pour chaque SKU connu (table `orders`, partagée avec la v1), un
 * vecteur sémantique du nom produit via un modèle de langage pré-entraîné
 * multilingue FR/AR/EN (inférence seule, pas d'entraînement — même modèle
 * que la v1 : Xenova/paraphrase-multilingual-MiniLM-L12-v2).
 *
 * PHP n'embarque pas de runtime d'inférence de modèle Transformer : ce
 * script Node autonome est orchestré par `php artisan marketplace:build-embeddings`
 * (voir backend/routes/console.php) — un choix d'outillage pour cette seule
 * étape hors-ligne, pas une dépendance métier du reste de l'application
 * (100 % PHP/Laravel ailleurs).
 *
 * Usage : npm run build   (depuis backend/scripts/recommender, après npm install)
 */
require('dotenv').config({ path: require('path').join(__dirname, '..', '..', '.env') });
const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');
const { pipeline } = require('@xenova/transformers');

const MODEL_NAME = 'Xenova/paraphrase-multilingual-MiniLM-L12-v2';

(async () => {
  const connection = await mysql.createConnection({
    host: process.env.DB_HOST || '127.0.0.1',
    port: Number(process.env.DB_PORT) || 3306,
    user: process.env.DB_USERNAME,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_DATABASE,
  });

  const [rows] = await connection.query(
    'SELECT sku, MIN(product_name) AS product_name FROM orders GROUP BY sku'
  );
  await connection.end();

  if (rows.length === 0) {
    console.error('Aucun produit en base (table orders vide).');
    process.exit(1);
  }

  console.log(`Chargement du modèle ${MODEL_NAME}...`);
  const extractor = await pipeline('feature-extraction', MODEL_NAME);

  const skus = [];
  const embeddings = [];

  for (let i = 0; i < rows.length; i++) {
    const { sku, product_name } = rows[i];
    const output = await extractor(product_name || sku, { pooling: 'mean', normalize: true });
    skus.push(sku);
    embeddings.push(Array.from(output.data));
    if ((i + 1) % 20 === 0 || i === rows.length - 1) {
      console.log(`  ${i + 1}/${rows.length} produits encodés...`);
    }
  }

  const output = {
    built_at: new Date().toISOString(),
    model: MODEL_NAME,
    dim: embeddings[0].length,
    n_items: skus.length,
    skus,
    embeddings,
  };

  const outDir = path.join(__dirname, '..', '..', 'storage', 'app', 'private', 'recommender');
  fs.mkdirSync(outDir, { recursive: true });
  const outPath = path.join(outDir, 'content_embeddings.json');
  fs.writeFileSync(outPath, JSON.stringify(output));
  console.log(`Embeddings sauvegardés : ${outPath}`);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
