<?php
session_start();
require_once '../config/db.php';
require_once '../includes/media_manager.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$success = '';
$error = '';
$weekdays = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
];
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

function resolve_pricing_settings(array $shop, array $defaults): array {
    if (!empty($shop['pricing_settings'])) {
        $decoded = json_decode($shop['pricing_settings'], true);
        if (is_array($decoded)) {
            return array_replace_recursive($defaults, $decoded);
        }
    }

    return $defaults;
}

$current_operating_days = $shop['operating_days']
    ? json_decode($shop['operating_days'], true)
    : [1, 2, 3, 4, 5, 6];
$current_services = $shop['service_settings']
    ? json_decode($shop['service_settings'], true)
    : $available_services;
$current_opening_time = $shop['opening_time'] ?: '08:00';
$current_closing_time = $shop['closing_time'] ?: '18:00';
$current_pricing_settings = resolve_pricing_settings($shop, $default_pricing_settings);
$portfolio_stmt = $pdo->prepare("SELECT * FROM shop_portfolio WHERE shop_id = ? ORDER BY created_at DESC");
$portfolio_stmt->execute([$shop['id']]);
$portfolio_items = $portfolio_stmt->fetchAll();
$capacity_stmt = $pdo->prepare("
    SELECT COALESCE(SUM(max_active_orders), 0) 
    FROM shop_staffs 
    WHERE shop_id = ? AND status = 'active'
");
$capacity_stmt->execute([$shop['id']]);
$total_capacity = (int) $capacity_stmt->fetchColumn();
$workload_stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM orders 
    WHERE shop_id = ? AND status IN ('pending', 'accepted', 'in_progress')
");
$workload_stmt->execute([$shop['id']]);
$active_workload = (int) $workload_stmt->fetchColumn();
$capacity_utilization = $total_capacity > 0 ? min(100, round(($active_workload / $total_capacity) * 100)) : 0;

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_profile';

    try {
        if ($action === 'add_portfolio') {
            $portfolio_title = sanitize($_POST['portfolio_title'] ?? '');
            $portfolio_description = sanitize($_POST['portfolio_description'] ?? '');

        if ($portfolio_title === '') {
                throw new RuntimeException('Portfolio title is required.');
            }
            if (empty($_FILES['portfolio_image']['name'])) {
                throw new RuntimeException('Please upload a portfolio image.');
            }

            $upload_result = save_uploaded_media(
                $_FILES['portfolio_image'],
                ['jpg', 'jpeg', 'png', 'webp'],
                MAX_FILE_SIZE,
                'portfolio',
                'portfolio',
                (string) $shop['id']
            );

        if (!$upload_result['success']) {
                throw new RuntimeException($upload_result['error']);
            }

        $insert_stmt = $pdo->prepare("
                INSERT INTO shop_portfolio (shop_id, title, description, image_path)
                VALUES (?, ?, ?, ?)
            ");
            $insert_stmt->execute([
                $shop['id'],
                $portfolio_title,
                $portfolio_description,
                $upload_result['path'],
            ]);

            $portfolio_stmt->execute([$shop['id']]);
            $portfolio_items = $portfolio_stmt->fetchAll();
            $success = 'Portfolio item added successfully.';
        } elseif ($action === 'delete_portfolio') {
            $portfolio_id = (int) ($_POST['portfolio_id'] ?? 0);
            $item_stmt = $pdo->prepare("SELECT image_path FROM shop_portfolio WHERE id = ? AND shop_id = ?");
            $item_stmt->execute([$portfolio_id, $shop['id']]);
            $item = $item_stmt->fetch();
            if (!$item) {
                throw new RuntimeException('Portfolio item not found.');
            }

            $delete_stmt = $pdo->prepare("DELETE FROM shop_portfolio WHERE id = ? AND shop_id = ?");
            $delete_stmt->execute([$portfolio_id, $shop['id']]);
            $path = (string) ($item['image_path'] ?? '');
            $parts = explode('/', $path, 2);
            if (count($parts) === 2) {
                delete_media_file($parts[0], $parts[1]);
            }

            $portfolio_stmt->execute([$shop['id']]);
            $portfolio_items = $portfolio_stmt->fetchAll();
            $success = 'Portfolio item removed.';
        } else {
            $shop_name = sanitize($_POST['shop_name']);
            $shop_description = sanitize($_POST['shop_description']);
            $address = sanitize($_POST['address']);
            $phone = sanitize($_POST['phone']);
            $email = sanitize($_POST['email']);
            $business_permit = sanitize($_POST['business_permit']);
            $opening_time = sanitize($_POST['opening_time']);
            $closing_time = sanitize($_POST['closing_time']);
            $operating_days = array_map('intval', $_POST['operating_days'] ?? []);
            $enabled_services = array_values(array_intersect($available_services, $_POST['enabled_services'] ?? []));
            $base_prices_input = $_POST['base_prices'] ?? [];
            $complexity_input = $_POST['complexity_multipliers'] ?? [];
            $add_on_input = $_POST['add_ons'] ?? [];
            $rush_fee_percent = filter_var($_POST['rush_fee_percent'] ?? null, FILTER_VALIDATE_FLOAT);

            if (empty($operating_days)) {
                throw new RuntimeException('Please select at least one operating day.');
            }
            if (empty($enabled_services)) {
                throw new RuntimeException('Please enable at least one service.');
            }
            if ($rush_fee_percent === false || $rush_fee_percent < 0) {
                throw new RuntimeException('Please provide a valid rush fee percentage (0 or greater).');
            }
            $opening_timestamp = strtotime($opening_time);
            $closing_timestamp = strtotime($closing_time);
            if ($opening_timestamp === false || $closing_timestamp === false || $opening_timestamp >= $closing_timestamp) {
                throw new RuntimeException('Please provide valid operating hours (opening time must be before closing time).');
            }

            $base_prices = [];
            foreach ($default_pricing_settings['base_prices'] as $service => $default_price) {
                $value = filter_var($base_prices_input[$service] ?? null, FILTER_VALIDATE_FLOAT);
                if ($value === false || $value < 0) {
                    throw new RuntimeException('Base prices must be 0 or greater.');
                }
                $base_prices[$service] = (float) $value;
            }

        $complexity_multipliers = [];
            foreach ($default_pricing_settings['complexity_multipliers'] as $level => $default_multiplier) {
                $value = filter_var($complexity_input[$level] ?? null, FILTER_VALIDATE_FLOAT);
                if ($value === false || $value <= 0) {
                    throw new RuntimeException('Complexity multipliers must be greater than 0.');
                }
                $complexity_multipliers[$level] = (float) $value;
            }

            $add_ons = [];
            foreach ($default_pricing_settings['add_ons'] as $addon => $default_fee) {
                $value = filter_var($add_on_input[$addon] ?? null, FILTER_VALIDATE_FLOAT);
                if ($value === false || $value < 0) {
                    throw new RuntimeException('Add-on fees must be 0 or greater.');
                }
                $add_ons[$addon] = (float) $value;
            }

            $pricing_payload = [
                'base_prices' => $base_prices,
                'complexity_multipliers' => $complexity_multipliers,
                'rush_fee_percent' => (float) $rush_fee_percent,
                'add_ons' => $add_ons,
            ];

            $update_stmt = $pdo->prepare("
                UPDATE shops 
                SET shop_name = ?, shop_description = ?, address = ?, phone = ?, email = ?, business_permit = ?,
                    opening_time = ?, closing_time = ?, operating_days = ?, service_settings = ?, pricing_settings = ?
                WHERE id = ?
            ");
            $update_stmt->execute([
                $shop_name,
                $shop_description,
                $address,
                $phone,
                $email,
                $business_permit,
                $opening_time,
                $closing_time,
                json_encode(array_values($operating_days)),
                json_encode(array_values($enabled_services)),
                json_encode($pricing_payload),
                $shop['id']
            ]);

            $shop_stmt->execute([$owner_id]);
            $shop = $shop_stmt->fetch();
            $current_operating_days = $shop['operating_days']
                ? json_decode($shop['operating_days'], true)
                : $current_operating_days;
            $current_services = $shop['service_settings']
                ? json_decode($shop['service_settings'], true)
                : $current_services;
            $current_opening_time = $shop['opening_time'] ?: $current_opening_time;
            $current_closing_time = $shop['closing_time'] ?: $current_closing_time;
            $current_pricing_settings = resolve_pricing_settings($shop, $default_pricing_settings);
            $success = 'Shop profile updated successfully.';
        }
    } catch(RuntimeException $e) {
        $error = $e->getMessage();
    } catch(PDOException $e) {
        $error = 'Failed to update shop profile: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Profile - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link active">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="reviews.php" class="nav-link">Reviews</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="earnings.php" class="nav-link">Earnings</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
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

    <div class="container">
        <div class="dashboard-header">
            <h2>Shop Profile</h2>
            <p class="text-muted">Manage your shop information and public listing details.</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="update_profile">
                <div class="form-group">
                    <label>Shop Name *</label>
                    <input type="text" name="shop_name" class="form-control" required
                           value="<?php echo htmlspecialchars($shop['shop_name']); ?>">
                </div>

                <div class="form-group">
                    <label>Shop Description *</label>
                    <textarea name="shop_description" class="form-control" rows="4" required><?php echo htmlspecialchars($shop['shop_description']); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Business Address *</label>
                    <textarea name="address" class="form-control" rows="3" required><?php echo htmlspecialchars($shop['address']); ?></textarea>
                </div>

                <div class="row" style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Contact Phone *</label>
                        <input type="tel" name="phone" class="form-control" required
                               value="<?php echo htmlspecialchars($shop['phone']); ?>">
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label>Contact Email</label>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($shop['email']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Business Permit Number</label>
                    <input type="text" name="business_permit" class="form-control"
                           value="<?php echo htmlspecialchars($shop['business_permit']); ?>">
                </div>

                <div class="card" style="background: #f8fafc;">
                    <h4>Operating Hours</h4>
                    <p class="text-muted">Set when your shop accepts new orders.</p>
                    <div class="row" style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label>Opening Time</label>
                            <input type="time" name="opening_time" class="form-control" required
                                   value="<?php echo htmlspecialchars($current_opening_time); ?>">
                        </div>
                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label>Closing Time</label>
                            <input type="time" name="closing_time" class="form-control" required
                                   value="<?php echo htmlspecialchars($current_closing_time); ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Operating Days</label>
                        <div class="row" style="display: flex; flex-wrap: wrap; gap: 12px;">
                            <?php foreach ($weekdays as $dayIndex => $dayLabel): ?>
                                <label style="display: flex; align-items: center; gap: 6px;">
                                    <input type="checkbox" name="operating_days[]"
                                           value="<?php echo $dayIndex; ?>"
                                           <?php echo in_array($dayIndex, $current_operating_days, true) ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($dayLabel); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card" style="background: #f8fafc;">
                    <h4>Service Availability</h4>
                    <p class="text-muted">Choose which services clients can request from your shop.</p>
                    <div class="row" style="display: flex; flex-wrap: wrap; gap: 12px;">
                        <?php foreach ($available_services as $service): ?>
                            <label style="display: flex; align-items: center; gap: 6px;">
                                <input type="checkbox" name="enabled_services[]"
                                       value="<?php echo htmlspecialchars($service); ?>"
                                       <?php echo in_array($service, $current_services, true) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($service); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card" style="background: #f8fafc;">
                    <h4>Service Catalog & Pricing</h4>
                    <p class="text-muted">Set standard pricing rules for quotes. These are used to generate estimates for clients.</p>
                    <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                        <?php foreach ($available_services as $service): ?>
                            <div class="form-group">
                                <label><?php echo htmlspecialchars($service); ?> base price (per item)</label>
                                <input type="number" name="base_prices[<?php echo htmlspecialchars($service); ?>]" class="form-control" min="0" step="0.01" required
                                       value="<?php echo htmlspecialchars($current_pricing_settings['base_prices'][$service] ?? 0); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                        <?php foreach ($current_pricing_settings['complexity_multipliers'] as $level => $multiplier): ?>
                            <div class="form-group">
                                <label><?php echo htmlspecialchars($level); ?> complexity multiplier</label>
                                <input type="number" name="complexity_multipliers[<?php echo htmlspecialchars($level); ?>]" class="form-control" min="0.1" step="0.01" required
                                       value="<?php echo htmlspecialchars($multiplier); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                        <?php foreach ($current_pricing_settings['add_ons'] as $addon => $fee): ?>
                            <div class="form-group">
                                <label><?php echo htmlspecialchars($addon); ?> add-on fee</label>
                                <input type="number" name="add_ons[<?php echo htmlspecialchars($addon); ?>]" class="form-control" min="0" step="0.01" required
                                       value="<?php echo htmlspecialchars($fee); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-group">
                        <label>Rush fee (%)</label>
                        <input type="number" name="rush_fee_percent" class="form-control" min="0" step="0.01" required
                               value="<?php echo htmlspecialchars($current_pricing_settings['rush_fee_percent'] ?? 0); ?>">
                        <small class="text-muted">Applied to subtotal when the client requests rush service.</small>
                    </div>
                </div>

                <div class="row" style="display: flex; gap: 15px;">
                    <div class="card" style="flex: 1; background: #f8fafc;">
                        <h4>Shop Status</h4>
                        <p class="text-muted">Current status: <strong><?php echo ucfirst($shop['status']); ?></strong></p>
                        <p class="text-muted">Rating: <?php echo number_format((float) $shop['rating'], 1); ?> / 5 (<?php echo (int) ($shop['rating_count'] ?? 0); ?> reviews)</p>
                    </div>
                    <div class="card" style="flex: 1; background: #f8fafc;">
                        <h4>Performance Snapshot</h4>
                        <p class="text-muted">Total Orders: <?php echo $shop['total_orders']; ?></p>
                        <p class="text-muted">Total Earnings: ₱<?php echo number_format($shop['total_earnings'], 2); ?></p>
                    </div>
                    <div class="card" style="flex: 1; background: #f8fafc;">
                        <h4>Capacity Estimation</h4>
                        <p class="text-muted">Active workload: <?php echo $active_workload; ?> jobs</p>
                        <p class="text-muted">Estimated capacity: <?php echo $total_capacity ?: 'Not set'; ?></p>
                        <p class="text-muted">Utilization: <?php echo $total_capacity > 0 ? $capacity_utilization . '%' : 'N/A'; ?></p>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
        
        <div class="card mt-4">
            <h3>Portfolio Samples</h3>
            <p class="text-muted">Showcase completed work to help clients evaluate your shop.</p>
            <form method="POST" enctype="multipart/form-data" class="mb-4">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="add_portfolio">
                <div class="row" style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 220px;">
                        <label>Title *</label>
                        <input type="text" name="portfolio_title" class="form-control" required>
                    </div>
                    <div class="form-group" style="flex: 2; min-width: 240px;">
                        <label>Description</label>
                        <input type="text" name="portfolio_description" class="form-control" maxlength="255">
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 220px;">
                        <label>Upload Image *</label>
                        <input type="file" name="portfolio_image" class="form-control" accept=".jpg,.jpeg,.png,.webp" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-primary">
                    <i class="fas fa-image"></i> Add Sample
                </button>
            </form>

            <?php if (empty($portfolio_items)): ?>
                <p class="text-muted">No portfolio samples yet.</p>
            <?php else: ?>
                <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
                    <?php foreach ($portfolio_items as $item): ?>
                        <div class="card" style="background: #f8fafc;">
                            <img src="../assets/uploads/<?php echo htmlspecialchars($item['image_path']); ?>" alt="<?php echo htmlspecialchars($item['title']); ?>" style="width: 100%; height: 160px; object-fit: cover; border-radius: 6px;">
                            <h5 class="mt-2 mb-1"><?php echo htmlspecialchars($item['title']); ?></h5>
                            <?php if (!empty($item['description'])): ?>
                                <p class="text-muted small mb-2"><?php echo htmlspecialchars($item['description']); ?></p>
                            <?php endif; ?>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_portfolio">
                                <input type="hidden" name="portfolio_id" value="<?php echo (int) $item['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
