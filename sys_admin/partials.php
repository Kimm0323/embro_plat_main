<?php
function sys_admin_nav(string $activePage): void {
    $userName = htmlspecialchars($_SESSION['user']['fullname'] ?? 'System Admin');
    $navItems = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt', 'href' => 'dashboard.php'],
        ['key' => 'analytics', 'label' => 'Analytics', 'icon' => 'fas fa-chart-line', 'href' => 'analytics.php'],
        ['key' => 'analytics_reporting', 'label' => 'Reporting', 'icon' => 'fas fa-chart-pie', 'href' => 'analytics_reporting.php'],
    ];
    $userItems = [
        ['key' => 'member_approval', 'label' => 'Approvals', 'icon' => 'fas fa-user-check', 'href' => 'member_approval.php'],
        ['key' => 'membership_lifecycle', 'label' => 'Lifecycle', 'icon' => 'fas fa-user-clock', 'href' => 'membership_lifecycle.php'],
        ['key' => 'accounts', 'label' => 'Accounts', 'icon' => 'fas fa-users', 'href' => 'accounts.php'],
    ];
    $systemItems = [
        ['key' => 'system_control', 'label' => 'Control', 'icon' => 'fas fa-sliders-h', 'href' => 'system_control.php'],
        ['key' => 'content_moderation', 'label' => 'Moderation', 'icon' => 'fas fa-shield-halved', 'href' => 'content_moderation.php'],
        ['key' => 'notification_reminder', 'label' => 'Notifications', 'icon' => 'fas fa-bell', 'href' => 'notification_reminder.php'],
        ['key' => 'config_backup', 'label' => 'Config & Backup', 'icon' => 'fas fa-database', 'href' => 'backup.php'],
        ['key' => 'dss_config', 'label' => 'Security', 'icon' => 'fas fa-shield-alt', 'href' => 'dss_config.php'],
        ['key' => 'audit_logs', 'label' => 'Audit Logs', 'icon' => 'fas fa-clipboard-list', 'href' => 'audit_logs.php'],
    ];
    $userActive = in_array($activePage, array_column($userItems, 'key'), true);
    $systemActive = in_array($activePage, array_column($systemItems, 'key'), true);
    ?>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <div class="d-flex align-center gap-4">
                <a href="dashboard.php" class="navbar-brand">
                    <i class="fas fa-cogs"></i>
                    <span>System Admin</span>
                </a>
                <div class="d-flex gap-2">
                    <span class="badge badge-primary">Online</span>
                    <span class="text-muted">v2.1.0</span>
                </div>
            </div>

            <ul class="navbar-nav">
                <?php foreach ($navItems as $item): ?>
                    <li>
                        <a href="<?php echo $item['href']; ?>" class="nav-link <?php echo $activePage === $item['key'] ? 'active' : ''; ?>">
                            <i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle <?php echo $userActive ? 'active' : ''; ?>">
                        <i class="fas fa-users-cog"></i> Users
                    </a>
                    <div class="dropdown-menu">
                        <?php foreach ($userItems as $item): ?>
                            <a href="<?php echo $item['href']; ?>" class="dropdown-item <?php echo $activePage === $item['key'] ? 'active' : ''; ?>">
                                <i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle <?php echo $systemActive ? 'active' : ''; ?>">
                        <i class="fas fa-sliders-h"></i> System
                    </a>
                    <div class="dropdown-menu">
                        <?php foreach ($systemItems as $item): ?>
                            <a href="<?php echo $item['href']; ?>" class="dropdown-item <?php echo $activePage === $item['key'] ? 'active' : ''; ?>">
                                <i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user-circle"></i> <?php echo $userName; ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-cog"></i> Profile Settings</a>
                        <a href="settings.php" class="dropdown-item"><i class="fas fa-cog"></i> System Settings</a>
                        <div class="dropdown-divider"></div>
                        <a href="../auth/logout.php" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>
            </ul>
        </div>
    </nav>
    <?php
}

function sys_admin_footer(): void {
    ?>
    <footer class="footer">
        <div class="container">
            <div class="d-flex justify-between align-center">
                <div>
                    <p class="mb-1">&copy; 2024 Embroidery Platform - System Admin Panel</p>
                    <small class="text-muted">Last updated: <?php echo date('F j, Y, g:i a'); ?></small>
                </div>
                <div class="d-flex gap-3">
                    <small class="text-muted">Server: <?php echo $_SERVER['SERVER_NAME'] ?? 'localhost'; ?></small>
                    <small class="text-muted">PHP: <?php echo phpversion(); ?></small>
                    <small class="text-muted">Users Online: 24</small>
                </div>
            </div>
        </div>
    </footer>
    <?php
}
