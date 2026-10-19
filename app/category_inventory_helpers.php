<?php
declare(strict_types=1);

function categoryInventoryDetailsUrl(int $categoryId): string
{
    if ($categoryId < 1) throw new InvalidArgumentException('Category ID must be positive.');
    return 'category-inventory.php?category_id=' . $categoryId;
}

/** Filter product detail rows while keeping category totals independent of page filters. */
function filterCategoryInventoryRows(array $products, int $subcategoryId = 0, int $productId = 0): array
{
    return array_values(array_filter($products, static function (array $product) use ($subcategoryId, $productId): bool {
        if ($subcategoryId > 0 && (int)($product['sub_category_id'] ?? 0) !== $subcategoryId) return false;
        return $productId < 1 || (int)($product['id'] ?? 0) === $productId;
    }));
}

function categoryInventoryDetailsTotals(array $products): array
{
    $assigned = 0;
    $available = 0;
    foreach ($products as $product) {
        $assigned += (int)($product['assigned_quantity'] ?? 0);
        $available += (int)($product['available_quantity'] ?? 0);
    }
    return ['active' => $assigned, 'inactive' => $available, 'total' => $assigned + $available];
}

function inventoryDrilldownStock(int $assigned, int $available): array
{
    $assigned = max(0, $assigned);
    $available = max(0, $available);
    return ['assigned' => $assigned, 'available' => $available, 'total' => $assigned + $available];
}

function inventoryDrilldownUrl(string $view, int $categoryId, int $productId = 0, string $brand = ''): string
{
    if (!in_array($view, ['product', 'brand', 'assigned', 'available', 'total'], true) || $categoryId < 1) {
        throw new InvalidArgumentException('Invalid inventory details route.');
    }
    $params = ['view' => $view, 'category_id' => $categoryId];
    if ($productId > 0) $params['product_id'] = $productId;
    if ($view === 'brand') $params['brand'] = $brand;
    return 'inventory-details.php?' . http_build_query($params);
}

/** Return how much bulk stock remains after individually listed, available assets. */
function inventoryDrilldownBulkAvailable(int $available, int $listedAssets): int
{
    return max(0, $available - inventoryDrilldownIncludedAssetCount($available, $listedAssets));
}

function inventoryDrilldownIncludedAssetCount(int $available, int $listedAssets): int
{
    return min(max(0, $available), max(0, $listedAssets));
}

function inventoryDrilldownRequiredPermissions(string $view): array
{
    return match ($view) {
        'product', 'brand' => ['inventory:view', 'products:view'],
        'assigned' => ['inventory:view', 'products:view', 'assignments:view', 'employees:view'],
        'available' => ['inventory:view', 'products:view', 'hardware:view'],
        'total' => ['inventory:view', 'products:view', 'assignments:view', 'employees:view', 'hardware:view'],
        default => [],
    };
}
