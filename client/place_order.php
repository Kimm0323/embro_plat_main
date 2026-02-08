<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_once '../includes/media_manager.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$error = '';
$success = '';
$available_services = [
    'T-shirt Embroidery',
    'Logo Embroidery',
    'Cap Embroidery',
    'Bag Embroidery',
    'Custom',
];
$default_pricing_settings = [
    'base_prices' => [
        'T-shirt Embroidery' => 180,
        'Logo Embroidery' => 160,
        'Cap Embroidery' => 150,
        'Bag Embroidery' => 200,
        'Custom' => 200,
    ],
    'complexity_multipliers' => [
        'Simple' => 1,
        'Standard' => 1.15,
        'Complex' => 1.35,
    ],
    'rush_fee_percent' => 25,
    'add_ons' => [
        'Metallic Thread' => 50,
        '3D Puff' => 75,
        'Extra Color' => 25,
        'Applique' => 60,
    ],
];
$search_term = sanitize($_GET['search'] ?? '');
$service_filter = sanitize($_GET['service'] ?? '');
$open_filter = sanitize($_GET['open'] ?? '');
$prefill_design_version_id = (int) ($_GET['design_version_id'] ?? 0);

function validate_design_description(string $description): string {
    $trimmed = trim($description);
    $length = mb_strlen($trimmed);
    if ($length < 30) {
        return 'Design description must be at least 30 characters and include placement, size, and color details.';
    }
    if ($length > 1000) {
        return 'Design description cannot exceed 1000 characters.';
    }

    return '';
}

function resolve_pricing_settings(array $shop, array $defaults): array {
    if (!empty($shop['pricing_settings'])) {
        $decoded = json_decode($shop['pricing_settings'], true);
        if (is_array($decoded)) {
            return array_replace_recursive($defaults, $decoded);
        }
    }

    return $defaults;
}

function resolve_shop_services(array $shop, array $available_services): array {
    if (!empty($shop['service_settings'])) {
        $decoded = json_decode($shop['service_settings'], true);
        if (is_array($decoded)) {
            return array_values(array_intersect($available_services, $decoded));
        }
    }

    return $available_services;
}

function resolve_operating_days(array $shop): array {
    if (!empty($shop['operating_days'])) {
        $decoded = json_decode($shop['operating_days'], true);
        if (is_array($decoded)) {
            return array_map('intval', $decoded);
        }
    }

    return [1, 2, 3, 4, 5, 6];
}

function is_shop_open(array $shop): bool {
    $operating_days = resolve_operating_days($shop);
    $today = (int) date('w');
    if (!in_array($today, $operating_days, true)) {
        return false;
    }

    $opening_time = $shop['opening_time'] ?: '08:00';
    $closing_time = $shop['closing_time'] ?: '18:00';
    $current_minutes = ((int) date('H')) * 60 + (int) date('i');
    [$open_hour, $open_minute] = array_map('intval', explode(':', $opening_time));
    [$close_hour, $close_minute] = array_map('intval', explode(':', $closing_time));
    $open_minutes = $open_hour * 60 + $open_minute;
    $close_minutes = $close_hour * 60 + $close_minute;

    return $current_minutes >= $open_minutes && $current_minutes <= $close_minutes;
}

function calculate_distance_score(string $search_term, array $shop): float {
    if ($search_term === '') {
        return 0.5;
    }

    $needle = mb_strtolower($search_term);
    $haystack = mb_strtolower(trim(($shop['address'] ?? '') . ' ' . ($shop['shop_name'] ?? '')));
    if ($haystack === '') {
        return 0.3;
    }

    $words = array_values(array_filter(preg_split('/\s+/', $needle)));
    if (empty($words)) {
        return 0.5;
    }

    $matches = 0;
    foreach ($words as $word) {
        if (mb_strlen($word) < 3) {
            continue;
        }
        if (mb_strpos($haystack, $word) !== false) {
            $matches++;
        }
    }

    $score = $matches / max(1, count($words));
    return max(0.1, min(1, $score));
}

function calculate_average_base_price(array $pricing_settings): float {
    $base_prices = $pricing_settings['base_prices'] ?? [];
    if (!is_array($base_prices) || empty($base_prices)) {
        return 0.0;
    }

    $values = array_filter(array_map('floatval', $base_prices), fn($price) => $price > 0);
    if (empty($values)) {
        return 0.0;
    }

    return array_sum($values) / count($values);
}

// Get available shops
$shops_stmt = $pdo->query("
    SELECT * FROM shops 
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

$portfolio_samples = [];
$portfolio_stmt = $pdo->query("
    SELECT shop_id, title, image_path
    FROM shop_portfolio
    ORDER BY created_at DESC
");
foreach ($portfolio_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $shop_id = (int) $row['shop_id'];
    if (!isset($portfolio_samples[$shop_id])) {
        $portfolio_samples[$shop_id] = [];
    }
    if (count($portfolio_samples[$shop_id]) < 3) {
        $portfolio_samples[$shop_id][] = $row;
    }
}


$shops = array_map(function($shop) use ($available_services, $default_pricing_settings, $capacity_map, $workload_map, $portfolio_samples, $reliability_map) {    $shop_id = (int) $shop['id'];
    $shop['operating_days_list'] = resolve_operating_days($shop);
    $shop['service_list'] = resolve_shop_services($shop, $available_services);
    $shop['is_open'] = is_shop_open($shop);
    $shop['pricing_settings'] = resolve_pricing_settings($shop, $default_pricing_settings);
    $shop['average_base_price'] = calculate_average_base_price($shop['pricing_settings']);
    $shop['capacity'] = $capacity_map[$shop_id] ?? 0;
    $shop['active_orders'] = $workload_map[$shop_id] ?? 0;
    $shop['reliability'] = $reliability_map[$shop_id] ?? ['total' => 0, 'completed' => 0, 'rate' => 0];
    $shop['portfolio_samples'] = $portfolio_samples[$shop_id] ?? [];
    return $shop;
}, $shops);

$price_values = array_values(array_filter(array_map(fn($shop) => $shop['average_base_price'], $shops)));
$min_price = !empty($price_values) ? min($price_values) : 0;
$max_price = !empty($price_values) ? max($price_values) : 0;

$ranking_weights = [
    'distance' => 0.15,
    'rating' => 0.2,
    'capacity' => 0.15,
    'reliability' => 0.2,
    'pricing' => 0.15,
    'specialization' => 0.15,
];

$shops = array_map(function($shop) use ($search_term, $service_filter, $available_services, $ranking_weights, $min_price, $max_price) {
    $distance_score = calculate_distance_score($search_term, $shop);
    $rating_score = min(1, max(0, ((float) $shop['rating']) / 5));
    $capacity = (int) $shop['capacity'];
    $active_orders = (int) $shop['active_orders'];
    $capacity_score = $capacity > 0 ? max(0, min(1, ($capacity - $active_orders) / $capacity)) : 0.2;
    $reliability_score = (float) ($shop['reliability']['rate'] ?? 0);
    $avg_price = (float) $shop['average_base_price'];
    if ($avg_price <= 0 || $max_price <= 0 || $max_price === $min_price) {
        $pricing_score = 0.5;
    } else {
        $pricing_score = 1 - (($avg_price - $min_price) / ($max_price - $min_price));
    }
    $service_count = count($shop['service_list']);
    $service_total = count($available_services);
    if ($service_filter !== '') {
        $specialization_score = in_array($service_filter, $shop['service_list'], true) ? 1 : 0;
    } else {
        $specialization_score = $service_total > 0 ? min(1, $service_count / $service_total) : 0.5;
    }

    $ranking_score = (
        $distance_score * $ranking_weights['distance']
        + $rating_score * $ranking_weights['rating']
        + $capacity_score * $ranking_weights['capacity']
        + $reliability_score * $ranking_weights['reliability']
        + $pricing_score * $ranking_weights['pricing']
        + $specialization_score * $ranking_weights['specialization']
    );

    $shop['ranking_score'] = round($ranking_score * 100, 1);
    $shop['ranking_breakdown'] = [
        'distance' => round($distance_score * 100),
        'rating' => round($rating_score * 100),
        'capacity' => round($capacity_score * 100),
        'reliability' => round($reliability_score * 100),
        'pricing' => round($pricing_score * 100),
        'specialization' => round($specialization_score * 100),
    ];
    return $shop;
}, $shops);

$shops = array_values(array_filter($shops, function($shop) use ($search_term, $service_filter, $open_filter) {
    if ($search_term !== '') {
        $haystack = mb_strtolower($shop['shop_name'] . ' ' . $shop['shop_description'] . ' ' . $shop['address']);
        if (mb_strpos($haystack, mb_strtolower($search_term)) === false) {
            return false;
        }
    }
    if ($service_filter !== '' && !in_array($service_filter, $shop['service_list'], true)) {
        return false;
    }
    if ($open_filter === '1' && !$shop['is_open']) {
        return false;
    }
    return true;
}));

usort($shops, function($a, $b) {
    if ($b['ranking_score'] === $a['ranking_score']) {
        return $b['rating'] <=> $a['rating'];
    }
    return $b['ranking_score'] <=> $a['ranking_score'];
});
$max_upload_mb = (int) ceil(MAX_FILE_SIZE / (1024 * 1024));
$design_versions_stmt = $pdo->prepare("
    SELECT dv.id, dv.version_no, dv.preview_file, dv.created_at, dp.title AS project_title
    FROM design_versions dv
    INNER JOIN design_projects dp ON dp.id = dv.project_id
    WHERE dp.client_id = ?
    ORDER BY dv.created_at DESC
");
$design_versions_stmt->execute([$client_id]);
$design_versions = $design_versions_stmt->fetchAll();

// Place order
if(isset($_POST['place_order'])) {
    $shop_id = (int) ($_POST['shop_id'] ?? 0);
    $service_type = sanitize($_POST['service_type'] ?? '');
    $custom_service = sanitize($_POST['custom_service'] ?? '');
    $design_description = sanitize($_POST['design_description'] ?? '');
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $client_notes = sanitize($_POST['client_notes'] ?? '');
    $complexity_level = sanitize($_POST['complexity_level'] ?? 'Standard');
    $design_version_id = (int) ($_POST['design_version_id'] ?? 0);
    $requested_add_ons = $_POST['add_ons'] ?? [];
    if (!is_array($requested_add_ons)) {
        $requested_add_ons = [];
    }
    $rush_requested = ($_POST['rush_service'] ?? '') === '1';
    
    // Generate order number
    $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    try {
        $shop_policy_stmt = $pdo->prepare("SELECT * FROM shops WHERE id = ? AND status = 'active'");
        $shop_policy_stmt->execute([$shop_id]);
        $shop_policy = $shop_policy_stmt->fetch();
        if (!$shop_policy) {
            throw new RuntimeException('Selected shop is no longer available.');
        }

        if ($shop_id <= 0) {
            throw new RuntimeException('Please select a service provider.');
        }

        $enabled_services = resolve_shop_services($shop_policy, $available_services);
        $is_custom_allowed = in_array('Custom', $enabled_services, true);
        if ($custom_service !== '') {
            if (!$is_custom_allowed) {
                throw new RuntimeException('Custom services are not available for this shop.');
            }
            if (mb_strlen($custom_service) < 3 || mb_strlen($custom_service) > 60) {
                throw new RuntimeException('Custom service name must be between 3 and 60 characters.');
            }
            $service_type = $custom_service;
        }

        if (!$service_type) {
            throw new RuntimeException('Please select a service type.');
        }

        if (!$is_custom_allowed && !in_array($service_type, $enabled_services, true)) {
            throw new RuntimeException('Selected service is not available for this shop.');
        }

        if ($quantity <= 0 || $quantity > 1000) {
            throw new RuntimeException('Quantity must be between 1 and 1000.');
        }

        $design_error = validate_design_description($design_description);
        if ($design_error !== '') {
            throw new RuntimeException($design_error);
        }

        $design_version = null;
        if ($design_version_id > 0) {
            if (isset($_FILES['design_file']) && $_FILES['design_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Please choose either a saved design version or upload a file, not both.');
            }
            $design_version_stmt = $pdo->prepare("
                SELECT dv.id, dv.preview_file
                FROM design_versions dv
                INNER JOIN design_projects dp ON dv.project_id = dp.id
                WHERE dv.id = ? AND dp.client_id = ?
            ");
            $design_version_stmt->execute([$design_version_id, $client_id]);
            $design_version = $design_version_stmt->fetch();
            if (!$design_version) {
                throw new RuntimeException('Selected design version is not available.');
            }
        }

        if (!is_shop_open($shop_policy)) {
            throw new RuntimeException('Selected shop is currently closed and cannot accept new orders.');
        }

        $pricing_settings = resolve_pricing_settings($shop_policy, $default_pricing_settings);
        $base_prices = $pricing_settings['base_prices'] ?? [];
        $add_on_fees = $pricing_settings['add_ons'] ?? [];
        $complexity_multipliers = $pricing_settings['complexity_multipliers'] ?? [];
        $rush_fee_percent = (float) ($pricing_settings['rush_fee_percent'] ?? 0);

        $base_price = (float) ($base_prices[$service_type] ?? ($base_prices['Custom'] ?? 0));
        $selected_add_ons = array_values(array_intersect($requested_add_ons, array_keys($add_on_fees)));
        $add_on_total = 0.0;
        foreach ($selected_add_ons as $addon) {
            $add_on_total += (float) ($add_on_fees[$addon] ?? 0);
        }
        $complexity_multiplier = (float) ($complexity_multipliers[$complexity_level] ?? 1);
        $rush_multiplier = $rush_requested ? 1 + ($rush_fee_percent / 100) : 1;
        $estimated_unit_price = ($base_price + $add_on_total) * $complexity_multiplier * $rush_multiplier;
        $estimated_total = $estimated_unit_price * (int) $quantity;
        $quote_details = [
            'service_type' => $service_type,
            'complexity' => $complexity_level,
            'add_ons' => $selected_add_ons,
            'rush' => $rush_requested,
            'breakdown' => [
                'base_price' => round($base_price, 2),
                'add_on_total' => round($add_on_total, 2),
                'complexity_multiplier' => round($complexity_multiplier, 2),
                'rush_fee_percent' => $rush_requested ? round($rush_fee_percent, 2) : 0,
            ],
            'estimated_unit_price' => round($estimated_unit_price, 2),
            'estimated_total' => round($estimated_total, 2),
        ];
        $quote_details_json = json_encode($quote_details);
        $design_file = $design_version['preview_file'] ?? null;

        // Start transaction
        $pdo->beginTransaction();
        
        // Insert order
        $order_stmt = $pdo->prepare("
            INSERT INTO orders (order_number, client_id, shop_id, service_type, design_description, 
                                quantity, price, client_notes, quote_details, design_file, design_version_id, status) 
            VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, 'pending')
        ");
        
        $order_stmt->execute([
            $order_number,
            $client_id,
            $shop_id,
            $service_type,
            $design_description,
            $quantity,
            $client_notes,
            $quote_details_json,
            $design_file,
            $design_version_id > 0 ? $design_version_id : null
        ]);
        
        $order_id = $pdo->lastInsertId();
        
        // Handle file upload
        if ($design_version_id <= 0 && isset($_FILES['design_file']) && $_FILES['design_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['design_file'];
            $allowed_extensions = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOC_TYPES);
$upload = save_uploaded_media(
                $file,
                $allowed_extensions,
                MAX_FILE_SIZE,
                'designs',
                'design',
                (string) $order_id
            );
            if (!$upload['success']) {
                throw new RuntimeException($upload['error'] === 'File size exceeds the limit.'
                    ? 'Design file size exceeds the ' . $max_upload_mb . 'MB limit.'
                    : 'Design file must be a JPG, PNG, GIF, PDF, DOC, or DOCX file.');
            }

            $update_stmt = $pdo->prepare("UPDATE orders SET design_file = ? WHERE id = ?");
            $update_stmt->execute([$upload['filename'], $order_id]);
        }
        
        // Update shop statistics
        $shop_stmt = $pdo->prepare("UPDATE shops SET total_orders = total_orders + 1 WHERE id = ?");
        $shop_stmt->execute([$shop_id]);
        
        create_notification(
            $pdo,
            $client_id,
            $order_id,
            'info',
            'Your order #' . $order_number . ' has been submitted and is awaiting shop acceptance.'
        );
        create_notification(
            $pdo,
            (int) $shop_policy['owner_id'],
            $order_id,
            'order_status',
            'New order #' . $order_number . ' has been placed and is awaiting your review.'
        );

        cleanup_media($pdo);

        $pdo->commit();
        
        log_audit(
            $pdo,
            $client_id,
            $_SESSION['user']['role'] ?? 'client',
            'place_order',
            'orders',
            (int) $order_id,
            [],
            [
                'order_number' => $order_number,
                'status' => 'pending',
                'estimated_total' => $quote_details['estimated_total'] ?? null,
            ]
        );
        $success = "Order placed successfully! Your order number is: <strong>$order_number</strong>";
        
    } catch(RuntimeException $e) {
        $error = $e->getMessage();
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error = "Failed to place order: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Place Order - Embroidery Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .shop-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .shop-card:hover {
            border-color: #4361ee;
            background: #f8f9ff;
        }
        .shop-card.selected {
            border-color: #4361ee;
            background: #4361ee;
            color: white;
        }
        .service-option {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            cursor: pointer;
        }
        .service-option:hover {
            border-color: #4361ee;
        }
        .service-option.selected {
            border-color: #4361ee;
            background: #f8f9ff;
        }
        .portfolio-strip {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .portfolio-strip img {
            width: 72px;
            height: 72px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .ranking-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .ranking-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 6px 12px;
            margin-top: 8px;
            font-size: 0.75rem;
            color: #475569;
        }
        .shop-card.selected .ranking-badge {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
        }
        .shop-card.selected .ranking-meta {
            color: rgba(255, 255, 255, 0.8);
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
                    <a href="#" class="nav-link dropdown-toggle active">
                        <i class="fas fa-clipboard-list"></i> Orders
                    </a>
                    <div class="dropdown-menu">
                        <a href="place_order.php" class="dropdown-item active"><i class="fas fa-plus-circle"></i> Place Order</a>
                        <a href="track_order.php" class="dropdown-item"><i class="fas fa-route"></i> Track Orders</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-layer-group"></i> Services
                    </a>
                    <div class="dropdown-menu">
                        <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                        <a href="search_discovery.php" class="dropdown-item"><i class="fas fa-compass"></i> Search &amp; Discovery</a>
                        <a href="design_proofing.php" class="dropdown-item"><i class="fas fa-clipboard-check"></i> Design Proofing &amp; Approval</a>
                        <a href="pricing_quotation.php" class="dropdown-item"><i class="fas fa-calculator"></i> Pricing &amp; Quotation</a>
                        <a href="order_management.php" class="dropdown-item"><i class="fas fa-clipboard-list"></i> Order Management</a>
                        <a href="payment_handling.php" class="dropdown-item"><i class="fas fa-hand-holding-dollar"></i> Payment Handling &amp; Release</a>
                        <a href="client_posting_community.php" class="dropdown-item"><i class="fas fa-comments"></i> Client Posting &amp; Community</a>
                    </div>
                </li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="notifications.php" class="nav-link">Notifications
                    <?php if($unread_notifications > 0): ?>
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
        <div class="dashboard-header">
            <h2>Place New Order</h2>
            <p class="text-muted">Fill in the details below to place your embroidery order</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
                <div class="mt-3">
                    <a href="track_order.php" class="btn btn-primary">Track Order</a>
                    <a href="dashboard.php" class="btn btn-outline-primary">Back to Dashboard</a>
                </div>
            </div>
        <?php else: ?>
            <form method="GET" class="mb-3">
            <div class="row" style="display: flex; gap: 12px; flex-wrap: wrap;">
                <div class="form-group" style="flex: 2; min-width: 220px;">
                    <label>Search shops</label>
                    <input type="text" name="search" class="form-control" placeholder="Search by name, description, or address" value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
                <div class="form-group" style="flex: 1; min-width: 180px;">
                    <label>Filter by service</label>
                    <select name="service" class="form-control">
                        <option value="">All services</option>
                        <?php foreach ($available_services as $service): ?>
                            <option value="<?php echo htmlspecialchars($service); ?>" <?php echo $service_filter === $service ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($service); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="flex: 1; min-width: 160px;">
                    <label>Availability</label>
                    <select name="open" class="form-control">
                        <option value="">All shops</option>
                        <option value="1" <?php echo $open_filter === '1' ? 'selected' : ''; ?>>Open now</option>
                    </select>
                </div>
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </div>
        </form>
        <form method="POST" enctype="multipart/form-data" id="orderForm">
            <?php echo csrf_field(); ?>
            <!-- Step 1: Select Shop -->
            <div class="card mb-4">
                <h3>Step 1: Select Service Provider</h3>
                <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                    <?php if (empty($shops)): ?>
                        <div class="text-muted">No shops match the current filters. Try broadening your search.</div>
                    <?php endif; ?>
                    <?php foreach($shops as $index => $shop): ?>
                    <div class="shop-card" onclick="selectShop(<?php echo $shop['id']; ?>)"
                         data-services="<?php echo htmlspecialchars(json_encode($shop['service_list']), ENT_QUOTES, 'UTF-8'); ?>"
                         data-pricing="<?php echo htmlspecialchars(json_encode($shop['pricing_settings']), ENT_QUOTES, 'UTF-8'); ?>"
                         data-is-open="<?php echo $shop['is_open'] ? '1' : '0'; ?>"
                         id="shop-<?php echo $shop['id']; ?>">
                        <div class="d-flex align-center">
                            <?php if($shop['logo']): ?>
                                <img src="../assets/uploads/logos/<?php echo $shop['logo']; ?>" 
                                     style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; margin-right: 15px;">
                            <?php else: ?>
                                <div style="width: 60px; height: 60px; background: #f0f0f0; border-radius: 50%; 
                                            display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                                    <i class="fas fa-store fa-2x text-muted"></i>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h5 class="mb-1"><?php echo htmlspecialchars($shop['shop_name']); ?></h5>
                                <div class="mb-1">
                                    <?php for($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star<?php echo $i <= $shop['rating'] ? ' text-warning' : ' text-muted'; ?>"></i>
                                    <?php endfor; ?>
                                    <small>(<?php echo $shop['total_orders']; ?> orders)</small>
                                </div>
                                <div class="ranking-badge">
                                    <i class="fas fa-chart-line"></i>
                                    <span>Recommendation: <?php echo number_format($shop['ranking_score'], 1); ?>%</span>
                                    <?php if ($index === 0): ?>
                                        <span>• Top pick</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-muted small mb-0"><?php echo substr($shop['shop_description'], 0, 100); ?>...</p>
                                <div class="text-muted small">
                                    <strong>Status:</strong>
                                    <?php if ($shop['is_open']): ?>
                                        <span class="text-success">Open</span>
                                    <?php else: ?>
                                        <span class="text-danger">Closed</span>
                                    <?php endif; ?>
                                    <span> • Hours: <?php echo htmlspecialchars($shop['opening_time'] ? substr($shop['opening_time'], 0, 5) : '08:00'); ?>-<?php echo htmlspecialchars($shop['closing_time'] ? substr($shop['closing_time'], 0, 5) : '18:00'); ?></span>
                                </div>
                                <div class="text-muted small">
                                    <strong>Capacity:</strong>
                                    <?php if ($shop['capacity'] > 0): ?>
                                        <?php echo $shop['active_orders']; ?> / <?php echo $shop['capacity']; ?> active
                                    <?php else: ?>
                                        Not set
                                    <?php endif; ?>
                                </div>
                                <div class="ranking-meta">
                                    <span>Distance: <?php echo $shop['ranking_breakdown']['distance']; ?>%</span>
                                    <span>Ratings: <?php echo $shop['ranking_breakdown']['rating']; ?>%</span>
                                    <span>Capacity: <?php echo $shop['ranking_breakdown']['capacity']; ?>%</span>
                                    <span>Reliability: <?php echo $shop['ranking_breakdown']['reliability']; ?>%</span>
                                    <span>Pricing: <?php echo $shop['ranking_breakdown']['pricing']; ?>%</span>
                                    <span>Specialization: <?php echo $shop['ranking_breakdown']['specialization']; ?>%</span>
                                </div>
                                <?php if (!empty($shop['portfolio_samples'])): ?>
                                    <div class="portfolio-strip">
                                        <?php foreach ($shop['portfolio_samples'] as $sample): ?>
                                            <img src="../assets/uploads/<?php echo htmlspecialchars($sample['image_path']); ?>" alt="<?php echo htmlspecialchars($sample['title']); ?>">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <input type="radio" name="shop_id" value="<?php echo $shop['id']; ?>" 
                               style="display: none;" required>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 2: Service Type -->
            <div class="card mb-4">
                <h3>Step 2: Select Service Type</h3>
                <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                    <div class="service-option" data-service="T-shirt Embroidery" onclick="selectService('T-shirt Embroidery')">
                        <h5>T-shirt Embroidery</h5>
                        <p class="text-muted small">Custom embroidery on t-shirts</p>
                        <input type="radio" name="service_type" value="T-shirt Embroidery" style="display: none;">
                    </div>
                    
                    <div class="service-option" data-service="Logo Embroidery" onclick="selectService('Logo Embroidery')">
                        <h5>Logo Embroidery</h5>
                        <p class="text-muted small">Company logo on uniforms</p>
                        <input type="radio" name="service_type" value="Logo Embroidery" style="display: none;">
                    </div>
                    
                    <div class="service-option" data-service="Cap Embroidery" onclick="selectService('Cap Embroidery')">
                        <h5>Cap Embroidery</h5>
                        <p class="text-muted small">Custom embroidery on caps</p>
                        <input type="radio" name="service_type" value="Cap Embroidery" style="display: none;">
                    </div>
                    
                    <div class="service-option" data-service="Bag Embroidery" onclick="selectService('Bag Embroidery')">
                        <h5>Bag Embroidery</h5>
                        <p class="text-muted small">Embroidery on bags and backpacks</p>
                        <input type="radio" name="service_type" value="Bag Embroidery" style="display: none;">
                    </div>
                </div>
                
                <div class="form-group mt-3">
                    <label>Or specify custom service:</label>
                    <input type="text" name="custom_service" class="form-control" 
                           placeholder="Enter custom service type">
                    <small class="text-muted" id="customServiceHint">Custom services depend on the shop's availability.</small>
                </div>
            </div>

            <!-- Step 3: Quote Preferences -->
            <div class="card mb-4">
                <h3>Step 3: Quote Preferences</h3>
                <p class="text-muted">Help the shop prepare a structured quote. Final pricing will be confirmed by the shop.</p>
                <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                    <div class="form-group">
                        <label>Complexity Level</label>
                        <select name="complexity_level" class="form-control" id="complexitySelect">
                            <option value="Simple">Simple</option>
                            <option value="Standard" selected>Standard</option>
                            <option value="Complex">Complex</option>
                        </select>
                        <small class="text-muted" id="complexityHint">Select a shop to view multipliers.</small>
                    </div>
                    <div class="form-group">
                        <label>Rush Service</label>
                        <div class="d-flex align-center" style="gap: 8px;">
                            <input type="checkbox" id="rushService" name="rush_service" value="1">
                            <label for="rushService" class="mb-0">Request rush turnaround</label>
                        </div>
                        <small class="text-muted" id="rushHint">Select a shop to view rush fees.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label>Optional Add-ons</label>
                    <div id="addOnOptions" class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px;"></div>
                    <small class="text-muted" id="addOnHint">Select a shop to see available add-ons and fees.</small>
                </div>

                <div class="alert alert-info mt-3">
                    <strong>Estimated quote:</strong>
                    <span id="quoteEstimate">Select a shop and service to see estimates.</span>
                    <div class="text-muted small mt-2">Estimates are based on shop rules and quantity, and may change after review.</div>
                </div>
            </div>

            <!-- Step 4: Design Details -->
            <div class="card mb-4">
                <h3>Step 4: Design Details</h3>
                <div class="form-group">
                    <label>Design Description *</label>
                    <textarea name="design_description" class="form-control" rows="4" required
                              placeholder="Placement: (e.g., left chest)&#10;Size: (e.g., 3in x 2in)&#10;Colors/Thread: (e.g., navy + white)&#10;Fabric/Item: (e.g., cotton polo)&#10;Notes: (optional)"></textarea>
                    <small class="text-muted">Provide at least 30 characters with placement, size, and color details for consistent quoting.</small>
                </div>
                
                <div class="form-group">
                    <label>Saved Design Version (Optional)</label>
                    <select name="design_version_id" class="form-control" id="designVersionSelect">
                        <option value="0">Select a saved design version</option>
                        <?php foreach ($design_versions as $version): ?>
                            <option value="<?php echo (int) $version['id']; ?>" <?php echo $prefill_design_version_id === (int) $version['id'] ? 'selected' : ''; ?>>
                                <?php
                                    $title = $version['project_title'] ? $version['project_title'] : 'Design';
                                    $version_label = 'v' . (int) $version['version_no'];
                                    $date_label = date('M d, Y', strtotime($version['created_at']));
                                    echo htmlspecialchars($title . ' · ' . $version_label . ' · ' . $date_label);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Choose a saved design version or upload a new file below.</small>
                </div>
                
                <div class="form-group">
                    <label>Upload Design File (Optional)</label>
                    <input type="file" name="design_file" class="form-control" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                    <small class="text-muted">Accepted formats: JPG, PNG, GIF, PDF, DOC, DOCX (Max <?php echo $max_upload_mb; ?>MB)</small>
                </div>
                
                <div class="row" style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Quantity *</label>
                        <input type="number" name="quantity" class="form-control" value="1" min="1" required>
                    </div>
                    
                    <div class="form-group" style="flex: 2;">
                        <label>Additional Notes (Optional)</label>
                        <textarea name="client_notes" class="form-control" rows="2"
                                  placeholder="Any special instructions or requirements..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Step 5: Submit -->
            <div class="card mb-4">
                <h3>Step 5: Submit</h3>
                
                <div class="alert alert-info mt-3">
                    <h6><i class="fas fa-info-circle"></i> Important Notes:</h6>
                    <ul class="mb-0">
                        <li>Shop owner will set the estimated price after reviewing your order</li>
                        <li>The quote will follow the complexity, add-ons, and rush preferences you selected</li>
                        <li>You'll receive a confirmation email</li>
                        <li>Shop owner may contact you for clarifications</li>
                        <li>Payment details will be provided after order acceptance</li>
                    </ul>
                </div>
            </div>

            <div class="text-center">
                <button type="submit" name="place_order" class="btn btn-primary btn-lg">
                    <i class="fas fa-paper-plane"></i> Place Order Now
                </button>
                <a href="dashboard.php" class="btn btn-secondary btn-lg">Cancel</a>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <script>
        const pricingState = {
            base_prices: {},
            add_ons: {},
            complexity_multipliers: {},
            rush_fee_percent: 0
        };

        function formatCurrency(value) {
            return '₱' + Number(value || 0).toFixed(2);
        }

        function getSelectedService() {
            const selected = document.querySelector('input[name="service_type"]:checked');
            if (selected) {
                return selected.value;
            }
            const custom = document.querySelector('input[name="custom_service"]');
            return custom && custom.value.trim() ? custom.value.trim() : '';
        }

        function updateQuoteEstimate() {
            const quoteEstimate = document.getElementById('quoteEstimate');
            const service = getSelectedService();
            const quantity = parseInt(document.querySelector('input[name="quantity"]').value || '0', 10);
            if (!service || Object.keys(pricingState.base_prices).length === 0 || quantity <= 0) {
                quoteEstimate.textContent = 'Select a shop and service to see estimates.';
                return;
            }

            const basePrice = pricingState.base_prices[service] ?? pricingState.base_prices.Custom ?? 0;
            const addOns = Array.from(document.querySelectorAll('input[name="add_ons[]"]:checked'))
                .map((input) => input.value);
            const addOnTotal = addOns.reduce((sum, addon) => sum + (pricingState.add_ons[addon] || 0), 0);
            const complexity = document.getElementById('complexitySelect').value;
            const complexityMultiplier = pricingState.complexity_multipliers[complexity] ?? 1;
            const rushRequested = document.getElementById('rushService').checked;
            const rushMultiplier = rushRequested ? 1 + (pricingState.rush_fee_percent / 100) : 1;

            const unitEstimate = (Number(basePrice) + Number(addOnTotal)) * complexityMultiplier * rushMultiplier;
            const totalEstimate = unitEstimate * quantity;

            quoteEstimate.textContent = `${formatCurrency(unitEstimate)} per item • ${formatCurrency(totalEstimate)} total`;
        }

        function renderAddOns(addOns) {
            const container = document.getElementById('addOnOptions');
            container.innerHTML = '';
            const entries = Object.entries(addOns);
            if (!entries.length) {
                container.innerHTML = '<span class="text-muted">No add-ons configured for this shop.</span>';
                return;
            }
            entries.forEach(([name, fee]) => {
                const wrapper = document.createElement('label');
                wrapper.style.display = 'flex';
                wrapper.style.alignItems = 'center';
                wrapper.style.gap = '8px';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.name = 'add_ons[]';
                input.value = name;
                input.addEventListener('change', updateQuoteEstimate);
                const text = document.createElement('span');
                text.textContent = `${name} (+${formatCurrency(fee)})`;
                wrapper.appendChild(input);
                wrapper.appendChild(text);
                container.appendChild(wrapper);
            });
        }

        function updateComplexityOptions(multipliers) {
            const complexitySelect = document.getElementById('complexitySelect');
            Array.from(complexitySelect.options).forEach(option => {
                const multiplier = multipliers[option.value];
                option.textContent = multiplier ? `${option.value} (x${Number(multiplier).toFixed(2)})` : option.value;
            });
        }

        function setPricingState(pricing) {
            pricingState.base_prices = pricing.base_prices || {};
            pricingState.add_ons = pricing.add_ons || {};
            pricingState.complexity_multipliers = pricing.complexity_multipliers || {};
            pricingState.rush_fee_percent = Number(pricing.rush_fee_percent || 0);

            renderAddOns(pricingState.add_ons);
            updateComplexityOptions(pricingState.complexity_multipliers);

            document.getElementById('complexityHint').textContent = 'Multipliers shown for this shop.';
            document.getElementById('rushHint').textContent = pricingState.rush_fee_percent
                ? `Rush fee: +${pricingState.rush_fee_percent}%`
                : 'Rush service is not configured.';
            document.getElementById('addOnHint').textContent = 'Add-ons pulled from shop pricing rules.';

            updateQuoteEstimate();
        }

        // Shop selection
        function selectShop(shopId) {
            // Remove selected class from all shops
            document.querySelectorAll('.shop-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selected class to clicked shop
            const shopCard = document.getElementById('shop-' + shopId);
            shopCard.classList.add('selected');
            
            // Check the radio button
            const radio = shopCard.querySelector('input[type="radio"]');
            radio.checked = true;
            
            const services = JSON.parse(shopCard.dataset.services || '[]');
            const isOpen = shopCard.dataset.isOpen === '1';
            const customServiceInput = document.querySelector('input[name="custom_service"]');
            const customServiceHint = document.getElementById('customServiceHint');

            document.querySelectorAll('.service-option').forEach(option => {
                const serviceName = option.dataset.service;
                const enabled = isOpen && services.includes(serviceName);
                option.classList.toggle('disabled', !enabled);
                option.style.opacity = enabled ? '1' : '0.5';
                option.style.pointerEvents = enabled ? 'auto' : 'none';
                const radioInput = option.querySelector('input[type="radio"]');
                if (!enabled) {
                    radioInput.checked = false;
                    option.classList.remove('selected');
                }
            });

            const customAllowed = services.includes('Custom');
            customServiceInput.disabled = !customAllowed || !isOpen;
            if (!customAllowed || !isOpen) {
                customServiceInput.value = '';
            }
            customServiceHint.textContent = customAllowed
                ? (isOpen ? 'Custom services are available for this shop.' : 'This shop is closed right now.')
                : 'Custom services are not offered by this shop.';
                
            const pricing = JSON.parse(shopCard.dataset.pricing || '{}');
            setPricingState(pricing);
        }
        
        // Service selection
        function selectService(service) {
            // Remove selected class from all services
            document.querySelectorAll('.service-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add to clicked service
            event.currentTarget.classList.add('selected');
            
            // Check the radio button
            const radio = event.currentTarget.querySelector('input[type="radio"]');
            radio.checked = true;
            
            // Update custom service field
            document.querySelector('input[name="custom_service"]').value = service;
            updateQuoteEstimate();
        }

        document.querySelectorAll('.shop-card').forEach(card => {
            const services = card.dataset.services || '[]';
            card.dataset.services = services;
        });
        
        document.getElementById('complexitySelect').addEventListener('change', updateQuoteEstimate);
        document.getElementById('rushService').addEventListener('change', updateQuoteEstimate);
        document.querySelector('input[name="quantity"]').addEventListener('input', updateQuoteEstimate);
        document.querySelector('input[name="custom_service"]').addEventListener('input', updateQuoteEstimate);
    </script>
</body>
</html>