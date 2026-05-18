<?php
/**
 * Settings – User Management
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin');

// ── Handle POST: toggle active status ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle-active') {
    csrf_check();

    $target_id = (int)($_POST['user_id'] ?? 0);
    $current   = current_user();

    if ($target_id <= 0) {
        flash_error('Invalid user ID.');
        redirect('users.php');
    }

    // Prevent toggling own account
    if ($target_id === (int)$current['id']) {
        flash_error('You cannot change the active status of your own account.');
        redirect('users.php');
    }

    $target = db_fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$target_id]);
    if (!$target) {
        flash_error('User not found.');
        redirect('users.php');
    }

    $new_active = $target['active'] ? 0 : 1;
    $label      = $new_active ? 'activated' : 'deactivated';

    db_update('users', ['active' => $new_active, 'updated_at' => date('Y-m-d H:i:s')], 'id', $target_id);
    log_activity('update', "User {$target['name']} $label", 'user', $target_id);
    flash_success("User {$target['name']} has been $label.");
    redirect('users.php');
}

// ── Handle POST: disable 2FA ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable-2fa') {
    csrf_check();

    $target_id = (int)($_POST['user_id'] ?? 0);

    if ($target_id <= 0) {
        flash_error('Invalid user ID.');
        redirect('users.php');
    }

    db_execute(
        'UPDATE two_factor_secrets SET enabled = 0, updated_at = NOW() WHERE user_id = ?',
        [$target_id]
    );
    $target = db_fetch('SELECT name FROM users WHERE id = ? LIMIT 1', [$target_id]);
    log_activity('update', '2FA disabled for user ' . ($target['name'] ?? 'ID ' . $target_id), 'user', $target_id);
    flash_success('2FA has been disabled for ' . ($target['name'] ?? 'the user') . '.');
    redirect('users.php');
}

// ── Fetch all users with 2FA status ───────────────────────────────────────────
try {
    $users = db_fetchall(
        'SELECT u.*, tfs.enabled AS tfa_enabled
         FROM users u
         LEFT JOIN two_factor_secrets tfs ON tfs.user_id = u.id
         ORDER BY u.name ASC'
    );
} catch (\Throwable $e) {
    // two_factor_secrets table may not exist on older installs — fall back
    $users = db_fetchall('SELECT u.*, NULL AS tfa_enabled FROM users u ORDER BY u.name ASC');
}

$role_labels = user_roles();

layout_start('Users', 'users');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">User Management</h5>
    <a href="create_user.php" class="btn-tp-primary btn-tp-sm">
        <i class="fa-solid fa-plus"></i> Add User
    </a>
</div>

<div class="tp-card">
    <?php if (empty($users)): ?>
        <p class="text-muted p-3 mb-0">No users found.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table tp-table mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                     <th>Active</th>
                    <th>2FA</th>
                    <th>Last Login</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u):
                $is_self   = ((int)$u['id'] === (int)(current_user()['id'] ?? 0));
                $role_slug = str_replace('_', '-', strtolower($u['role']));
            ?>
                <tr>
                    <td>
                        <?= e($u['name']) ?>
                        <?php if ($is_self): ?>
                            <span class="text-muted" style="font-size:.72rem;">(you)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($u['email']) ?></td>
                    <td>
                        <span class="tp-badge badge-<?= e($role_slug) ?>"
                              style="background:#e5e7eb;color:#374151;">
                            <?= e($role_labels[$u['role']] ?? ucfirst($u['role'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($u['active']): ?>
                            <span class="tp-badge badge-available">Active</span>
                        <?php else: ?>
                            <span class="tp-badge badge-canceled">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($u['tfa_enabled'])): ?>
                            <span class="badge bg-success">
                                <i class="fa-solid fa-shield-halved"></i> On
                            </span>
                            <form method="POST" action="users.php" class="d-inline ms-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="disable-2fa">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn btn-link btn-sm p-0" style="color:#ef4444;font-size:.75rem;"
                                        onclick="return confirm('Disable 2FA for <?= e(addslashes($u['name'])) ?>?')">
                                    Disable
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="badge bg-secondary">Off</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($u['last_login'])): ?>
                            <?= e(fmt_datetime($u['last_login'])) ?>
                        <?php else: ?>
                            <span class="text-muted">Never</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="edit_user.php?id=<?= (int)$u['id'] ?>"
                           class="btn-tp-ghost btn-tp-sm">
                            <i class="fa-solid fa-pencil"></i> Edit
                        </a>
                        <?php if (!$is_self): ?>
                        <form method="POST" action="users.php" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle-active">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit"
                                    class="btn-tp-ghost btn-tp-sm <?= $u['active'] ? 'text-danger' : 'text-success' ?>"
                                    onclick="return confirm('<?= $u['active'] ? 'Deactivate' : 'Activate' ?> <?= e(addslashes($u['name'])) ?>?')">
                                <?php if ($u['active']): ?>
                                    <i class="fa-solid fa-ban"></i> Deactivate
                                <?php else: ?>
                                    <i class="fa-solid fa-check"></i> Activate
                                <?php endif; ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
layout_end();
