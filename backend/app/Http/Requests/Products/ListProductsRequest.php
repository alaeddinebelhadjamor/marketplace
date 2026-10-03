<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\ApiFormRequest;

class ListProductsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:'.config('marketplace.products.max_page_size')],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function page(): int
    {
        return (int) ($this->input('page') ?: 1);
    }

    public function pageSize(): int
    {
        return (int) ($this->input('pageSize') ?: config('marketplace.products.page_size'));
    }

    public function search(): string
    {
        return trim((string) $this->input('search', ''));
    }
}
