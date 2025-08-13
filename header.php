<?php
// Header include for all pages.
$activePage = basename($_SERVER['PHP_SELF'], '.php');
?>
<header class="site-header">
    <nav class="nav">
        <ul>
            <li class="<?php echo ($activePage === 'dashboard') ? 'active' : ''; ?>"><a href="dashboard.php" rel="noopener">Dashboard</a></li>
            <li class="<?php echo ($activePage === 'screen') ? 'active' : ''; ?>"><a href="screen.php" rel="noopener">Screen</a></li>
            <li class="<?php echo ($activePage === 'admin') ? 'active' : ''; ?>"><a href="admin.php" rel="noopener">Admin</a></li>
        </ul>
    </nav>
</header>