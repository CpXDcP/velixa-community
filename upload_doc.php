<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
vx_require_auth(true);
header('Location: interface_user.php?focus=upload#upload-zone');
exit;
