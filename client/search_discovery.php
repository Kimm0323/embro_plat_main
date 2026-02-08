<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$search_term = sanitize($_GET['search'] ?? '');
$hiring_filter = sanitize($_GET['hiring'] ?? '');

$shops_stmt = $pdo->query("
    SELECT *
    FROM shops
    WHERE status = 'active'
    ORDER BY rating DESC, total_orders DESC
");
$shops = $shops_stmt->fetchAll();

$capacity_map = [];
$capacity_stmt = $pdo->query("
    SELECT shop_id, COALESCE(SUM(max_active_orders), 0) AS total_capacity
    FROM shop_staffs
    WHERE status = 'active'
    GROUP BY shop_id
");
foreach ($capacity_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $capacity_map[(int) $row['shop_id']] = (int) $row['total_capacity'];
}

$workload_map = [];
$workload_stmt = $pdo->query("
    SELECT shop_id, COUNT(*) AS active_orders
    FROM orders
    WHERE status IN ('pending', 'accepted', 'in_progress')
    GROUP BY shop_id
");
foreach ($workload_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $workload_map[(int) $row['shop_id']] = (int) $row['active_orders'];
}

$reliability_map = [];
$reliability_stmt = $pdo->query("
    SELECT shop_id,
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders
    FROM orders
    GROUP BY shop_id
");
foreach ($reliability_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $total_orders = (int) $row['total_orders'];
    $completed_orders = (int) $row['completed_orders'];
    $reliability_map[(int) $row['shop_id']] = [
        'total' => $total_orders,
        'completed' => $completed_orders,
        'rate' => $total_orders > 0 ? ($completed_orders / $total_orders) : 0,
    ];
}

$hiring_posts_map = [];
$hiring_stmt = $pdo->query("
    SELECT hp.*, s.shop_name
    FROM hiring_posts hp
    INNER JOIN shops s ON s.id = hp.shop_id
    WHERE hp.status = 'live'
      AND s.status = 'active'
      AND (hp.expires_at IS NULL OR hp.expires_at >= NOW())
    ORDER BY hp.created_at DESC
");
foreach ($hiring_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $shop_id = (int) $row['shop_id'];
    if (!isset($hiring_posts_map[$shop_id])) {
        $hiring_posts_map[$shop_id] = [];
    }
    $row['expiration_status'] = 'Open';
    if (!empty($row['expires_at'])) {
        $expires_ts = strtotime($row['expires_at']);
        $row['expiration_status'] = $expires_ts && $expires_ts >= time() ? 'Active' : 'Expired';
    }
    $hiring_posts_map[$shop_id][] = $row;
}

$shops = array_map(function(array $shop) use ($capacity_map, $workload_map, $reliability_map, $hiring_posts_map) {
    $shop_id = (int) $shop['id'];
    $capacity = $capacity_map[$shop_id] ?? 0;
    $active_orders = $workload_map[$shop_id] ?? 0;
    $reliability = $reliability_map[$shop_id] ?? ['total' => 0, 'completed' => 0, 'rate' => 0];
    $rating_score = min(1, max(0, ((float) $shop['rating']) / 5));
    $capacity_score = $capacity > 0 ? max(0, min(1, ($capacity - $active_orders) / $capacity)) : 0;
    $reliability_score = (float) ($reliability['rate'] ?? 0);

    $dss_score = $rating_score + $reliability_score + $capacity_score;

    $shop['capacity'] = $capacity;
    $shop['active_orders'] = $active_orders;
    $shop['reliability'] = $reliability;
    $shop['dss_score'] = round($dss_score, 2);
    $shop['dss_breakdown'] = [
        'rating' => round($rating_score * 100, 1),
        'completion' => round($reliability_score * 100, 1),
        'capacity' => round($capacity_score * 100, 1),
    ];
    $shop['hiring_posts'] = $hiring_posts_map[$shop_id] ?? [];
    return $shop;
}, $shops);

$shops = array_values(array_filter($shops, function(array $shop) use ($search_term, $hiring_filter) {
    if ($search_term !== '') {
        $haystack = mb_strtolower(trim(($shop['shop_name'] ?? '') . ' ' . ($shop['shop_description'] ?? '') . ' ' . ($shop['address'] ?? '')));
        if (mb_strpos($haystack, mb_strtolower($search_term)) === false) {
            return false;
        }
    }
    if ($hiring_filter === '1' && empty($shop['hiring_posts'])) {
        return false;
    }
    return true;
}));

usort($shops, function(array $a, array $b) {
    if ($b['dss_score'] === $a['dss_score']) {
        return $b['rating'] <=> $a['rating'];
    }
    return $b['dss_score'] <=> $a['dss_score'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search & Discovery Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .discovery-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card,
        .search-card,
        .results-card {
            grid-column: span 12;
        }

        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .shop-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
        }

        .shop-card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1.25rem;
            background: var(--bg-primary);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .shop-card h4 {
            margin: 0;
        }

        .shop-meta {
            display: grid;
            gap: 0.35rem;
            font-size: 0.92rem;
        }

        .shop-meta span {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .dss-badge {
            background: var(--primary-100);
            color: var(--primary-700);
            border-radius: 999px;
            padding: 0.35rem 0.75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .hiring-list {
            border-top: 1px dashed var(--gray-200);
            padding-top: 0.75rem;
            display: grid;
            gap: 0.75rem;
        }

        .hiring-post {
            background: var(--gray-50);
            border-radius: var(--radius);
            padding: 0.75rem;
        }

        .hiring-post h5 {
            margin: 0 0 0.35rem;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            background: var(--success-100);
            color: var(--success-700);
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .pill--warning {
            background: #fef3c7;
            color: #92400e;
        }

        .pill--danger {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user"></i> Client Portal
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-clipboard-list"></i> Orders
                    </a>
                    <div class="dropdown-menu">
                        <a href="place_order.php" class="dropdown-item"><i class="fas fa-plus-circle"></i> Place Order</a>
                        <a href="track_order.php" class="dropdown-item"><i class="fas fa-route"></i> Track Orders</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle active">
                        <i class="fas fa-layer-group"></i> Services
                    </a>
                    <div class="dropdown-menu">
                        <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                        <a href="search_discovery.php" class="dropdown-item active"><i class="fas fa-compass"></i> Search & Discovery</a>
                        <a href="design_proofing.php" class="dropdown-item"><i class="fas fa-clipboard-check"></i> Design Proofing &amp; Approval</a>
                        <a href="pricing_quotation.php" class="dropdown-item"><i class="fas fa-calculator"></i> Pricing &amp; Quotation</a>
                        <a href="order_management.php" class="dropdown-item"><i class="fas fa-clipboard-list"></i> Order Management</a>
                        <a href="payment_handling.php" class="dropdown-item"><i class="fas fa-hand-holding-dollar"></i> Payment Handling &amp; Release</a>
                        <a href="client_posting_community.php" class="dropdown-item"><i class="fas fa-comments"></i> Client Posting &amp; Community</a>
                    </div>
                </li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="notifications.php" class="nav-link">Notifications
                    <?php if ($unread_notifications > 0): ?>
                        <span class="badge badge-danger"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Search, Discovery & Hiring Visibility</h2>
                    <p class="text-muted">Find the right shop, check availability, and explore hiring opportunities.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-compass"></i> Module 6</span>
            </div>
        </div>

        <div class="discovery-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Shop Discovery (Live)</h3>
                </div>
                <p class="text-muted mb-0">
                    Browse active embroidery shops, compare Decision Support System (DSS) scores, and review live hiring posts
                    before reaching out or placing an order.
                </p>
            </div>

            <div class="card search-card">
                <div class="card-header">
                    <h3><i class="fas fa-filter text-primary"></i> Filter Results</h3>
                </div>
                <form class="search-form" method="get" action="search_discovery.php">
                    <label class="form-group">
                        <span class="form-label">Search shop or location</span>
                        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="e.g. Market Street or Thread & Needle">
                    </label>
                    <label class="form-group">
                        <span class="form-label">Hiring filter</span>
                        <select name="hiring" class="form-control">
                            <option value="">All shops</option>
                            <option value="1" <?php echo $hiring_filter === '1' ? 'selected' : ''; ?>>Only shops hiring</option>
                        </select>
                    </label>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                        <a href="search_discovery.php" class="btn btn-outline">Reset</a>
                    </div>
                </form>
            </div>

            <div class="card results-card">
                <div class="card-header">
                    <h3><i class="fas fa-store text-primary"></i> Ranked Shops</h3>
                    <p class="text-muted">Sorted by DSS score (rating + completion rate + capacity).</p>
                </div>
                <?php if (empty($shops)): ?>
                    <p class="text-muted mb-0">No shops matched your filters. Try adjusting the search or hiring filter.</p>
                <?php else: ?>
                    <div class="shop-grid">
                        <?php foreach ($shops as $shop): ?>
                            <div class="shop-card">
                                <div class="d-flex justify-between align-center">
                                    <h4><?php echo htmlspecialchars($shop['shop_name']); ?></h4>
                                    <span class="dss-badge"><i class="fas fa-chart-line"></i> DSS <?php echo number_format((float) $shop['dss_score'], 2); ?> / 3</span>
                                </div>
                                <p class="text-muted mb-0"><?php echo htmlspecialchars($shop['shop_description'] ?? ''); ?></p>
                                <div class="shop-meta text-muted">
                                    <span><i class="fas fa-map-marker-alt text-primary"></i> <?php echo htmlspecialchars($shop['address'] ?? 'Address not set'); ?></span>
                                    <span><i class="fas fa-star text-warning"></i> Rating: <?php echo number_format((float) $shop['rating'], 1); ?> (<?php echo (int) $shop['rating_count']; ?>)</span>
                                    <span><i class="fas fa-clipboard-check text-success"></i> Reliability: <?php echo round(((float) ($shop['reliability']['rate'] ?? 0)) * 100); ?>%</span>
                                    <span><i class="fas fa-boxes-stacked text-info"></i> Capacity: <?php echo (int) $shop['active_orders']; ?>/<?php echo (int) $shop['capacity']; ?> active orders</span>
                                    <span><i class="fas fa-chart-pie text-primary"></i> DSS Breakdown:
                                        Rating <?php echo number_format((float) ($shop['dss_breakdown']['rating'] ?? 0), 1); ?>% ·
                                        Completion <?php echo number_format((float) ($shop['dss_breakdown']['completion'] ?? 0), 1); ?>% ·
                                        Capacity <?php echo number_format((float) ($shop['dss_breakdown']['capacity'] ?? 0), 1); ?>%
                                    </span>
                                </div>
                                <?php if (!empty($shop['hiring_posts'])): ?>
                                    <div class="hiring-list">
                                        <span class="pill"><i class="fas fa-briefcase"></i> Hiring now</span>
                                        <?php foreach ($shop['hiring_posts'] as $post): ?>
                                            <div class="hiring-post">
                                                <h5><?php echo htmlspecialchars($post['title']); ?></h5>
                                                <p class="text-muted mb-1"><?php echo nl2br(htmlspecialchars($post['description'] ?? '')); ?></p>
                                                <small class="text-muted">Posted <?php echo date('M d, Y', strtotime($post['created_at'])); ?>
                                                    <?php if (!empty($post['expires_at'])): ?>
                                                        · Expires <?php echo date('M d, Y', strtotime($post['expires_at'])); ?>
                                                    <?php endif; ?>
                                                </small>
                                                <div class="mt-2">
                                                    <?php
                                                        $expiration_status = $post['expiration_status'] ?? 'Open';
                                                        $status_class = 'pill--warning';
                                                        if ($expiration_status === 'Active' || $expiration_status === 'Open') {
                                                            $status_class = '';
                                                        } elseif ($expiration_status === 'Expired') {
                                                            $status_class = 'pill--danger';
                                                        }
                                                    ?>
                                                    <span class="pill <?php echo $status_class; ?>">
                                                        <i class="fas fa-hourglass-half"></i> <?php echo htmlspecialchars($expiration_status); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0"><i class="fas fa-info-circle"></i> No live hiring posts from this shop.</p>
                                <?php endif; ?>
                                <div class="d-flex gap-2">
                                    <a href="place_order.php" class="btn btn-primary btn-sm"><i class="fas fa-plus-circle"></i> Place Order</a>
                                    <a href="client_posting_community.php" class="btn btn-outline btn-sm"><i class="fas fa-comments"></i> Ask a Question</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
