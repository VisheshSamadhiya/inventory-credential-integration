<?php
declare(strict_types=1);

/** Return products matching a category and optional sub-category selection. */
function filterInventoryProductsForSelection(array $products, int $categoryId, int $subcategoryId = 0): array
{
    if ($categoryId < 1) return [];

    return array_values(array_filter($products, static function (array $product) use ($categoryId, $subcategoryId): bool {
        if ((int)($product['category_id'] ?? 0) !== $categoryId) return false;
        return $subcategoryId < 1 || (int)($product['sub_category_id'] ?? 0) === $subcategoryId;
    }));
}

/** Confirm that a chosen product belongs to the displayed category selection. */
function inventoryProductSelectionIsValid(array $products, int $productId, int $categoryId, int $subcategoryId = 0): bool
{
    foreach (filterInventoryProductsForSelection($products, $categoryId, $subcategoryId) as $product) {
        if ((int)($product['id'] ?? 0) === $productId) return true;
    }
    return false;
}
