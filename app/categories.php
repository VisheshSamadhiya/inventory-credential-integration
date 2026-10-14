<?php
require_once __DIR__ . '/auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') requirePermission('categories', 'view');
$pageTitle = 'Categories';
$pdo = db();
$canAddCategory = canPerform('categories', 'add');
$canEditCategory = canPerform('categories', 'edit');
$canChangeCategoryStatus = canPerform('categories', 'status');
$canChangeCategories = $canAddCategory || $canEditCategory || $canChangeCategoryStatus;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $permission = match ($action) {
        'category_create', 'subcategory_create' => 'add',
        'category_update', 'subcategory_update' => 'edit',
        'toggle_category', 'toggle_subcategory' => 'status',
        default => 'edit',
    };
    requirePermission('categories', $permission);
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    try {
        $pdo->beginTransaction();
        if ($action === 'category_create' || $action === 'category_update') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new DomainException('Please enter a category name.');
            $description = trim((string)($_POST['description'] ?? '')) ?: null;
            if ($action === 'category_create') {
                $stmt = $pdo->prepare('INSERT INTO categories (name, description) VALUES (?, ?)');
                $stmt->execute([$name, $description]);
                $id = (int)$pdo->lastInsertId();
                audit('create', 'category', $id, $name);
                setFlash('success', 'Category created successfully.');
            } else {
                $stmt = $pdo->prepare('UPDATE categories SET name = ?, description = ? WHERE id = ?');
                $stmt->execute([$name, $description, $id]);
                audit('update', 'category', $id, $name);
                setFlash('success', 'Changes saved successfully.');
            }
        } elseif ($action === 'subcategory_create' || $action === 'subcategory_update') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($categoryId < 1) throw new DomainException('Please select a category.');
            if ($name === '') throw new DomainException('Please enter a sub-category name.');
            $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND active = 1');
            $stmt->execute([$categoryId]);
            if (!$stmt->fetchColumn()) throw new DomainException('Please select an active category.');
            $description = trim((string)($_POST['description'] ?? '')) ?: null;
            if ($action === 'subcategory_create') {
                $stmt = $pdo->prepare('INSERT INTO sub_categories (category_id, name, description) VALUES (?, ?, ?)');
                $stmt->execute([$categoryId, $name, $description]);
                $id = (int)$pdo->lastInsertId();
                audit('create', 'sub_category', $id, $name);
                setFlash('success', 'Sub-category created successfully.');
            } else {
                $current = $pdo->prepare('SELECT category_id FROM sub_categories WHERE id = ?');
                $current->execute([$id]);
                $currentCategoryId = $current->fetchColumn();
                if ($currentCategoryId === false) throw new DomainException('Sub-category was not found.');
                if ((int)$currentCategoryId !== $categoryId) {
                    $used = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sub_category_id = ?');
                    $used->execute([$id]);
                    if ((int)$used->fetchColumn() > 0) {
                        throw new DomainException('This sub-category is in use by products. Change those products first, then move it.');
                    }
                }
                $stmt = $pdo->prepare('UPDATE sub_categories SET category_id = ?, name = ?, description = ? WHERE id = ?');
                $stmt->execute([$categoryId, $name, $description, $id]);
                audit('update', 'sub_category', $id, $name);
                setFlash('success', 'Changes saved successfully.');
            }
        } elseif ($action === 'toggle_category' || $action === 'toggle_subcategory') {
            $table = $action === 'toggle_category' ? 'categories' : 'sub_categories';
            $entity = $action === 'toggle_category' ? 'category' : 'sub_category';
            $stmt = $pdo->prepare("UPDATE {$table} SET active = IF(active = 1, 0, 1) WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() !== 1) throw new DomainException('The selected item was not found.');
            audit('status_change', $entity, $id);
            setFlash('success', 'Changes saved successfully.');
        } else {
            throw new DomainException('Choose a valid category action.');
        }
        $pdo->commit();
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        setFlash('error', $exception->getMessage());
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Category update failed: ' . $exception->getMessage());
        setFlash('error', (int)($exception->errorInfo[1] ?? 0) === 1062
            ? 'A category or sub-category with that name already exists.'
            : 'Unable to save changes. Please check the entered information.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Category operation failed: ' . $exception->getMessage());
        setFlash('error', 'Unable to save changes. Please try again.');
    }
    header('Location: categories.php');
    exit;
}

$categories = $pdo->query('SELECT id, name, description, active FROM categories ORDER BY active DESC, name')->fetchAll();
$subcategories = $pdo->query(
    'SELECT sc.id, sc.category_id, sc.name, sc.description, sc.active, c.name AS category_name
     FROM sub_categories sc JOIN categories c ON c.id = sc.category_id
     ORDER BY c.name, sc.name'
)->fetchAll();
$activeCategories = array_values(array_filter($categories, static fn(array $category): bool => (bool)$category['active']));
require __DIR__ . '/header.php';
?>
<section class="card catalog-section">
    <h2>Categories</h2>
    <?php if ($canAddCategory): ?><form method="post" class="catalog-entry">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="category_create">
        <div><label>Category name</label><input name="name" maxlength="150" required></div>
        <div><label>Description (optional)</label><input name="description"></div>
        <button class="btn btn-glow" type="submit">Create category</button>
    </form><?php endif; ?>
    <div class="table-scroll"><table><thead><tr><th>Category</th><th>Description</th><th>Status</th><?php if($canChangeCategories): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
    <?php foreach($categories as $category): ?><tr>
        <td><?php if($canEditCategory): ?><form id="category-<?=$category['id']?>" method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="category_update"><input type="hidden" name="id" value="<?=$category['id']?>"><input name="name" required value="<?=htmlspecialchars($category['name'])?>"></form><?php else: ?><?=htmlspecialchars($category['name'])?><?php endif; ?></td>
        <td><?php if($canEditCategory): ?><input form="category-<?=$category['id']?>" name="description" value="<?=htmlspecialchars($category['description']??'')?>"><?php else: ?><?=htmlspecialchars($category['description']??'')?><?php endif; ?></td>
        <td><span class="badge"><?=$category['active']?'Active':'Inactive'?></span></td>
        <?php if($canChangeCategories): ?><td><div class="inline-actions"><?php if($canEditCategory): ?><button class="btn" form="category-<?=$category['id']?>" type="submit">Save</button><?php endif; ?><?php if($canChangeCategoryStatus): ?><form method="post" onsubmit="return confirm('⚠️ <?=$category['active']?'Deactivate':'Reactivate'?> this category?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="toggle_category"><input type="hidden" name="id" value="<?=$category['id']?>"><button class="btn <?=$category['active']?'btn-danger':'btn-success'?>" type="submit"><?=$category['active']?'Deactivate':'Reactivate'?></button></form><?php endif; ?></div></td><?php endif; ?>
    </tr><?php endforeach; ?><?php if(!$categories): ?><tr><td colspan="<?=$canChangeCategories?4:3?>" class="muted">No categories yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<section class="card catalog-section">
    <h2>Sub-categories</h2>
    <?php if ($canAddCategory): ?><form method="post" class="catalog-entry">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="subcategory_create">
        <div><label>Category</label><select name="category_id" required><option value="">Select category</option><?php foreach($activeCategories as $category): ?><option value="<?=$category['id']?>"><?=htmlspecialchars($category['name'])?></option><?php endforeach; ?></select></div>
        <div><label>Sub-category name</label><input name="name" maxlength="150" required></div>
        <div><label>Description (optional)</label><input name="description"></div>
        <button class="btn btn-glow" type="submit">Create sub-category</button>
    </form><?php endif; ?>
    <div class="table-scroll"><table><thead><tr><th>Sub-category</th><th>Category</th><th>Description</th><th>Status</th><?php if($canChangeCategories): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
    <?php foreach($subcategories as $subcategory): ?><tr>
        <td><?php if($canEditCategory): ?><form id="subcategory-<?=$subcategory['id']?>" method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="subcategory_update"><input type="hidden" name="id" value="<?=$subcategory['id']?>"><input name="name" required value="<?=htmlspecialchars($subcategory['name'])?>"></form><?php else: ?><?=htmlspecialchars($subcategory['name'])?><?php endif; ?></td>
        <td><?php if($canEditCategory): ?><select name="category_id" form="subcategory-<?=$subcategory['id']?>" required><?php foreach($activeCategories as $category): ?><option value="<?=$category['id']?>" <?= (int)$subcategory['category_id']===(int)$category['id']?'selected':'' ?>><?=htmlspecialchars($category['name'])?></option><?php endforeach; ?></select><?php else: ?><?=htmlspecialchars($subcategory['category_name'])?><?php endif; ?></td>
        <td><?php if($canEditCategory): ?><input form="subcategory-<?=$subcategory['id']?>" name="description" value="<?=htmlspecialchars($subcategory['description']??'')?>"><?php else: ?><?=htmlspecialchars($subcategory['description']??'')?><?php endif; ?></td>
        <td><span class="badge"><?=$subcategory['active']?'Active':'Inactive'?></span></td>
        <?php if($canChangeCategories): ?><td><div class="inline-actions"><?php if($canEditCategory): ?><button class="btn" form="subcategory-<?=$subcategory['id']?>" type="submit">Save</button><?php endif; ?><?php if($canChangeCategoryStatus): ?><form method="post" onsubmit="return confirm('⚠️ <?=$subcategory['active']?'Deactivate':'Reactivate'?> this sub-category?')"><input type="hidden" name="csrf" value="<?=htmlspecialchars(csrf())?>"><input type="hidden" name="action" value="toggle_subcategory"><input type="hidden" name="id" value="<?=$subcategory['id']?>"><button class="btn <?=$subcategory['active']?'btn-danger':'btn-success'?>" type="submit"><?=$subcategory['active']?'Deactivate':'Reactivate'?></button></form><?php endif; ?></div></td><?php endif; ?>
    </tr><?php endforeach; ?><?php if(!$subcategories): ?><tr><td colspan="<?=$canChangeCategories?5:4?>" class="muted">No sub-categories yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
