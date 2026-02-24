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
$client_payment_stmt = $pdo->prepare("SELECT email_verified, phone_verified FROM users WHERE id = ? LIMIT 1");
$client_payment_stmt->execute([$client_id]);
$client_payment_profile = $client_payment_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$can_use_gcash = (int) ($client_payment_profile['phone_verified'] ?? 0) === 1;
$can_use_card = (int) ($client_payment_profile['email_verified'] ?? 0) === 1;
$allowed_payment_methods = ['cod', 'pickup'];
if ($can_use_gcash) {
    $allowed_payment_methods[] = 'gcash';
}
if ($can_use_card) {
    $allowed_payment_methods[] = 'card';
}
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
$preselected_shop_id = (int) ($_GET['shop_id'] ?? 0);
$preselected_portfolio_id = (int) ($_GET['portfolio_id'] ?? 0);
$preselected_portfolio = null;
if ($preselected_shop_id > 0 && $preselected_portfolio_id > 0) {
    $portfolio_preselect_stmt = $pdo->prepare("
        SELECT p.id, p.shop_id, p.title, p.description, p.image_path, p.price, s.shop_name
        FROM shop_portfolio p
        INNER JOIN shops s ON s.id = p.shop_id
        WHERE p.id = ? AND p.shop_id = ?
        LIMIT 1
    ");
    $portfolio_preselect_stmt->execute([$preselected_portfolio_id, $preselected_shop_id]);
    $preselected_portfolio = $portfolio_preselect_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$is_sample_order = $preselected_portfolio !== null;

// Place order
if(isset($_POST['place_order'])) {
    $shop_id = (int) ($_POST['shop_id'] ?? 0);
     $selected_portfolio_id = (int) ($_POST['selected_portfolio_id'] ?? 0);
    $service_type = sanitize($_POST['service_type'] ?? '');
    $custom_service = sanitize($_POST['custom_service'] ?? '');
    $design_description = '';
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $client_notes = sanitize($_POST['client_notes'] ?? '');
    $payment_method = sanitize($_POST['payment_method'] ?? '');
    $product_font = sanitize($_POST['product_font'] ?? '');
    $product_size = sanitize($_POST['product_size'] ?? '');
    $product_front_text = sanitize($_POST['product_front_text'] ?? '');
    $product_back_text = sanitize($_POST['product_back_text'] ?? '');
    $complexity_level = 'Standard';
    $requested_add_ons = $_POST['add_ons'] ?? [];
     $tshirt_fields = [
        'shirt_type' => sanitize($_POST['shirt_type'] ?? ''),
        'embroidery_placement' => sanitize($_POST['embroidery_placement'] ?? ''),
        'embroidery_size' => sanitize($_POST['embroidery_size'] ?? ''),
        'embroidery_font' => sanitize($_POST['embroidery_font'] ?? ''),
        'thread_type' => sanitize($_POST['thread_type'] ?? ''),
        'thread_color' => sanitize($_POST['thread_color'] ?? ''),
    ];
    $logo_fields = [
        'logo_size_cm' => sanitize($_POST['logo_size_cm'] ?? ''),
        'logo_design_type' => sanitize($_POST['logo_design_type'] ?? ''),
        'logo_digitizing' => sanitize($_POST['logo_digitizing'] ?? ''),
    ];

    $cap_fields = [
        'cap_type' => sanitize($_POST['cap_type'] ?? ''),
        'cap_embroidery_placement' => sanitize($_POST['cap_embroidery_placement'] ?? ''),
        'cap_embroidery_size' => sanitize($_POST['cap_embroidery_size'] ?? ''),
        'cap_embroidery_style' => sanitize($_POST['cap_embroidery_style'] ?? ''),
        'cap_thread_colors' => sanitize($_POST['cap_thread_colors'] ?? ''),
    ];
    $bag_fields = [
        'bag_embroidery_placement' => sanitize($_POST['bag_embroidery_placement'] ?? ''),
        'bag_embroidery_size' => sanitize($_POST['bag_embroidery_size'] ?? ''),
        'bag_embroidery_style' => sanitize($_POST['bag_embroidery_style'] ?? ''),
        'bag_thread_colors' => sanitize($_POST['bag_thread_colors'] ?? ''),
    ];
    if (!is_array($requested_add_ons)) {
        $requested_add_ons = [];
    }
    $sample_canvas_type = sanitize($_POST['sample_canvas_type'] ?? '');
    $sample_embroidery_detail = sanitize($_POST['sample_embroidery_detail'] ?? '');
    $sample_size_selection = sanitize($_POST['sample_size_selection'] ?? '');
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
        $is_sample_order = $selected_portfolio_id > 0;
        if ($is_sample_order) {
            $service_type = 'Posted Work Sample';
            $custom_service = '';
        }
        $is_custom_allowed = in_array('Custom', $enabled_services, true);
        if (!$is_sample_order && $custom_service !== '') {
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

        if (!in_array($payment_method, $allowed_payment_methods, true)) {
            throw new RuntimeException('Selected payment method is not available in your account. Use Cash on Delivery (COD) or Pickup Pay, or verify your contact details in your profile first.');
        }

        if ($selected_portfolio_id > 0) {
            $selected_portfolio_stmt = $pdo->prepare("
                SELECT title, description
                FROM shop_portfolio
                WHERE id = ? AND shop_id = ?
                LIMIT 1
            ");
            $selected_portfolio_stmt->execute([$selected_portfolio_id, $shop_id]);
            $selected_portfolio = $selected_portfolio_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$selected_portfolio) {
                throw new RuntimeException('Selected posted work is no longer available for this shop.');
            }
            $design_description = 'Selected posted work: ' . $selected_portfolio['title'];
            if (!empty($selected_portfolio['description'])) {
                $design_description .= ' - ' . $selected_portfolio['description'];
            }
            if ($is_sample_order) {
                if ($sample_canvas_type === '') {
                    throw new RuntimeException('Please select a canvas type for the sample order.');
                }
                if ($sample_embroidery_detail === '') {
                    throw new RuntimeException('Please select an embroidery detail for the sample order.');
                }
                if ($sample_size_selection === '') {
                    throw new RuntimeException('Please select a size for the sample order.');
                }
                $design_description = trim($design_description . ' | Canvas Type: ' . $sample_canvas_type . ' | Embroidery Detail: ' . $sample_embroidery_detail . ' | Size: ' . $sample_size_selection);
            }
        }

        if (!$is_sample_order && !$is_custom_allowed && !in_array($service_type, $enabled_services, true)) {
            throw new RuntimeException('Selected service is not available for this shop.');
        }

        if ($selected_portfolio_id > 0 && !$is_sample_order) {
            if ($product_font === '') {
                throw new RuntimeException('Please choose a font option for the selected product.');
            }
            if ($product_size === '') {
                throw new RuntimeException('Please choose a size option for the selected product.');
            }
            if ($product_front_text === '' || mb_strlen($product_front_text) > 80) {
                throw new RuntimeException('Front text is required and must be 80 characters or less.');
            }
            if (mb_strlen($product_back_text) > 80) {
                throw new RuntimeException('Back text must be 80 characters or less.');
            }
            $design_description = trim($design_description . ' | Font: ' . $product_font . ' | Size: ' . $product_size . ' | Front Text: ' . $product_front_text . ($product_back_text !== '' ? ' | Back Text: ' . $product_back_text : ''));
        }


        if ($quantity <= 0 || $quantity > 1000) {
            throw new RuntimeException('Quantity must be between 1 and 1000.');
        }

        if (!is_shop_open($shop_policy)) {
            throw new RuntimeException('Selected shop is currently closed and cannot accept new orders.');
        }

        $pricing_settings = resolve_pricing_settings($shop_policy, $default_pricing_settings);
        $base_prices = $pricing_settings['base_prices'] ?? [];
        $add_on_fees = $pricing_settings['add_ons'] ?? [];
        $complexity_multipliers = $pricing_settings['complexity_multipliers'] ?? [];
        $rush_fee_percent = (float) ($pricing_settings['rush_fee_percent'] ?? 0);

        $product_price = (float) ($_POST['product_price'] ?? 0);
        if ($product_price < 0) {
            $product_price = 0;
        }
        $service_type_price = $is_sample_order ? 0.0 : (float) ($base_prices[$service_type] ?? ($base_prices['Custom'] ?? 0));
        $base_price = $service_type_price;
        $selected_add_ons = $is_sample_order ? [] : array_values(array_intersect($requested_add_ons, array_keys($add_on_fees)));
        $add_on_total = 0.0;
        foreach ($selected_add_ons as $addon) {
            $add_on_total += (float) ($add_on_fees[$addon] ?? 0);
        }
        $subtotal = $product_price + $service_type_price + $add_on_total;
        if ($is_sample_order) {
            $rush_requested = false;
        }
        $rush_fee_amount = $rush_requested ? ($subtotal * ($rush_fee_percent / 100)) : 0;
        $estimated_unit_price = $subtotal + $rush_fee_amount;
        $estimated_total = $estimated_unit_price * $quantity;
        $quote_details = [
            'service_type' => $service_type,
            'payment_method' => $payment_method,
            'complexity' => $complexity_level,
            'add_ons' => $selected_add_ons,
            'rush' => $rush_requested,
            'tshirt_details' => $service_type === 'T-shirt Embroidery' ? $tshirt_fields : new stdClass(),
            'logo_details' => $service_type === 'Logo Embroidery' ? $logo_fields : new stdClass(),
            'cap_details' => $service_type === 'Cap Embroidery' ? $cap_fields : new stdClass(),
            'bag_details' => $service_type === 'Bag Embroidery' ? $bag_fields : new stdClass(),
            'breakdown' => [
                'base_price' => round($base_price, 2),
                'add_on_total' => round($add_on_total, 2),
                'complexity_multiplier' => round((float) ($complexity_multipliers[$complexity_level] ?? 1), 2),
                'rush_fee_percent' => $rush_requested ? round($rush_fee_percent, 2) : 0,
                'rush_fee_amount' => round($rush_fee_amount, 2),
            ],
            'estimated_unit_price' => round($estimated_unit_price, 2),
            'estimated_total' => round($estimated_total, 2),
            'selected_portfolio_id' => $selected_portfolio_id > 0 ? $selected_portfolio_id : null,
            'sample_order' => $is_sample_order,
            'sample_order_details' => $is_sample_order ? [
                'canvas_type' => $sample_canvas_type,
                'embroidery_detail' => $sample_embroidery_detail,
                'size_selection' => $sample_size_selection,
            ] : new stdClass(),
        ];
        $quote_details_json = json_encode($quote_details);
        $design_file = null;

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
            null
        ]);
        
        $order_id = $pdo->lastInsertId();
        
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
        
        if ($selected_portfolio_id > 0) {
            header('Location: order_receipt.php?order_id=' . (int) $order_id);
            exit;
        }

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
        
        .payment-method-option {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .payment-method-option:hover {
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
         .selection-group {
            margin-bottom: 14px;
        }
        .selection-label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .selection-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .selection-btn {
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            background: #fff;
            color: #334155;
            padding: 6px 14px;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .selection-btn:hover {
            border-color: #4361ee;
        }
        .selection-btn.selected {
            background: #4361ee;
            border-color: #4361ee;
            color: #fff;
        }
        .sample-preview {
            display: grid;
            grid-template-columns: minmax(140px, 220px) 1fr;
            gap: 14px;
            align-items: start;
        }
        .sample-preview img {
            width: 100%;
            border-radius: 10px;
            border: 1px solid #dbe3f0;
            object-fit: cover;
            background: #f8fafc;
        }
        .sample-selection-summary {
            margin-top: 12px;
            border: 1px solid #dbeafe;
            border-radius: 10px;
            padding: 12px;
            background: #f0f9ff;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

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
         <form method="POST" id="orderForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="product_price" id="productPrice" value="<?php echo $preselected_portfolio ? (float) $preselected_portfolio['price'] : 0; ?>">
             <input type="hidden" name="selected_portfolio_id" id="selectedPortfolioId" value="<?php echo $preselected_portfolio ? (int) $preselected_portfolio['id'] : 0; ?>">
            <?php if ($preselected_portfolio): ?>
                <div class="alert alert-info mb-3" id="selectedPostBanner" data-title="<?php echo htmlspecialchars($preselected_portfolio['title']); ?>" data-price="<?php echo (float) ($preselected_portfolio['price'] ?? 0); ?>">
                    <strong>Selected posted work:</strong> <?php echo htmlspecialchars($preselected_portfolio['title']); ?>
                     <div class="small mt-1"><strong>Shop:</strong> <?php echo htmlspecialchars($preselected_portfolio['shop_name']); ?></div>
                      <div class="small mt-1"><strong>Portfolio price:</strong> ₱<?php echo number_format((float) ($preselected_portfolio['price'] ?? 0), 2); ?></div>
                    <?php if (!empty($preselected_portfolio['description'])): ?>
                        <div class="small text-muted mt-1"><?php echo nl2br(htmlspecialchars($preselected_portfolio['description'])); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($is_sample_order): ?>
                <div class="alert alert-success mb-3">
                    <strong>Sample checkout mode:</strong> This order uses the exact posted work details. Service type, quote preferences, and extra design setup are skipped.
                </div>
            <?php endif; ?>

            <?php if ($is_sample_order): ?>
            <div class="card mb-4">
                <h3>Step 1: Shop for This Sample Order</h3>
                <p class="text-muted mb-2">This sample order is locked to the selected posted work and shop.</p>
                <input type="hidden" name="shop_id" value="<?php echo (int) $preselected_shop_id; ?>">
                <div class="alert alert-secondary mb-0">
                    <div><strong>Shop:</strong> <?php echo htmlspecialchars($preselected_portfolio['shop_name'] ?? 'Selected shop'); ?></div>
                    <div><strong>Posted work:</strong> <?php echo htmlspecialchars($preselected_portfolio['title'] ?? 'Selected sample'); ?></div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($is_sample_order): ?>
            <div class="card mb-4">
                <h3>Step 2: Sample Order Details</h3>
                <p class="text-muted">Review the posted work and complete your sample preferences before checkout.</p>
                <div class="sample-preview">
                    <div>
                        <?php if (!empty($preselected_portfolio['image_path'])): ?>
                            <img src="../assets/uploads/<?php echo htmlspecialchars($preselected_portfolio['image_path']); ?>" alt="<?php echo htmlspecialchars($preselected_portfolio['title']); ?>">
                        <?php else: ?>
                            <div class="text-muted small">No photo available.</div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h4 class="mb-1"><?php echo htmlspecialchars($preselected_portfolio['title']); ?></h4>
                        <?php if (!empty($preselected_portfolio['description'])): ?>
                            <p class="text-muted mb-2"><?php echo nl2br(htmlspecialchars($preselected_portfolio['description'])); ?></p>
                        <?php endif; ?>
                        <div class="small"><strong>Canvas Type</strong>: <span id="sampleCanvasSummary">Not selected yet</span></div>
                        <div class="small mt-1"><strong>Embroidery Detail</strong>: <span id="sampleEmbroiderySummary">Not selected yet</span></div>
                        <div class="small mt-1"><strong>Size</strong>: <span id="sampleSizeSummary">Not selected yet</span></div>
                        <div class="small mt-1"><strong>Quantity</strong>: <span id="sampleQuantitySummary">1</span></div>
                    </div>
                </div>

                <div class="row mt-3" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                    <div class="form-group mb-0">
                        <label>Canvas Type *</label>
                        <select name="sample_canvas_type" id="sampleCanvasType" class="form-control" required>
                            <option value="">Select canvas type</option>
                            <option value="Cotton Twill">Cotton Twill</option>
                            <option value="Linen">Linen</option>
                            <option value="Polyester Blend">Polyester Blend</option>
                            <option value="Denim">Denim</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label>Embroidery Detail *</label>
                        <select name="sample_embroidery_detail" id="sampleEmbroideryDetail" class="form-control" required>
                            <option value="">Select embroidery detail</option>
                            <option value="Flat Stitch">Flat Stitch</option>
                            <option value="Satin Stitch">Satin Stitch</option>
                            <option value="3D Puff">3D Puff</option>
                            <option value="Applique">Applique</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label>Size Selection *</label>
                        <select name="sample_size_selection" id="sampleSizeSelection" class="form-control" required>
                            <option value="">Select size</option>
                            <option value="Small (5x3 cm)">Small (5x3 cm)</option>
                            <option value="Medium (5x5 cm)">Medium (5x5 cm)</option>
                            <option value="Large (8x5 cm)">Large (8x5 cm)</option>
                        </select>
                    </div>
                </div>
                <div class="row mt-2" style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 180px;">
                        <label>Quantity Selection *</label>
                        <input type="number" name="quantity" id="sampleQuantityInput" class="form-control" value="1" min="1" required>
                    </div>
                    <div class="form-group" style="flex: 2; min-width: 240px;">
                        <label>Additional Notes (Optional)</label>
                        <textarea name="client_notes" class="form-control" rows="2" placeholder="Any special instructions or requirements..."></textarea>
                    </div>
                </div>
                <div class="sample-selection-summary small text-muted" id="sampleSelectionSummary">
                    Please complete canvas type, embroidery detail, size, and quantity.
                </div>
            </div>
            <?php else: ?>
            <!-- Step 1: Select Shop -->
            <div class="card mb-4">
                <h3>Step 1: Select Service Provider</h3>
                 <div class="d-flex justify-content-between align-items-center mb-2" style="gap: 12px; flex-wrap: wrap;">
                    <small id="selectionSummary" class="text-muted">No shop and order selected yet.</small>
                    <button type="button" id="changeShopBtn" class="btn btn-sm btn-outline-secondary" style="display: none;">Change shop</button>
                </div>
                <div class="row" id="shopGrid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                    <?php if (empty($shops)): ?>
                        <div class="text-muted">No shops match the current filters. Try broadening your search.</div>
                    <?php endif; ?>
                    <?php foreach($shops as $index => $shop): ?>
                     <?php $is_selected = $preselected_shop_id > 0 && (int) $shop['id'] === $preselected_shop_id; ?>
                    <div class="shop-card<?php echo $is_selected ? ' selected' : ''; ?>" onclick="selectShop(<?php echo $shop['id']; ?>)"
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
                                 <?php if ($preselected_portfolio && (int) $shop['id'] === (int) $preselected_portfolio['shop_id']): ?>
                                    <div class="portfolio-strip" title="Selected posted work">
                                        <?php if (!empty($preselected_portfolio['image_path'])): ?>
                                            <img src="../assets/uploads/<?php echo htmlspecialchars($preselected_portfolio['image_path']); ?>" alt="<?php echo htmlspecialchars($preselected_portfolio['title']); ?>">
                                        <?php endif; ?>
                                        <div class="small text-light">
                                            <strong><?php echo htmlspecialchars($preselected_portfolio['title']); ?></strong>
                                            <?php if (!empty($preselected_portfolio['description'])): ?>
                                                <div><?php echo htmlspecialchars($preselected_portfolio['description']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php elseif (!$preselected_portfolio && !empty($shop['portfolio_samples'])): ?>
                                    <div class="portfolio-strip">
                                        <?php foreach ($shop['portfolio_samples'] as $sample): ?>
                                            <img src="../assets/uploads/<?php echo htmlspecialchars($sample['image_path']); ?>" alt="<?php echo htmlspecialchars($sample['title']); ?>">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <input type="radio" name="shop_id" value="<?php echo $shop['id']; ?>" 
                         <?php echo $is_selected ? 'checked' : ''; ?>
                               style="display: none;" required>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$is_sample_order): ?>
            <!-- Step 2: Service Type -->
            <div class="card mb-4">
                <h3>Step 2: Select Service Type</h3>
                <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                    <div class="service-option" data-service="T-shirt Embroidery" onclick="selectService('T-shirt Embroidery', this)">
                        <h5>T-shirt Embroidery</h5>
                        <p class="text-muted small">Custom embroidery on t-shirts</p>
                        <input type="radio" name="service_type" value="T-shirt Embroidery" style="display: none;">
                    </div>
                    <div class="service-option" data-service="T-shirt Embroidery" onclick="selectService('T-shirt Embroidery', this)">
                        <h5>Logo Embroidery</h5>
                        <p class="text-muted small">Company logo on uniforms</p>
                        <input type="radio" name="service_type" value="Logo Embroidery" style="display: none;">
                    </div>
                    <div class="service-option" data-service="Cap Embroidery" onclick="selectService('Cap Embroidery', this)">
                        <h5>Cap Embroidery</h5>
                        <p class="text-muted small">Custom embroidery on caps</p>
                        <input type="radio" name="service_type" value="Cap Embroidery" style="display: none;">
                    </div>
                    <div class="service-option" data-service="Bag Embroidery" onclick="selectService('Bag Embroidery', this)">
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
            </div>

            <!-- Step 4: Design Details -->
            <div class="card mb-4">
                <h3>Step 4: Design Details</h3>
                <div id="tshirtDetailSelections" class="form-group" style="display: none;">
                    <label>T-shirt Embroidery Preferences</label>
                    <p class="text-muted small">Choose options below for a clearer specification.</p>

                    <div class="selection-group">
                        <span class="selection-label">Shirt Type</span>
                        <div class="selection-buttons" data-target-input="shirtTypeInput">
                            <button type="button" class="selection-btn" data-value="Round Neck">Round Neck</button>
                            <button type="button" class="selection-btn" data-value="Polo Shirt">Polo Shirt</button>
                            <button type="button" class="selection-btn" data-value="V-Neck">V-Neck</button>
                            <button type="button" class="selection-btn" data-value="Long Sleeve">Long Sleeve</button>
                            <button type="button" class="selection-btn" data-value="Dri-Fit">Dri-Fit</button>
                        </div>
                        <input type="hidden" name="shirt_type" id="shirtTypeInput" value="">
                    </div>

                    <div class="selection-group">
                        <span class="selection-label">Embroidery Placement</span>
                        <div class="selection-buttons" data-target-input="embroideryPlacementInput">
                            <button type="button" class="selection-btn" data-value="Left Chest">Left Chest</button>
                            <button type="button" class="selection-btn" data-value="Right Chest">Right Chest</button>
                            <button type="button" class="selection-btn" data-value="Center Chest">Center Chest</button>
                            <button type="button" class="selection-btn" data-value="Back">Back</button>
                            <button type="button" class="selection-btn" data-value="Sleeve">Sleeve</button>
                        </div>
                        <input type="hidden" name="embroidery_placement" id="embroideryPlacementInput" value="">
                    </div>

                    <div class="selection-group">
                        <span class="selection-label">Embroidery Size</span>
                        <div class="selection-buttons" data-target-input="embroiderySizeInput">
                            <button type="button" class="selection-btn" data-value="Small (2-3 in)">Small (5x3 cm)</button>
                            <button type="button" class="selection-btn" data-value="Medium (4-5 in)">Medium (5x5 cm)</button>
                            <button type="button" class="selection-btn" data-value="Large (6-8 in)">Large (8x3 cm)</button>
                        </div>
                        <input type="hidden" name="embroidery_size" id="embroiderySizeInput" value="">
                    </div>

                    <div class="selection-group">
                        <span class="selection-label">Embroidery Font</span>
                        <div class="selection-buttons" data-target-input="embroideryFontInput">
                            <button type="button" class="selection-btn" data-value="Block">Block</button>
                            <button type="button" class="selection-btn" data-value="Script">Script</button>
                            <button type="button" class="selection-btn" data-value="Serif">Serif</button>
                            <button type="button" class="selection-btn" data-value="Sans Serif">Sans Serif</button>
                            <button type="button" class="selection-btn" data-value="Custom">Custom</button>
                        </div>
                        <input type="hidden" name="embroidery_font" id="embroideryFontInput" value="">
                    </div>

                    <div class="selection-group">
                        <span class="selection-label">Thread Type</span>
                        <div class="selection-buttons" data-target-input="threadTypeInput">
                            <button type="button" class="selection-btn" data-value="Rayon">Rayon</button>
                            <button type="button" class="selection-btn" data-value="Polyester">Polyester</button>
                            <button type="button" class="selection-btn" data-value="Cotton">Cotton</button>
                            <button type="button" class="selection-btn" data-value="Metallic">Metallic</button>
                        </div>
                        <input type="hidden" name="thread_type" id="threadTypeInput" value="">
                    </div>

                    <div class="selection-group mb-0">
                        <span class="selection-label">Thread Color</span>
                        <div class="selection-buttons" data-target-input="threadColorInput">
                            <button type="button" class="selection-btn" data-value="Black">Black</button>
                            <button type="button" class="selection-btn" data-value="White">White</button>
                            <button type="button" class="selection-btn" data-value="Red">Red</button>
                            <button type="button" class="selection-btn" data-value="Blue">Blue</button>
                            <button type="button" class="selection-btn" data-value="Gold">Gold</button>
                            <button type="button" class="selection-btn" data-value="Custom">Custom</button>
                        </div>
                        <input type="hidden" name="thread_color" id="threadColorInput" value="">
                    </div>
                </div>
                 <div id="logoDetailSelections" class="form-group" style="display: none; margin-top: 16px;">
                    <label>Logo Embroidery Preferences</label>
                    <p class="text-muted small">Choose logo details so the shop can prepare an accurate quote.</p>

                    <div class="selection-group">
                        <span class="selection-label">Logo Size (cm)</span>
                        <div class="selection-buttons" data-target-input="logoSizeCmInput">
                            <button type="button" class="selection-btn" data-value="3x3 cm">3 x 3 cm</button>
                            <button type="button" class="selection-btn" data-value="5x5 cm">5 x 5 cm</button>
                            <button type="button" class="selection-btn" data-value="8x8 cm">8 x 8 cm</button>
                            <button type="button" class="selection-btn" data-value="10x10 cm">10 x 10 cm</button>
                        </div>
                        <input type="hidden" name="logo_size_cm" id="logoSizeCmInput" value="">
                    </div>

                    <div class="selection-group">
                        <span class="selection-label">Design Type</span>
                        <div class="selection-buttons" data-target-input="logoDesignTypeInput">
                            <button type="button" class="selection-btn" data-value="Plain">Plain</button>
                            <button type="button" class="selection-btn" data-value="Multi Color">Multi Color</button>
                        </div>
                        <input type="hidden" name="logo_design_type" id="logoDesignTypeInput" value="">
                    </div>

                    <div class="selection-group mb-0">
                        <span class="selection-label">Digitizing</span>
                        <div class="selection-buttons" data-target-input="logoDigitizingInput">
                            <button type="button" class="selection-btn" data-value="With Digitizing">With Digitizing</button>
                            <button type="button" class="selection-btn" data-value="No Digitizing">No Digitizing</button>
                        </div>
                        <input type="hidden" name="logo_digitizing" id="logoDigitizingInput" value="">
                    </div>
                </div>
                <div id="capDetailSelections" class="form-group" style="display: none; margin-top: 16px;">
                    <label>Cap Embroidery Preferences</label>
                    <p class="text-muted small">Choose cap details for clearer production instructions.</p>

                    <div class="selection-group">
                        <span class="selection-label">Cap Type</span>
                        <div class="selection-buttons" data-target-input="capTypeInput">
                            <button type="button" class="selection-btn" data-value="Baseball Cap">Baseball Cap</button>
                            <button type="button" class="selection-btn" data-value="Snapback Cap">Snapback Cap</button>
                            <button type="button" class="selection-btn" data-value="Dad Cap">Dad Cap</button>
                            <button type="button" class="selection-btn" data-value="No Cap">No Cap</button>
                        </div>
                        <input type="hidden" name="cap_type" id="capTypeInput" value="">
                    </div>

                    <div class="selection-group">
                        <span class="selection-label">Embroidery Placement</span>
                        <div class="selection-buttons" data-target-input="capEmbroideryPlacementInput">
                            <button type="button" class="selection-btn" data-value="Center">Center</button>
                            <button type="button" class="selection-btn" data-value="Front">Front</button>
                            <button type="button" class="selection-btn" data-value="Left">Left</button>
                            <button type="button" class="selection-btn" data-value="Right">Right</button>
                        </div>
                        <input type="hidden" name="cap_embroidery_placement" id="capEmbroideryPlacementInput" value="">
                    </div>

                    <div class="selection-group">
                        <span class="selection-label">Embroidery Size</span>
                        <div class="selection-buttons" data-target-input="capEmbroiderySizeInput">
                            <button type="button" class="selection-btn" data-value="Small (5x3 cm)">Small (5x3 cm)</button>
                            <button type="button" class="selection-btn" data-value="Medium (5x5 cm)">Medium (5x5 cm)</button>
                            <button type="button" class="selection-btn" data-value="Large (8x3 cm)">Large (8x3 cm)</button>
                        </div>
                        <input type="hidden" name="cap_embroidery_size" id="capEmbroiderySizeInput" value="">
                    </div>

                    <div class="selection-group">
                        <span class="selection-label">Embroidery Style</span>
                        <div class="selection-buttons" data-target-input="capEmbroideryStyleInput">
                            <button type="button" class="selection-btn" data-value="Block Stitch">Block Stitch</button>
                            <button type="button" class="selection-btn" data-value="Script Stitch">Script Stitch</button>
                            <button type="button" class="selection-btn" data-value="Satin Stitch">Satin Stitch</button>
                            <button type="button" class="selection-btn" data-value="Cursiv Stitch">Cursiv Stitch</button>
                            <button type="button" class="selection-btn" data-value="Serif Stitch">Serif Stitch</button>
                            <button type="button" class="selection-btn" data-value="Gothic Stitch">Gothic Stitch</button>
                        </div>
                        <input type="hidden" name="cap_embroidery_style" id="capEmbroideryStyleInput" value="">
                    </div>

                    <div class="selection-group mb-0">
                        <span class="selection-label">Thread Colors</span>
                        <div class="selection-buttons" data-target-input="capThreadColorsInput">
                            <button type="button" class="selection-btn" data-value="Black">Black</button>
                            <button type="button" class="selection-btn" data-value="White">White</button>
                            <button type="button" class="selection-btn" data-value="Red">Red</button>
                            <button type="button" class="selection-btn" data-value="Blue">Blue</button>
                            <button type="button" class="selection-btn" data-value="Gold">Gold</button>
                            <button type="button" class="selection-btn" data-value="Custom">Custom</button>
                        </div>
                        <input type="hidden" name="cap_thread_colors" id="capThreadColorsInput" value="">
                    </div>
                </div>
                <div id="bagDetailSelections" class="form-group" style="display: none; margin-top: 16px;">
                    <label>Bag Embroidery Preferences</label>
                    <p class="text-muted small">Choose bag details for clearer embroidery instructions.</p>

                    <div class="selection-group">
                        <span class="selection-label">Embroidery Placement</span>
                        <div class="selection-buttons" data-target-input="bagEmbroideryPlacementInput">
                            <button type="button" class="selection-btn" data-value="Upper Left">Upper Left</button>
                            <button type="button" class="selection-btn" data-value="Upper Right">Upper Right</button>
                            <button type="button" class="selection-btn" data-value="Center">Center</button>
                            <button type="button" class="selection-btn" data-value="Lower Left">Lower Left</button>
                            <button type="button" class="selection-btn" data-value="Lower Right">Lower Right</button>
                        </div>
                        <input type="hidden" name="bag_embroidery_placement" id="bagEmbroideryPlacementInput" value="">
                    </div>

                    <div class="selection-group">
                        <span class="selection-label">Embroidery Size</span>
                        <div class="selection-buttons" data-target-input="bagEmbroiderySizeInput">
                            <button type="button" class="selection-btn" data-value="5x3 cm">5x3 cm</button>
                            <button type="button" class="selection-btn" data-value="5x5 cm">5x5 cm</button>
                            <button type="button" class="selection-btn" data-value="8x3 cm">8x3 cm</button>
                            <button type="button" class="selection-btn" data-value="8x5 cm">8x5 cm</button>
                        </div>
                        <input type="hidden" name="bag_embroidery_size" id="bagEmbroiderySizeInput" value="">
                    </div>

                    <div class="selection-group">
                        <span class="selection-label">Embroidery Style</span>
                        <div class="selection-buttons" data-target-input="bagEmbroideryStyleInput">
                            <button type="button" class="selection-btn" data-value="Block Stitch">Block Stitch</button>
                            <button type="button" class="selection-btn" data-value="Script Stitch">Script Stitch</button>
                            <button type="button" class="selection-btn" data-value="Satin Stitch">Satin Stitch</button>
                            <button type="button" class="selection-btn" data-value="Cursiv Stitch">Cursiv Stitch</button>
                            <button type="button" class="selection-btn" data-value="Serif Stitch">Serif Stitch</button>
                            <button type="button" class="selection-btn" data-value="Gothic Stitch">Gothic Stitch</button>
                        </div>
                        <input type="hidden" name="bag_embroidery_style" id="bagEmbroideryStyleInput" value="">
                    </div>

                    <div class="selection-group mb-0">
                        <span class="selection-label">Thread Colors</span>
                        <div class="selection-buttons" data-target-input="bagThreadColorsInput">
                            <button type="button" class="selection-btn" data-value="Black">Black</button>
                            <button type="button" class="selection-btn" data-value="White">White</button>
                            <button type="button" class="selection-btn" data-value="Red">Red</button>
                            <button type="button" class="selection-btn" data-value="Blue">Blue</button>
                            <button type="button" class="selection-btn" data-value="Gold">Gold</button>
                            <button type="button" class="selection-btn" data-value="Custom">Custom</button>
                        </div>
                        <input type="hidden" name="bag_thread_colors" id="bagThreadColorsInput" value="">
                    </div>
                </div>
                 <div class="card" style="background: #f8fafc; border: 1px dashed #cbd5e1; margin-top: 1rem;">
                    <h4 class="mb-2"><i class="fas fa-sliders-h"></i> Available Product Design Setup</h4>
                    <p class="text-muted small mb-3">For posted products, select the set design options and provide front/back text.</p>
                    <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
                        <div class="form-group mb-0">
                            <label>Font *</label>
                            <select name="product_font" class="form-control">
                                <option value="">Select font</option>
                                <option value="Block">Block</option>
                                <option value="Script">Script</option>
                                <option value="Serif">Serif</option>
                                <option value="Sans Serif">Sans Serif</option>
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label>Size *</label>
                            <select name="product_size" class="form-control">
                                <option value="">Select size</option>
                                <option value="Small (5x3 cm)">Small (5x3 cm)</option>
                                <option value="Medium (5x5 cm)">Medium (5x5 cm)</option>
                                <option value="Large (8x3 cm)">Large (8x3 cm)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mt-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                        <div class="form-group mb-0">
                            <label>Front text *</label>
                            <input type="text" name="product_front_text" maxlength="80" class="form-control" placeholder="Text for front of canvas">
                        </div>
                        <div class="form-group mb-0">
                            <label>Back text (optional)</label>
                            <input type="text" name="product_back_text" maxlength="80" class="form-control" placeholder="Text for back of canvas">
                        </div>
                    </div>
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

            
            <?php endif; ?>

            <!-- Step 5: Payment & Delivery Address -->
            <div class="card mb-4">
                <h3><?php echo $is_sample_order ? 'Step 3: Payment & Delivery Address' : 'Step 5: Payment & Delivery Address'; ?></h3>
                <p class="text-muted">Choose how you want to pay for your order and review your default delivery address.</p>
                <div class="form-group">
                    <label>Payment Method *</label>
                    <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px;">
                        <label class="payment-method-option">
                            <input type="radio" name="payment_method" value="gcash" <?php echo $can_use_gcash ? 'required' : 'disabled'; ?>>
                            <span><strong>GCash</strong></span>
                            <?php if (!$can_use_gcash): ?>
                                <small class="text-muted d-block">Unavailable: verify your phone number first.</small>
                            <?php endif; ?>
                        </label>
                        <label class="payment-method-option">
                            <input type="radio" name="payment_method" value="card" <?php echo $can_use_card ? (!$can_use_gcash ? 'required' : '') : 'disabled'; ?>>
                            <span><strong>Visa / Mastercard</strong></span>
                            <?php if (!$can_use_card): ?>
                                <small class="text-muted d-block">Unavailable: verify your email first.</small>
                            <?php endif; ?>
                        </label>
                        <label class="payment-method-option">
                            <input type="radio" name="payment_method" value="cod" required>
                            <span><strong>Cash on Delivery (COD)</strong></span>
                        </label>
                        <label class="payment-method-option">
                            <input type="radio" name="payment_method" value="pickup" required>
                            <span><strong>Pickup</strong></span>
                        </label>
                    </div>
                    <?php if (!$can_use_gcash && !$can_use_card): ?>
                        <small class="text-muted d-block mt-2">GCash and Visa/Mastercard are unavailable because your payment profile is incomplete. You can continue using Cash on Delivery (COD) or Pickup Pay.</small>
                    <?php endif; ?>
                </div>
                <div class="form-group mb-0">
                    <label>Delivery Address</label>
                    <div class="d-flex align-center" style="gap: 10px; flex-wrap: wrap;">
                        <span class="text-muted">Use your default delivery address from your profile.</span>
                        <a href="customer_profile.php#delivery-address" class="btn btn-outline-primary btn-sm">Go to Default Delivery Address</a>
                    </div>
                </div>
            </div>

            <!-- Step 6: Submit -->
            <div class="card mb-4">
                <h3><?php echo $is_sample_order ? 'Step 4: Submit Sample Order' : 'Step 6: Submit'; ?></h3>
                <div class="alert alert-info mt-3">
                    <strong><?php echo $is_sample_order ? 'Price quotation:' : 'Estimated quote:'; ?></strong>
                    <span id="quoteEstimate">Select a shop and service to see estimates.</span>
                    <div class="text-muted small mt-2" id="selectedPortfolioPrice">Selected portfolio price: <?php echo $preselected_portfolio ? '₱' . number_format((float) ($preselected_portfolio['price'] ?? 0), 2) : '₱0.00'; ?></div>
                    <div class="mt-2 small" id="priceBreakdown" style="display: none;">
                        <div><strong>Price breakdown</strong></div>
                        <div id="priceBreakdownBase">Base service: ₱0.00</div>
                        <div id="priceBreakdownPortfolio">Portfolio product price: ₱0.00</div>
                        <div id="priceBreakdownAddOns">Add-ons: ₱0.00</div>
                        <div id="priceBreakdownRush">Rush fee: ₱0.00</div>
                        <div id="priceBreakdownQuantity">Quantity: 0</div>
                    </div>
                </div>
            

            <div class="text-center">
                <button type="submit" name="place_order" class="btn btn-primary btn-lg">
                    <i class="fas fa-paper-plane"></i> Place Order Now
                </button>
                <a href="dashboard.php" class="btn btn-secondary btn-lg">Cancel</a>
            </div>
        </form>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const pricingState = {
            base_prices: {},
            add_ons: {},
            complexity_multipliers: {},
            rush_fee_percent: 0
        };
        const isSampleOrder = <?php echo $is_sample_order ? 'true' : 'false'; ?>;

        function formatCurrency(value) {
            return '₱' + Number(value || 0).toFixed(2);
        }

        function getSelectedService() {
            if (isSampleOrder) {
                return 'Posted Work Sample';
            }
            const selected = document.querySelector('input[name="service_type"]:checked');
            if (selected) {
                return selected.value;
            }
            const custom = document.querySelector('input[name="custom_service"]');
            return custom && custom.value.trim() ? custom.value.trim() : '';
        }

          function resetSelectionGroup(container) {
            container.querySelectorAll('.selection-buttons').forEach(group => {
                const targetInput = document.getElementById(group.dataset.targetInput);
                if (targetInput) {
                    targetInput.value = '';
                }
                group.querySelectorAll('.selection-btn').forEach(btn => btn.classList.remove('selected'));
            });
        }

        function toggleDetailSelections() {
            if (isSampleOrder) {
                return;
            }
            const tshirtDetails = document.getElementById('tshirtDetailSelections');
            const logoDetails = document.getElementById('logoDetailSelections');
            const capDetails = document.getElementById('capDetailSelections');
            const bagDetails = document.getElementById('bagDetailSelections');
            const selectedService = getSelectedService();
            const isTshirt = selectedService === 'T-shirt Embroidery';
            const isLogo = selectedService === 'Logo Embroidery';
            const isCap = selectedService === 'Cap Embroidery';
            const isBag = selectedService === 'Bag Embroidery';

            tshirtDetails.style.display = isTshirt ? 'block' : 'none';
            logoDetails.style.display = isLogo ? 'block' : 'none';
            capDetails.style.display = isCap ? 'block' : 'none';
            bagDetails.style.display = isBag ? 'block' : 'none';

            if (!isTshirt) {
               resetSelectionGroup(tshirtDetails);
            }
            if (!isLogo) {
                resetSelectionGroup(logoDetails);
            }
            if (!isCap) {
                resetSelectionGroup(capDetails);
            }
            if (!isBag) {
                resetSelectionGroup(bagDetails);
            }
        }

        function updateQuoteEstimate() {
            const quoteEstimate = document.getElementById('quoteEstimate');
            const selectedPortfolioPrice = document.getElementById('selectedPortfolioPrice');
             const priceBreakdown = document.getElementById('priceBreakdown');
            const service = getSelectedService();
            const quantity = parseInt(document.querySelector('input[name="quantity"]').value || '0', 10);
            const productPriceInput = document.querySelector('input[name="product_price"]');
            const productPrice = Number(productPriceInput ? productPriceInput.value : 0);
            selectedPortfolioPrice.textContent = `Selected portfolio price: ${formatCurrency(productPrice)}`;
             if (isSampleOrder) {
                if (quantity <= 0) {
                    quoteEstimate.textContent = 'Set quantity to see the final price quotation.';
                    priceBreakdown.style.display = 'none';
                    return;
                }
                const totalPrice = productPrice * quantity;
                quoteEstimate.textContent = `${formatCurrency(productPrice)} per item • ${formatCurrency(totalPrice)} overall total`;
                priceBreakdown.style.display = 'block';
                document.getElementById('priceBreakdownBase').textContent = 'Base service: Included in posted work';
                document.getElementById('priceBreakdownPortfolio').textContent = `Posted work unit price: ${formatCurrency(productPrice)}`;
                document.getElementById('priceBreakdownAddOns').textContent = 'Add-ons: Not applicable for sample order';
                document.getElementById('priceBreakdownRush').textContent = 'Rush fee: Not applicable for sample order';
                document.getElementById('priceBreakdownQuantity').textContent = `Quantity: ${quantity}`;
                return;
            }
            if (!service || Object.keys(pricingState.base_prices).length === 0 || quantity <= 0) {
                quoteEstimate.textContent = 'Select a shop and service to see estimates.';
                 priceBreakdown.style.display = 'none';
                return;
            }

            const serviceTypePrice = Number(pricingState.base_prices[service] ?? pricingState.base_prices.Custom ?? 0);
            const addOns = isSampleOrder
                ? []
                : Array.from(document.querySelectorAll('input[name="add_ons[]"]:checked')).map((input) => input.value);
            const addOnTotal = addOns.reduce((sum, addon) => sum + (pricingState.add_ons[addon] || 0), 0);
            const rushElement = document.getElementById('rushService');
            const rushRequested = !isSampleOrder && rushElement && rushElement.checked;
            const subtotal = productPrice + serviceTypePrice + Number(addOnTotal);
            const rushFeeAmount = rushRequested ? subtotal * (pricingState.rush_fee_percent / 100) : 0;

            const unitEstimate = subtotal + rushFeeAmount;
            const totalEstimate = unitEstimate * quantity;

            quoteEstimate.textContent = `${formatCurrency(unitEstimate)} per item • ${formatCurrency(totalEstimate)} total`;
            priceBreakdown.style.display = 'block';
            document.getElementById('priceBreakdownBase').textContent = `Base service: ${formatCurrency(serviceTypePrice)}`;
            document.getElementById('priceBreakdownPortfolio').textContent = `Portfolio product price: ${formatCurrency(productPrice)}`;
            document.getElementById('priceBreakdownAddOns').textContent = `Add-ons: ${formatCurrency(addOnTotal)}`;
            document.getElementById('priceBreakdownRush').textContent = `Rush fee: ${formatCurrency(rushFeeAmount)}`;
            document.getElementById('priceBreakdownQuantity').textContent = `Quantity: ${quantity}`;
        }

        function updateSelectionSummary() {
            const selectedShopRadio = document.querySelector('input[name="shop_id"]:checked');
            const selectedService = getSelectedService();
            const summary = document.getElementById('selectionSummary');

            if (!selectedShopRadio) {
                summary.textContent = 'No shop and order selected yet.';
                return;
            }

            const selectedCard = selectedShopRadio.closest('.shop-card');
            const shopNameElement = selectedCard ? selectedCard.querySelector('h5') : null;
            const shopName = shopNameElement ? shopNameElement.textContent.trim() : 'Selected shop';
            const orderText = selectedService || document.querySelector('input[name="custom_service"]').value.trim() || 'No order selected yet';
            const selectedPostBanner = document.getElementById('selectedPostBanner');
            const selectedPostTitle = selectedPostBanner ? selectedPostBanner.dataset.title : '';

            summary.textContent = selectedPostTitle
                ? `Shop: ${shopName} • Order: ${orderText} • Post: ${selectedPostTitle}`
                : `Shop: ${shopName} • Order: ${orderText}`;
        }

        function updateSampleOrderSummary() {
            if (!isSampleOrder) {
                return;
            }
            const canvas = document.getElementById('sampleCanvasType');
            const embroidery = document.getElementById('sampleEmbroideryDetail');
            const size = document.getElementById('sampleSizeSelection');
            const quantity = document.getElementById('sampleQuantityInput');

            const canvasValue = canvas && canvas.value ? canvas.value : 'Not selected yet';
            const embroideryValue = embroidery && embroidery.value ? embroidery.value : 'Not selected yet';
            const sizeValue = size && size.value ? size.value : 'Not selected yet';
            const quantityValue = quantity && quantity.value ? quantity.value : '1';

            const canvasSummary = document.getElementById('sampleCanvasSummary');
            const embroiderySummary = document.getElementById('sampleEmbroiderySummary');
            const sizeSummary = document.getElementById('sampleSizeSummary');
            const quantitySummary = document.getElementById('sampleQuantitySummary');
            const summaryBox = document.getElementById('sampleSelectionSummary');

            if (canvasSummary) canvasSummary.textContent = canvasValue;
            if (embroiderySummary) embroiderySummary.textContent = embroideryValue;
            if (sizeSummary) sizeSummary.textContent = sizeValue;
            if (quantitySummary) quantitySummary.textContent = quantityValue;

            if (summaryBox) {
                if (canvas && canvas.value && embroidery && embroidery.value && size && size.value) {
                    summaryBox.textContent = `Selected: ${canvas.value} canvas, ${embroidery.value}, ${size.value}, quantity ${quantityValue}.`;
                } else {
                    summaryBox.textContent = 'Please complete canvas type, embroidery detail, size, and quantity.';
                }
            }
        }

        function showOnlySelectedShop(selectedShopId) {
            document.querySelectorAll('.shop-card').forEach(card => {
                const isSelected = card.id === `shop-${selectedShopId}`;
                card.style.display = isSelected ? '' : 'none';
            });
            const changeShopBtn = document.getElementById('changeShopBtn');
            if (changeShopBtn) {
                changeShopBtn.style.display = '';
            }
        }

        function showAllShops() {
            document.querySelectorAll('.shop-card').forEach(card => {
                card.style.display = '';
            });
            const changeShopBtn = document.getElementById('changeShopBtn');
            if (changeShopBtn) {
                changeShopBtn.style.display = 'none';
            }
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

        function setPricingState(pricing) {
            pricingState.base_prices = pricing.base_prices || {};
            pricingState.add_ons = pricing.add_ons || {};
            pricingState.complexity_multipliers = pricing.complexity_multipliers || {};
            pricingState.rush_fee_percent = Number(pricing.rush_fee_percent || 0);

            if (!isSampleOrder) {
                renderAddOns(pricingState.add_ons);
                document.getElementById('rushHint').textContent = pricingState.rush_fee_percent
                    ? `Rush fee: +${pricingState.rush_fee_percent}%`
                    : 'Rush service is not configured.';
                document.getElementById('addOnHint').textContent = 'Add-ons pulled from shop pricing rules.';
            }

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

            if (!isSampleOrder) {
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
            }
                
            const pricing = JSON.parse(shopCard.dataset.pricing || '{}');
            setPricingState(pricing);
            showOnlySelectedShop(shopId);
            updateSelectionSummary();
        }
        
        // Service selection
         function selectService(service, selectedOption) {
            // Remove selected class from all services
            document.querySelectorAll('.service-option').forEach(option => {
                option.classList.remove('selected');
            });
            // Add to clicked service
             if (selectedOption) {
                selectedOption.classList.add('selected');
            }
            
            // Check the radio button
            const radio = selectedOption ? selectedOption.querySelector('input[type="radio"]') : null;
            if (radio) {
                radio.checked = true;
            }
            
             // Keep custom service input for true custom requests only
            document.querySelector('input[name="custom_service"]').value = '';
            toggleDetailSelections();
            updateQuoteEstimate();
            updateSelectionSummary();
        }

        const changeShopBtn = document.getElementById('changeShopBtn');
        if (changeShopBtn) {
            changeShopBtn.addEventListener('click', () => {
                window.location.href = 'dashboard.php';
            });
        }
         document.querySelectorAll('.selection-buttons').forEach(group => {
            group.querySelectorAll('.selection-btn').forEach(button => {
                button.addEventListener('click', () => {
                    group.querySelectorAll('.selection-btn').forEach(btn => btn.classList.remove('selected'));
                    button.classList.add('selected');
                    const targetInput = document.getElementById(group.dataset.targetInput);
                    if (targetInput) {
                        targetInput.value = button.dataset.value;
                    }
                });
            });
        });

        document.querySelectorAll('.shop-card').forEach(card => {
            const services = card.dataset.services || '[]';
            card.dataset.services = services;
        });
        
          const preselectedShopId = <?php echo $preselected_shop_id; ?>;
         if (preselectedShopId && !isSampleOrder) {
            const preselectedCard = document.getElementById('shop-' + preselectedShopId);
            if (preselectedCard) {
                selectShop(preselectedShopId);
            }
            } else if (isSampleOrder) {
            updateQuoteEstimate();
        }
        const rushServiceInput = document.getElementById('rushService');
        if (rushServiceInput) {
            rushServiceInput.addEventListener('change', updateQuoteEstimate);
        }
        document.querySelector('input[name="quantity"]').addEventListener('input', () => {
            updateQuoteEstimate();
            updateSampleOrderSummary();
        });
        toggleDetailSelections();
        if (!isSampleOrder) {
            document.querySelector('input[name="custom_service"]').addEventListener('input', () => {
                toggleDetailSelections();
                updateQuoteEstimate();
                updateSelectionSummary();
            });
            } else {
            ['sampleCanvasType', 'sampleEmbroideryDetail', 'sampleSizeSelection'].forEach((id) => {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('change', updateSampleOrderSummary);
                }
            });
        }

        updateSampleOrderSummary();
    </script>
</body>
</html>