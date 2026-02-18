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

$current_operating_days = $shop['operating_days']
    ? json_decode($shop['operating_days'], true)
    : [1, 2, 3, 4, 5, 6];
$current_opening_time = $shop['opening_time'] ?: '08:00';
$current_closing_time = $shop['closing_time'] ?: '18:00';
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
             $portfolio_price = filter_var($_POST['portfolio_price'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($portfolio_title === '') {
                throw new RuntimeException('Portfolio title is required.');
            }
            if ($portfolio_price === false || $portfolio_price < 0) {
                throw new RuntimeException('Please provide a valid portfolio price (0 or greater).');
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
               INSERT INTO shop_portfolio (shop_id, title, description, price, image_path)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insert_stmt->execute([
                $shop['id'],
                $portfolio_title,
                $portfolio_description,
                (float) $portfolio_price,
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
            $phone_raw = trim((string) ($_POST['phone'] ?? ''));
            if (!preg_match('/^\+?\d+$/', $phone_raw)) {
                throw new RuntimeException('Contact phone must contain numbers only.');
            }
            if (str_starts_with($phone_raw, '+63')) {
                if (strlen($phone_raw) !== 12) {
                    throw new RuntimeException('If phone starts with +63, it must be exactly 12 characters.');
                }
            } elseif (str_starts_with($phone_raw, '09')) {
                if (strlen($phone_raw) !== 11) {
                    throw new RuntimeException('If phone starts with 09, it must be exactly 11 digits.');
                }
            } else {
                throw new RuntimeException('Contact phone must start with +63 or 09.');
            }
            $phone = sanitize($phone_raw);
            $email = sanitize($_POST['email']);
            $business_permit = sanitize($_POST['business_permit']);
            $opening_time = sanitize($_POST['opening_time']);
            $closing_time = sanitize($_POST['closing_time']);
            $operating_days = array_map('intval', $_POST['operating_days'] ?? []);

            if (empty($operating_days)) {
                throw new RuntimeException('Please select at least one operating day.');
            }
            $opening_timestamp = strtotime($opening_time);
            $closing_timestamp = strtotime($closing_time);
            if ($opening_timestamp === false || $closing_timestamp === false || $opening_timestamp >= $closing_timestamp) {
                throw new RuntimeException('Please provide valid operating hours (opening time must be before closing time).');
            }

            $update_stmt = $pdo->prepare("
                UPDATE shops 
                SET shop_name = ?, shop_description = ?, address = ?, phone = ?, email = ?, business_permit = ?,
                    opening_time = ?, closing_time = ?, operating_days = ?
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
                $shop['id']
            ]);

            $shop_stmt->execute([$owner_id]);
            $shop = $shop_stmt->fetch();
            $current_operating_days = $shop['operating_days']
                ? json_decode($shop['operating_days'], true)
                : $current_operating_days;
            $current_opening_time = $shop['opening_time'] ?: $current_opening_time;
            $current_closing_time = $shop['closing_time'] ?: $current_closing_time;
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
     <style>
        .profile-section-title {
            margin-top: 1.25rem;
            margin-bottom: 0.25rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link active">Shop Profile</a></li>
                <li><a href="pricing_management.php" class="nav-link">Pricing</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="reviews.php" class="nav-link">Reviews</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-coins"></i> Finance
                    </a>
                    <div class="dropdown-menu">
                        <a href="payment_verifications.php" class="dropdown-item"><i class="fas fa-receipt"></i> Payments</a>
                        <a href="earnings.php" class="dropdown-item"><i class="fas fa-wallet"></i> Earnings</a>
                    </div>
                </li>
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

                  <h4 class="profile-section-title">Shop Information</h4>
                <p class="text-muted">Basic public details that customers can see in your shop profile.</p>
                <div>
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
                           <input type="text" name="phone" class="form-control" required maxlength="12" inputmode="numeric" pattern="^(\+63\d{9}|09\d{9})$"
                                   value="<?php echo htmlspecialchars($shop['phone']); ?>">
                                   <small class="text-muted">Use <strong>+63</strong> then 9 digits (12 total chars), or <strong>09</strong> then 9 digits (11 digits total).</small>
                        </div>

                        <div class="form-group" style="flex: 1;">
                            <label>Contact Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?php echo htmlspecialchars($shop['email']); ?>">
                        </div>
                    </div>
                </div>

                <h4 class="profile-section-title">Business Information</h4>
                <div>
                    <p class="text-muted">Maintain all legal and permit details required for your shop to operate.</p>
                    <div class="form-group">
                        <label>Business Permit Number</label>
                        <input type="text" name="business_permit" class="form-control"
                               value="<?php echo htmlspecialchars($shop['business_permit']); ?>">
                    </div>
                </div>

                <div class="card" style="background: #f8fafc;">
                    <h4>Operating Hours</h4>
                    <p class="text-muted">Set when your shop accepts new orders (Philippine Time, PHT).</p>
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
                    <h4>Service Catalog & Pricing</h4>
                    <p class="text-muted">Service catalog and pricing are now managed in <a href="pricing_management.php">Pricing Management</a>.</p>
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
            <p class="text-muted">Showcase completed work to help clients evaluate your shop. Posted samples can also be sold directly to customers from your shop page.</p>
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
                    <div class="form-group" style="flex: 1; min-width: 180px;">
                        <label>Price *</label>
                        <input type="number" name="portfolio_price" class="form-control" min="0" step="0.01" placeholder="0.00" required>
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
                             <?php if (isset($item['price'])): ?>
                                <p class="mb-1"><strong>₱<?php echo number_format((float) $item['price'], 2); ?></strong></p>
                            <?php endif; ?>
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
    <script>
        const phoneInput = document.querySelector('input[name="phone"]');
        if (phoneInput) {
            phoneInput.addEventListener('input', () => {
                let value = phoneInput.value.replace(/[^\d+]/g, '');
                if (value.indexOf('+') > 0) {
                    value = value.replace(/\+/g, '');
                }

                if (value.startsWith('+63')) {
                    value = value.slice(0, 12);
                } else if (value.startsWith('09')) {
                    value = value.slice(0, 11);
                } else {
                    value = value.startsWith('+') ? '+' : value.replace(/\+/g, '');
                    value = value.slice(0, 12);
                }
                phoneInput.value = value;
            });
            phoneInput.addEventListener('blur', () => {
                phoneInput.reportValidity();
            });
        }
    </script>
</body>
</html>
