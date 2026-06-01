<?php
/**
 * Dumpsters – Size Catalog (Categories)
 * Manage the list of available dumpster sizes shown when adding/editing units.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin', 'office');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create') {
            $name        = trim((string)($_POST['name']        ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $sort_order  = (int)($_POST['sort_order'] ?? 0);

            if ($name === '') {
                throw new RuntimeException('Size name is required.');
            }
            $exists = db_fetch('SELECT id FROM dumpster_categories WHERE name = ? LIMIT 1', [$name]);
            if ($exists) {
                throw new RuntimeException('A category with that name already exists.');
            }
            db_insert('dumpster_categories', [
                'name'        => $name,
                'description' => $description !== '' ? $description : null,
                'sort_order'  => $sort_order,
                'active'      => 1,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            log_activity('create', "Created dumpster category: $name", 'dumpster_category', 0);
            flash_success("Category "$name" added.");

        } elseif ($action === 'edit') {
            $id          = (int)($_POST['id']          ?? 0);
            $name        = trim((string)($_POST['name']        ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $sort_order  = (int)($_POST['sort_order'] ?? 0);
            $active      = isset($_POST['active']) ? 1 : 0;

            if ($id <= 0 || $name === '') {
                throw new RuntimeException('Invalid request.');
            }
            $cat = db_fetch('SELECT * FROM dumpster_categories WHERE id = ? LIMIT 1', [$id]);
            if (!$cat) {
                throw new RuntimeException('Category not found.');
            }
            $duplicate = db_fetch(
                'SELECT id FROM dumpster_categories WHERE name = ? AND id <> ? LIMIT 1',
                [$name, $id]
            );
            if ($duplicate) {
                throw new RuntimeException('Another category with that name already exists.');
            }

            // If the name changed, rename the size on all dumpsters that used the old name
            $oldName = $cat['name'];
            if ($name !== $oldName) {
                db_execute(
                    "UPDATE dumpsters SET size = ?, updated_at = NOW() WHERE size = ?",
                    [$name, $oldName]
                );
            }

            db_update('dumpster_categories', [
                'name'        => $name,
                'description' => $description !== '' ? $description : null,
                'sort_order'  => $sort_order,
                'active'      => $active,
                'updated_at'  => date('Y-m-d H:i:s'),
            ], 'id', $id);
            log_activity('update', "Updated dumpster category: $name", 'dumpster_category', $id);
            flash_success("Category updated.");

        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Invalid request.');
            }
            $cat = db_fetch('SELECT * FROM dumpster_categories WHERE id = ? LIMIT 1', [$id]);
            if (!$cat) {
                throw new RuntimeException('Category not found.');
            }
            $inUse = (int)(db_value(
                'SELECT COUNT(*) FROM dumpsters WHERE size = ?',
                [$cat['name']]
            ) ?? 0);
            if ($inUse > 0) {
                throw new RuntimeException(
                    "Cannot delete "{$cat['name']}" — $inUse dumpster(s) currently use this size. "
                    . "Reassign those dumpsters first."
                );
            }
            db_execute('DELETE FROM dumpster_categories WHERE id = ?', [$id]);
            log_activity('delete', "Deleted dumpster category: {$cat['name']}", 'dumpster_category', $id);
            flash_success("Category "{$cat['name']}" deleted.");
        }
    } catch (Throwable $e) {
        flash_error($e->getMessage());
    }

    redirect('categories.php');
}

$categories = db_fetchall(
    "SELECT dc.*,
            (SELECT COUNT(*) FROM dumpsters d WHERE d.size = dc.name) AS dumpster_count
     FROM dumpster_categories dc
     ORDER BY dc.sort_order ASC, dc.name ASC"
);

layout_start('Size Catalog', 'inventory');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
        <a href="index.php" class="text-muted" style="text-decoration:none;font-size:.85rem;">
            <i class="fa-solid fa-arrow-left me-1"></i>Inventory
        </a>
        <span class="mx-2 text-muted">/</span>Size Catalog
    </h5>
    <button type="button" class="btn-tp-primary btn-tp-sm" data-bs-toggle="modal" data-bs-target="#addCatModal">
        <i class="fa-solid fa-plus me-1"></i>Add Size
    </button>
</div>

<div class="tp-card">
    <div class="tp-card-header">
        <span><i class="fa-solid fa-layer-group me-2 text-muted"></i>Dumpster Sizes</span>
        <span class="text-muted ms-2" style="font-size:.8rem;"><?= count($categories) ?> sizes defined</span>
    </div>
    <?php if (!$categories): ?>
    <p class="text-muted p-3 mb-0">No sizes defined yet. Add one above.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table tp-table mb-0">
            <thead>
                <tr>
                    <th>Size Name</th>
                    <th>Description</th>
                    <th>Units</th>
                    <th>Order</th>
                    <th>Active</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td class="fw-semibold"><?= e($cat['name']) ?></td>
                    <td style="max-width:340px;white-space:normal;font-size:.85rem;color:var(--gy);">
                        <?= $cat['description'] ? e($cat['description']) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td>
                        <?php if ((int)$cat['dumpster_count'] > 0): ?>
                        <a href="index.php" style="font-size:.83rem;"><?= (int)$cat['dumpster_count'] ?> unit<?= (int)$cat['dumpster_count'] !== 1 ? 's' : '' ?></a>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:.83rem;">0</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.83rem;"><?= (int)$cat['sort_order'] ?></td>
                    <td>
                        <?php if ($cat['active']): ?>
                        <span class="tp-badge badge-active">Active</span>
                        <?php else: ?>
                        <span class="tp-badge badge-void">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn-tp-ghost btn-tp-xs me-1"
                                onclick="openEditCat(<?= (int)$cat['id'] ?>,
                                    <?= e(json_encode($cat['name'])) ?>,
                                    <?= e(json_encode($cat['description'] ?? '')) ?>,
                                    <?= (int)$cat['sort_order'] ?>,
                                    <?= (int)$cat['active'] ?>)">
                            <i class="fa-solid fa-pen-to-square me-1"></i>Edit
                        </button>
                        <?php if ((int)$cat['dumpster_count'] === 0): ?>
                        <form method="POST" action="categories.php" class="d-inline"
                              onsubmit="return confirm('Delete size category &quot;<?= e(addslashes($cat['name'])) ?>&quot;? This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                            <button type="submit" class="btn-tp-danger btn-tp-xs">
                                <i class="fa-solid fa-trash me-1"></i>Delete
                            </button>
                        </form>
                        <?php else: ?>
                        <button type="button" class="btn-tp-danger btn-tp-xs" disabled
                                title="Reassign <?= (int)$cat['dumpster_count'] ?> dumpster(s) before deleting">
                            <i class="fa-solid fa-trash me-1"></i>Delete
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="alert alert-light border mt-3 py-2 px-3" style="font-size:.85rem;">
    <strong>Note:</strong> Size names here populate the size selector when adding or editing dumpsters.
    Renaming a size automatically updates all dumpsters that use it. A size can only be deleted when no dumpsters reference it.
</div>

<!-- Add Size Modal -->
<div class="modal fade" id="addCatModal" tabindex="-1" aria-labelledby="addCatModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="categories.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title" id="addCatModalLabel"><i class="fa-solid fa-plus me-2"></i>Add Size</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Size Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" placeholder="e.g. 20 Yard" required autofocus>
            <div class="form-text">This is the label shown in dumpster dropdowns (e.g. "20 Yard").</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Description <small class="text-muted">optional</small></label>
            <textarea name="description" class="form-control" rows="3"
                      placeholder="e.g. Great for medium home cleanouts, flooring projects, and small renovations…"></textarea>
          </div>
          <div class="mb-0">
            <label class="form-label">Sort Order</label>
            <input type="number" name="sort_order" class="form-control" value="0" min="0" step="1" style="max-width:100px;">
            <div class="form-text">Lower numbers appear first. Use multiples of 10 to leave room.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-tp-ghost btn-tp-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-tp-primary btn-tp-sm"><i class="fa-solid fa-plus me-1"></i>Add Size</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Size Modal -->
<div class="modal fade" id="editCatModal" tabindex="-1" aria-labelledby="editCatModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="categories.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="editCatId">
        <div class="modal-header">
          <h5 class="modal-title" id="editCatModalLabel"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Size</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Size Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="editCatName" class="form-control" required>
            <div class="form-text">Renaming will update all dumpsters that currently use this size.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Description <small class="text-muted">optional</small></label>
            <textarea name="description" id="editCatDescription" class="form-control" rows="3"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Sort Order</label>
            <input type="number" name="sort_order" id="editCatSortOrder" class="form-control" min="0" step="1" style="max-width:100px;">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="active" id="editCatActive" value="1">
            <label class="form-check-label" for="editCatActive">Active (show in size selector)</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-tp-ghost btn-tp-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-tp-primary btn-tp-sm"><i class="fa-solid fa-floppy-disk me-1"></i>Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditCat(id, name, description, sortOrder, active) {
    document.getElementById('editCatId').value          = id;
    document.getElementById('editCatName').value        = name;
    document.getElementById('editCatDescription').value = description || '';
    document.getElementById('editCatSortOrder').value   = sortOrder;
    document.getElementById('editCatActive').checked    = active == 1;
    new bootstrap.Modal(document.getElementById('editCatModal')).show();
}
</script>

<?php layout_end(); ?>
