<?php
// Redirect root to dashboard
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Location: dashboard.php', true, 302);
exit;