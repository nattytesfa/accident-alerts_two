<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

/*
 * Registration is disabled: this is an admin-only system with a single,
 * pre-seeded account. Anyone trying to self-register is sent to the login
 * page, which explains that accounts cannot be created here.
 */
header("Location: login.php?reg=disabled");
exit;