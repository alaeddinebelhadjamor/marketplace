<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * Soumission d'un produit (cas d'utilisation « Ajouter un produit », tab. 2.5).
 * Le corps suit le format Magento { product: {...} } comme dans la v1.
 */
class StoreProductRequest extends ApiFormRequest
{
    /** Attributs personnalisés que le vendeur peut renseigner. */
    public const ALLOWED_CUSTOM_ATTRIBUTES = ['short_description', 'description', 'meta_title', 'meta_description', 'meta_keyword'];

    protected function prepareForValidation(): void
    {
        $product = $this->input('product');
        if (is_array($product) && isset($product['name']) && is_string($product['name'])) {
            $product['name'] = trim($product['name']);
            $this->merge(['product' => $product]);
        }
    }

    public function rules(): array
    {
        return [
            'product' => ['required', 'array'],
            'product.sku' => ['required', 'string', 'regex:'.config('marketplace.products.sku_pattern')],
            'product.name' => ['required', 'string', 'min:2', 'max:255'],
            'product.price' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'product.weight' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'product.attribute_set_id' => ['nullable', 'integer'],
            'product.type_id' => ['nullable', 'in:simple'],
            'product.visibility' => ['nullable', 'integer', 'between:1,4'],
            'product.extension_attributes.stock_item.qty' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'product.custom_attributes' => ['nullable', 'array', 'max:20'],
            'product.custom_attributes.*.attribute_code' => ['required', 'string'],
            'product.custom_attributes.*.value' => ['nullable', 'string', 'max:65000'],
            'product.media_gallery_entries' => ['nullable', 'array', 'max:10'],
            'product.media_gallery_entries.*.content.base64_encoded_data' => ['required', 'string'],
            'product.media_gallery_entries.*.content.type' => ['required', 'in:image/jpeg,image/png,image/gif,image/webp'],
            'product.media_gallery_entries.*.content.name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'product.sku.regex' => "SKU invalide : 3 à 64 caractères, lettres, chiffres, '.', '_' ou '-' uniquement.",
            'product.name.min' => 'Le nom du produit doit contenir entre 2 et 255 caractères.',
            'product.name.max' => 'Le nom du produit doit contenir entre 2 et 255 caractères.',
            'product.price.numeric' => 'Le prix doit être un nombre strictement positif.',
            'product.price.gt' => 'Le prix doit être un nombre strictement positif.',
            'product.media_gallery_entries.*.content.type.in' => 'Format d\'image non accepté (JPEG, PNG, GIF ou WebP).',
        ];
    }

    protected function summaryMessage(Validator $validator): string
    {
        if ($this->missingFields($validator, ['product', 'product.sku', 'product.name', 'product.price']) !== []) {
            return 'SKU, name et price sont obligatoires.';
        }

        return parent::summaryMessage($validator);
    }

    /** Produit nettoyé : seuls les champs connus sont transmis à Magento. */
    public function product(): array
    {
        $in = $this->validated('product');

        $product = [
            'sku' => $in['sku'],
            'name' => $in['name'],
            'price' => (float) $in['price'],
            'attribute_set_id' => (int) ($in['attribute_set_id'] ?? 4),
            'type_id' => 'simple',
            'visibility' => (int) ($in['visibility'] ?? 4),
            'weight' => isset($in['weight']) ? (float) $in['weight'] : null,
            'extension_attributes' => [
                'stock_item' => [
                    'qty' => (int) ($in['extension_attributes']['stock_item']['qty'] ?? 10000),
                    'is_in_stock' => true,
                    'manage_stock' => true,
                ],
            ],
            'custom_attributes' => array_values(array_filter(
                $in['custom_attributes'] ?? [],
                fn (array $a) => in_array($a['attribute_code'], self::ALLOWED_CUSTOM_ATTRIBUTES, true)
            )),
        ];

        $media = [];
        foreach (array_values($in['media_gallery_entries'] ?? []) as $i => $entry) {
            $media[] = [
                'media_type' => 'image',
                'label' => mb_substr((string) ($entry['label'] ?? $entry['content']['name']), 0, 255),
                'position' => $i + 1,
                'disabled' => false,
                'types' => $i === 0 ? ['image', 'small_image', 'thumbnail'] : [],
                'content' => [
                    'base64_encoded_data' => $entry['content']['base64_encoded_data'],
                    'type' => $entry['content']['type'],
                    'name' => basename((string) $entry['content']['name']),
                ],
            ];
        }
        if ($media !== []) {
            $product['media_gallery_entries'] = $media;
        }

        return array_filter($product, fn ($v) => $v !== null);
    }
}
