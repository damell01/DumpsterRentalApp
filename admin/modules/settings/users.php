<?php
/**
 * Settings - User Management
 * Trash Panda Roll-Offs
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once TMPL_PATH . '/layout.php';
require_login();
require_role('admin');

$super_admin_ids = super_admin_user_ids();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle-super-admin') {
    csrf_check();

    $target_id = (int)($_POST['user_id'] ?? 0);
    if ($target_id <= 0) {
        flash_error('Invalid user ID.');
        redirect('users.php');
    }

    $target = db_try_fetch('SELECT id, name, role FROM users WHERE id = ? LIMIT 1', [$target_id]);
    if (!$target) {
        flash_error('User not found.');
        redirect('users.php');
    }

    if (($target['role'] ?? '') !== 'admin') {
        flash_error('Only admin accounts can be marked as super admins.');
        redirect('users.php');
    }

    $updated_ids = $super_admin_ids;
    if (in_array($target_id, $updated_ids, true)) {
        $updated_ids = array_values(array_filter($updated_ids, static fn($id) => (int)$id !== $target_id));
        $label = 'removed from';
    } else {
        $updated_ids[] = $target_id;
        $updated_ids = array_values(array_unique(array_map('intval', $updated_ids)));
        sort($updated_ids);
        $label = 'added to';
    }

    set_setting('super_admin_user_ids', json_encode($updated_ids, JSON_UNESCAPED_SLASHES));
    log_activity('update', 'User ' . ($target['name'] ?? ('ID ' . $target_id)) . ' ' . $label . ' super admin access', 'user', $target_id);
    flash_success(($target['name'] ?? 'User') . ' was ' . $label . ' super admin access.');
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle-active') {
    csrf_check();

    $target_id = (int)($_POST['user_id'] ?? 0);
    $current   = current_user();

    if ($target_id <= 0) {
        flash_error('Invalid user ID.');
        redirect('users.php');
    }

    if ($target_id === (int)$current['id']) {
        flash_error('You cannot change the active status of your own account.');
        redirect('users.php');
    }

    $target = db_try_fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$target_id]);
    if (!$target) {
        flash_error('User not found.');
        redirect('users.php');
    }

    $new_active = !empty($target['active']) ? 0 : 1;
    $label      = $new_active ? 'activated' : 'deactivated';

    db_update('users', ['active' => $new_active, 'updated_at' => date('Y-m-d H:i:s')], 'id', $target_id);
    log_activity('update', "User {$target['name']} $label", 'user', $target_id);
    flash_success("User {$target['name']} has been $label.");
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable-2fa') {
    csrf_check();

    $target_id = (int)($_POST['user_id'] ?? 0);
    if ($target_id <= 0) {
        flash_error('Invalid user ID.');
        redirect('users.php');
    }

    if (db_table_exists('two_factor_secrets') && db_column_exists('two_factor_secrets', 'enabled')) {
        db_execute(
            'UPDATE two_factor_secrets SET enabled = 0, updated_at = NOW() WHERE user_id = ?',
            [$target_id]
        );
    }

    $target = db_try_fetch('SELECT name FROM users WHERE id = ? LIMIT 1', [$target_id]);
    log_activity('update', '2FA disabled for user ' . ($target['name'] ?? 'ID ' . $target_id), 'user', $target_id);
    flash_success('2FA has been disabled for ' . ($target['name'] ?? 'the user') . '.');
    redirect('users.php');
}

$users = [];
$has_2fa_table = db_table_exists('two_factor_secrets') && db_column_exists('two_factor_secrets', 'user_id');
$has_2fa_enabled = $has_2fa_table && db_column_exists('two_factor_secrets', 'enabled');

if ($has_2fa_table && $has_2fa_enabled) {
    $users = db_try_fetchall(
        'SELECT u.*, tfs.enabled AS tfa_enabled
         FROM users u
         LEFT JOIN two_factor_secrets tfs ON tfs.user_id = u.id
         ORDER BY u.name ASC'
    );
}

if (empty($users)) {
    $users = db_try_fetchall('SELECT u.*, NULL AS tfa_enabled FROM users u ORDER BY u.name ASC');
}

$role_labels = user_roles();
$super_admin_ids = super_admin_user_ids();

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
                    <th>Access</th>
                    <th>2FA</th>
                    <th>Last Login</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u):
                $is_self   = ((int)$u['id'] === (int)(current_user()['id'] ?? 0));
                $role_slug = str_replace('_', '-', strtolower((string)($u['role'] ?? 'office')));
            ?>
                <tr>
                    <td>
                        <?= e($u['name'] ?? 'Unknown User') ?>
                        <?php if ($is_self): ?>
                            <span class="text-muted" style="font-size:.72rem;">(you)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($u['email'] ?? '') ?></td>
                    <td>
                        <span class="tp-badge badge-<?= e($role_slug) ?>" style="background:#e5e7eb;color:#374151;">
                            <?= e($role_labels[$u['role'] ?? 'office'] ?? ucfirst((string)($u['role'] ?? 'office'))) ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($u['active'])): ?>
                            <span class="tp-badge badge-available">Active</span>
                        <?php else: ?>
                            <span class="tp-badge badge-canceled">Inactive</span>
                        <?php endif; ?>
                        <?php if (($u['role'] ?? '') === 'admin' && in_array((int)$u['id'], $super_admin_ids, true)): ?>
                            <span class="tp-badge ms-1" style="background:rgba(249,115,22,.12);color:#ea580c;border:1px solid rgba(249,115,22,.25);">Super Admin</span>
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
                                        onclick="return confirm('Disable 2FA for <?= e(addslashes((string)($u['name'] ?? 'this user'))) ?>?')">
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
                        <a href="edit_user.php?id=<?= (int)$u['id'] ?>" class="btn-tp-ghost btn-tp-sm">
                            <i class="fa-solid fa-pencil"></i> Edit
                        </a>
                        <?php if (($u['role'] ?? '') === 'admin'): ?>
                        <form method="POST" action="users.php" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle-super-admin">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn-tp-ghost btn-tp-sm" title="Toggle super admin access">
                                <i class="fa-solid fa-user-shield"></i>
                                <?= in_array((int)$u['id'], $super_admin_ids, true) ? 'Super Admin On' : 'Make Super Admin' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if (!$is_self): ?>
                        <form method="POST" action="users.php" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle-active">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit"
                                    class="btn-tp-ghost btn-tp-sm <?= !empty($u['active']) ? 'text-danger' : 'text-success' ?>"
                                    onclick="return confirm('<?= !empty($u['active']) ? 'Deactivate' : 'Activate' ?> <?= e(addslashes((string)($u['name'] ?? 'this user'))) ?>?')">
                                <?php if (!empty($u['active'])): ?>
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
