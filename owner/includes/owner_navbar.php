<?php
$current_page = basename($_SERVER['PHP_SELF'] ?? '');

$brand_name = $shop['shop_name'] ?? ($_SESSION['user']['fullname'] ?? 'Owner Portal');

$active_groups = [
    'dashboard' => ['dashboard.php'],
    'shop_profile' => ['shop_profile.php', 'create_shop.php'],
    'pricing' => ['pricing_management.php'],
    'staff' => ['manage_staff.php', 'add_staff.php', 'edit_employee.php', 'create_hr.php'],
    'orders' => ['shop_orders.php', 'view_order.php', 'accept_order.php', 'reject_order.php', 'view_invoice.php', 'view_receipt.php'],
    'community' => ['client_community_posts.php', 'shop_posting_content.php'],
    'reviews' => ['reviews.php'],
    'messages' => ['messages.php'],
    'delivery' => ['delivery_management.php'],
    'finance' => ['payment_verifications.php', 'earnings.php'],
    'operations' => [
        'supplier_management.php',
        'supplier_list.php',
        'raw_material_inventory.php',
        'inventory_production_supply_chain_automation.php',
        'production_tracking.php',
        'quality_control.php',
        'storage_warehouse_management.php',
        'finished_goods_order_storage.php',
        'workforce_scheduling.php',
        'dispute_resolution.php',
    ],
    'analytics' => ['analytics_reporting.php'],
    'preferences' => ['notification_preferences.php'],
    'profile' => ['profile.php'],
];

$is_active = static function (string $key) use ($active_groups, $current_page): bool {
    return in_array($current_page, $active_groups[$key] ?? [], true);
};
?>
<nav class="navbar navbar--compact">
    <div class="container d-flex justify-between align-center">
        <a href="dashboard.php" class="navbar-brand">
            <i class="fas fa-store"></i> <?php echo htmlspecialchars($brand_name); ?>
        </a>
        <ul class="navbar-nav">
            <li><a href="dashboard.php" class="nav-link <?php echo $is_active('dashboard') ? 'active' : ''; ?>">Dashboard</a></li>
            <li><a href="shop_profile.php" class="nav-link <?php echo $is_active('shop_profile') ? 'active' : ''; ?>">Shop Profile</a></li>
            <li><a href="pricing_management.php" class="nav-link <?php echo $is_active('pricing') ? 'active' : ''; ?>">Pricing</a></li>
            <li><a href="manage_staff.php" class="nav-link <?php echo $is_active('staff') ? 'active' : ''; ?>">Staff</a></li>
            <li><a href="shop_orders.php" class="nav-link <?php echo $is_active('orders') ? 'active' : ''; ?>">Orders</a></li>
            <li><a href="client_community_posts.php" class="nav-link <?php echo $is_active('community') ? 'active' : ''; ?>">Community Posts</a></li>
            <li><a href="reviews.php" class="nav-link <?php echo $is_active('reviews') ? 'active' : ''; ?>">Reviews</a></li>
            <li><a href="messages.php" class="nav-link <?php echo $is_active('messages') ? 'active' : ''; ?>">Messages</a></li>
            <li><a href="delivery_management.php" class="nav-link <?php echo $is_active('delivery') ? 'active' : ''; ?>">Delivery & Pickup</a></li>
            <li class="dropdown">
                <a href="#" class="nav-link dropdown-toggle <?php echo $is_active('finance') ? 'active' : ''; ?>">
                    <i class="fas fa-coins"></i> Finance
                </a>
                <div class="dropdown-menu">
                    <a href="payment_verifications.php" class="dropdown-item"><i class="fas fa-receipt"></i> Payments</a>
                    <a href="earnings.php" class="dropdown-item"><i class="fas fa-wallet"></i> Earnings</a>
                </div>
            </li>
            <li class="dropdown">
                <a href="#" class="nav-link dropdown-toggle <?php echo $is_active('operations') ? 'active' : ''; ?>">
                    <i class="fas fa-cogs"></i> Operations
                </a>
                <div class="dropdown-menu">
                    <a href="supplier_management.php" class="dropdown-item"><i class="fas fa-truck-loading"></i> Supplier Management</a>
                    <a href="raw_material_inventory.php" class="dropdown-item"><i class="fas fa-boxes"></i> Raw Materials</a>
                    <a href="inventory_production_supply_chain_automation.php" class="dropdown-item"><i class="fas fa-project-diagram"></i> Supply Chain</a>
                    <a href="production_tracking.php" class="dropdown-item"><i class="fas fa-industry"></i> Production Tracking</a>
                    <a href="quality_control.php" class="dropdown-item"><i class="fas fa-check-circle"></i> Quality Control</a>
                    <a href="storage_warehouse_management.php" class="dropdown-item"><i class="fas fa-warehouse"></i> Warehouse</a>
                    <a href="finished_goods_order_storage.php" class="dropdown-item"><i class="fas fa-dolly-flatbed"></i> Finished Goods</a>
                    <a href="workforce_scheduling.php" class="dropdown-item"><i class="fas fa-calendar-alt"></i> Workforce Scheduling</a>
                    <a href="dispute_resolution.php" class="dropdown-item"><i class="fas fa-balance-scale"></i> Dispute Resolution</a>
                </div>
            </li>
            <li><a href="analytics_reporting.php" class="nav-link <?php echo $is_active('analytics') ? 'active' : ''; ?>">Analytics</a></li>
            <li><a href="notification_preferences.php" class="nav-link <?php echo $is_active('preferences') ? 'active' : ''; ?>">Preferences</a></li>
            <li class="dropdown">
                <a href="#" class="nav-link dropdown-toggle <?php echo $is_active('profile') ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                </a>
                <div class="dropdown-menu">
                    <a href="profile.php" class="dropdown-item"><i class="fas fa-user-cog"></i> Profile</a>
                    <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </li>
        </ul>
    </div>
</nav>
