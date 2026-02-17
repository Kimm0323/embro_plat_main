<?php
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$unreadCount = isset($unread_notifications) ? (int) $unread_notifications : 0;

$isHomeActive = $currentPage === 'dashboard.php';
$isOrdersActive = in_array($currentPage, ['track_order.php', 'rate_provider.php', 'payment_handling.php'], true);
$isServicesActive = in_array($currentPage, ['customize_design.php', 'design_proofing.php', 'pricing_quotation.php', 'client_posting_community.php'], true);
$isMessageActive = $currentPage === 'messages.php';
$isNotificationActive = in_array($currentPage, ['notifications.php', 'notification_preferences.php'], true);
?>
<nav class="navbar navbar--compact">
    <div class="container d-flex justify-between align-center">
        <a href="dashboard.php" class="navbar-brand">
            <i class="fas fa-user"></i> Client Portal
        </a>
        <ul class="navbar-nav">
            <li><a href="dashboard.php" class="nav-link<?php echo $isHomeActive ? ' active' : ''; ?>">Home</a></li>
            <li class="dropdown">
                <a href="#" class="nav-link dropdown-toggle<?php echo $isOrdersActive ? ' active' : ''; ?>">
                    <i class="fas fa-clipboard-list"></i> My Orders
                </a>
                <div class="dropdown-menu">
                    <a href="track_order.php" class="dropdown-item"><i class="fas fa-route"></i> Track Orders</a>
                    <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Orders</a>
                    <a href="payment_handling.php" class="dropdown-item"><i class="fas fa-credit-card"></i> Payment Methods</a>
                </div>
            </li>
            <li class="dropdown">
                <a href="#" class="nav-link dropdown-toggle<?php echo $isServicesActive ? ' active' : ''; ?>">
                    <i class="fas fa-layer-group"></i> Services
                </a>
                <div class="dropdown-menu">
                    <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                    <a href="design_proofing.php" class="dropdown-item"><i class="fas fa-ruler-combined"></i> Design Proofing and Price Quotation</a>
                    <a href="client_posting_community.php" class="dropdown-item"><i class="fas fa-comments"></i> Client Posting Community</a>
                </div>
            </li>
            <li><a href="messages.php" class="nav-link<?php echo $isMessageActive ? ' active' : ''; ?>">Message</a></li>
            <li><a href="notifications.php" class="nav-link<?php echo $isNotificationActive ? ' active' : ''; ?>">Notification
                <?php if ($unreadCount > 0): ?>
                    <span class="badge badge-danger"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a></li>
            <li class="dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-user-circle"></i> Customer
                </a>
                <div class="dropdown-menu">
                    <a href="dashboard.php" class="dropdown-item"><i class="fas fa-id-card"></i> Customer Profile</a>
                    <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Log Out</a>
                </div>
            </li>
        </ul>
    </div>
</nav>