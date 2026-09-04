<?php
/**
 * admin/logout.php — End the administrative session.
 *
 * The laboratory identity in current_user_name() is left alone, so signing out
 * of administration does not make the person retype their name on the use form.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    admin_logout();
    flash('Signed out of administration.', 'success');
}

redirect('../index.php');
