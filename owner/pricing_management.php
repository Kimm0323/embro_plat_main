<?php
session_start();

require_once '../config/db.php';
require_once '../includes/media_manager.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare('SELECT * FROM shops WHERE owner_id = ? LIMIT 1');
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
    header('Location: create_shop.php');
    exit();
}

$success = '';
$error = '';
$pricing_services = [
    'T-shirt Embroidery',
    'Logo Embroidery',
    'Cap Embroidery',
    'Bag Embroidery',
    'Custom',
];
$available_services = $pricing_services;
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
     'products' => [],
];

$inventory_states = [
    'available' => 'Available',
    'low_quantity' => 'Low Quantity',
    'unavailable' => 'Unavailable',
];

function resolve_pricing_settings(array $shop, array $defaults): array
{
    if (!empty($shop['pricing_settings'])) {
        $decoded = json_decode($shop['pricing_settings'], true);
        if (is_array($decoded)) {
            return array_replace_recursive($defaults, $decoded);
        }
    }

    return $defaults;
}

$pricing_settings = resolve_pricing_settings($shop, $default_pricing_settings);
$service_settings = $shop['service_settings']
    ? json_decode($shop['service_settings'], true)
    : $available_services;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? 'save_pricing';
        $base_prices_input = $_POST['base_prices'] ?? [];
        $complexity_input = $_POST['complexity_multipliers'] ?? [];
        $add_on_input = $_POST['add_ons'] ?? [];
        $rush_fee_percent = filter_var($_POST['rush_fee_percent'] ?? null, FILTER_VALIDATE_FLOAT);
        $enabled_services = array_values(array_intersect($available_services, $_POST['enabled_services'] ?? []));

        $products = is_array($pricing_settings['products'] ?? null) ? $pricing_settings['products'] : [];

        if ($action === 'add_product' || $action === 'edit_product') {
            $product_id = sanitize($_POST['product_id'] ?? '');
            $product_type = sanitize($_POST['product_type'] ?? '');
            $available_type = sanitize($_POST['available_type'] ?? '');
            $available_sizes = sanitize($_POST['available_sizes'] ?? '');
            $design_font = sanitize($_POST['design_font'] ?? '');
            $embroidery_sizes = sanitize($_POST['embroidery_sizes'] ?? '');
            $inventory_state = $_POST['inventory_state'] ?? 'available';

            if ($product_type === '' || $available_type === '' || $available_sizes === '' || $design_font === '' || $embroidery_sizes === '') {
                throw new RuntimeException('Please complete all required product details.');
            }
            if (!isset($inventory_states[$inventory_state])) {
                throw new RuntimeException('Please select a valid availability status.');
            }

            $existing_product = null;
            $existing_index = null;
            if ($action === 'edit_product') {
                foreach ($products as $idx => $product) {
                    if (($product['id'] ?? '') === $product_id) {
                        $existing_product = $product;
                        $existing_index = $idx;
                        break;
                    }
                }
                if ($existing_product === null) {
                    throw new RuntimeException('The selected product could not be found.');
                }
            }

            $front_path = $existing_product['front_photo'] ?? null;
            if (!empty($_FILES['front_photo']['name'] ?? '')) {
                $upload_front = save_uploaded_media(
                    $_FILES['front_photo'],
                    ALLOWED_IMAGE_TYPES,
                    MAX_FILE_SIZE,
                    'products',
                    'product_front',
                    (string) $shop['id']
                );
                if (!$upload_front['success']) {
                    throw new RuntimeException('Front photo: ' . $upload_front['error']);
                }
                $front_path = $upload_front['path'];
            }

            $back_path = $existing_product['back_photo'] ?? null;
            if (!empty($_FILES['back_photo']['name'] ?? '')) {
                $upload_back = save_uploaded_media(
                    $_FILES['back_photo'],
                    ALLOWED_IMAGE_TYPES,
                    MAX_FILE_SIZE,
                    'products',
                    'product_back',
                    (string) $shop['id']
                );
                if (!$upload_back['success']) {
                    throw new RuntimeException('Back photo: ' . $upload_back['error']);
                }
                $back_path = $upload_back['path'];
            }

            if ($front_path === null || $back_path === null) {
                throw new RuntimeException('Please upload both front and back product photos.');
            }

            $payload = [
                'id' => $existing_product['id'] ?? uniqid('prod_', true),
                'product_type' => $product_type,
                'front_photo' => $front_path,
                'back_photo' => $back_path,
                'available_type' => $available_type,
                'available_sizes' => $available_sizes,
                'design_font' => $design_font,
                'embroidery_sizes' => $embroidery_sizes,
                'inventory_state' => $inventory_state,
                'archived' => (bool) ($existing_product['archived'] ?? false),
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => $existing_product['created_at'] ?? date('Y-m-d H:i:s'),
            ];

            if ($existing_index !== null) {
                $products[$existing_index] = $payload;
                $success = 'Product updated successfully.';
            } else {
                array_unshift($products, $payload);
                $success = 'Product added successfully.';
            }
        } elseif ($action === 'toggle_archive' || $action === 'delete_product') {
            $product_id = sanitize($_POST['product_id'] ?? '');
            $matched = false;
            foreach ($products as $idx => $product) {
                if (($product['id'] ?? '') !== $product_id) {
                    continue;
                }
                $matched = true;
                if ($action === 'toggle_archive') {
                    $products[$idx]['archived'] = !((bool) ($product['archived'] ?? false));
                    $products[$idx]['updated_at'] = date('Y-m-d H:i:s');
                    $success = $products[$idx]['archived'] ? 'Product archived successfully.' : 'Product restored successfully.';
                } else {
                    unset($products[$idx]);
                    $products = array_values($products);
                    $success = 'Product deleted successfully.';
                }
                break;
            }
            if (!$matched) {
                throw new RuntimeException('Unable to find the selected product.');
            }
        }

        if ($action !== 'save_pricing') {
            $pricing_payload = $pricing_settings;
            $pricing_payload['products'] = $products;

            $update_stmt = $pdo->prepare('UPDATE shops SET pricing_settings = ? WHERE id = ?');
            $update_stmt->execute([json_encode($pricing_payload), $shop['id']]);

            $shop_stmt->execute([$owner_id]);
            $shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);
            $pricing_settings = resolve_pricing_settings($shop, $default_pricing_settings);
            $service_settings = $shop['service_settings']
                ? json_decode($shop['service_settings'], true)
                : $available_services;
            if ($success === '') {
                $success = 'Product catalog saved successfully.';
            }
            throw new RuntimeException('__STOP__');
        }

        if ($rush_fee_percent === false || $rush_fee_percent < 0) {
            throw new RuntimeException('Please provide a valid rush fee percentage (0 or greater).');
        }
        if (empty($enabled_services)) {
            throw new RuntimeException('Please enable at least one service.');
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
        foreach ($default_pricing_settings['add_ons'] as $add_on => $default_fee) {
            $value = filter_var($add_on_input[$add_on] ?? null, FILTER_VALIDATE_FLOAT);
            if ($value === false || $value < 0) {
                throw new RuntimeException('Add-on fees must be 0 or greater.');
            }
            $add_ons[$add_on] = (float) $value;
        }

        $pricing_payload = [
            'base_prices' => $base_prices,
            'complexity_multipliers' => $complexity_multipliers,
            'rush_fee_percent' => (float) $rush_fee_percent,
            'add_ons' => $add_ons,
            'products' => $products,
        ];

        $update_stmt = $pdo->prepare('UPDATE shops SET pricing_settings = ?, service_settings = ? WHERE id = ?');
        $update_stmt->execute([json_encode($pricing_payload), json_encode(array_values($enabled_services)), $shop['id']]);

        $shop_stmt->execute([$owner_id]);
        $shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);
        $pricing_settings = resolve_pricing_settings($shop, $default_pricing_settings);
         $service_settings = $shop['service_settings']
            ? json_decode($shop['service_settings'], true)
            : $available_services;

        $success = 'Pricing settings updated. New quotes are now reflected in client place order.';
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '__STOP__') {
            $error = $e->getMessage();
        }
    } catch (PDOException $e) {
        $error = 'Failed to update pricing settings: ' . $e->getMessage();
    }
}

$products = is_array($pricing_settings['products'] ?? null) ? $pricing_settings['products'] : [];
$active_products = array_values(array_filter($products, static fn($product) => empty($product['archived'])));
$archived_products = array_values(array_filter($products, static fn($product) => !empty($product['archived'])));
$editing_product_id = sanitize($_GET['edit_product'] ?? '');
$editing_product = null;
if ($editing_product_id !== '') {
    foreach ($products as $product) {
        if (($product['id'] ?? '') === $editing_product_id) {
            $editing_product = $product;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Management - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }
        .pricing-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            padding: 14px;
        }
        .pricing-card h5 {
            margin: 0 0 12px;
            color: #1f2937;
        }
        .pricing-helper {
            color: #64748b;
            font-size: 0.85rem;
        }
          .product-photo-preview {
            width: 100%;
            max-width: 180px;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-top: 8px;
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
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="pricing_management.php" class="nav-link active">Pricing</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="container mt-4 mb-4">
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-between align-center">
                <h3><i class="fas fa-tags"></i> Service Pricing</h3>
                <a href="shop_profile.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Profile</a>
            </div>
            <div class="card-body">
                <p class="pricing-helper mb-3">These values are used to build estimated quotes in <code>client/place_order.php</code>.</p>

                <form method="POST" action="">
                    <?php echo csrf_field(); ?>
                    <div class="pricing-card mb-3">
                        <h5>Service Availability</h5>
                        <p class="pricing-helper mb-2">Choose which services clients can request from your shop.</p>
                        <div class="pricing-grid">
                            <?php foreach ($available_services as $service): ?>
                                <label style="display:flex;align-items:center;gap:8px;">
                                    <input
                                        type="checkbox"
                                        name="enabled_services[]"
                                        value="<?php echo htmlspecialchars($service); ?>"
                                        <?php echo in_array($service, $service_settings, true) ? 'checked' : ''; ?>
                                    >
                                    <span><?php echo htmlspecialchars($service); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="pricing-card mb-3">
                        <h5>Base Prices (per unit)</h5>
                        <div class="pricing-grid">
                            <?php foreach ($pricing_services as $service): ?>
                                <div>
                                    <label class="form-label"><?php echo htmlspecialchars($service); ?> (₱)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="form-control"
                                        name="base_prices[<?php echo htmlspecialchars($service); ?>]"
                                        value="<?php echo htmlspecialchars((string) ($pricing_settings['base_prices'][$service] ?? 0)); ?>"
                                        required
                                    >
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="pricing-card mb-3">
                        <h5>Complexity Multipliers</h5>
                        <div class="pricing-grid">
                            <?php foreach ($default_pricing_settings['complexity_multipliers'] as $level => $_): ?>
                                <div>
                                    <label class="form-label"><?php echo htmlspecialchars($level); ?></label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        class="form-control"
                                        name="complexity_multipliers[<?php echo htmlspecialchars($level); ?>]"
                                        value="<?php echo htmlspecialchars((string) ($pricing_settings['complexity_multipliers'][$level] ?? 1)); ?>"
                                        required
                                    >
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="pricing-card mb-3">
                        <h5>Add-on Fees</h5>
                        <div class="pricing-grid">
                            <?php foreach ($default_pricing_settings['add_ons'] as $add_on => $_): ?>
                                <div>
                                    <label class="form-label"><?php echo htmlspecialchars($add_on); ?> (₱)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="form-control"
                                        name="add_ons[<?php echo htmlspecialchars($add_on); ?>]"
                                        value="<?php echo htmlspecialchars((string) ($pricing_settings['add_ons'][$add_on] ?? 0)); ?>"
                                        required
                                    >
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="pricing-card mb-4">
                        <h5>Rush Service Fee</h5>
                        <label class="form-label">Additional Percentage (%)</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            class="form-control"
                            name="rush_fee_percent"
                            value="<?php echo htmlspecialchars((string) ($pricing_settings['rush_fee_percent'] ?? 0)); ?>"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Pricing Settings
                    </button>
                </form>
                <hr style="margin: 24px 0;">
                <h4><i class="fas fa-shirt"></i> Product Catalog Management</h4>
                <p class="pricing-helper mb-3">Add, edit, archive, restore, or delete products shown in your shop.</p>

                <form method="POST" enctype="multipart/form-data" class="pricing-card mb-4">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $editing_product ? 'edit_product' : 'add_product'; ?>">
                    <?php if ($editing_product): ?>
                        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($editing_product['id']); ?>">
                    <?php endif; ?>

                    <div class="pricing-grid">
                        <div>
                            <label class="form-label">Type of product *</label>
                            <input type="text" class="form-control" name="product_type" required value="<?php echo htmlspecialchars($editing_product['product_type'] ?? ''); ?>" placeholder="e.g. Polo shirt">
                        </div>
                        <div>
                            <label class="form-label">Available type *</label>
                            <input type="text" class="form-control" name="available_type" required value="<?php echo htmlspecialchars($editing_product['available_type'] ?? ''); ?>" placeholder="e.g. Cotton, Dry-fit">
                        </div>
                        <div>
                            <label class="form-label">Available size *</label>
                            <input type="text" class="form-control" name="available_sizes" required value="<?php echo htmlspecialchars($editing_product['available_sizes'] ?? ''); ?>" placeholder="e.g. S, M, L, XL">
                        </div>
                        <div>
                            <label class="form-label">Embroidery design / font *</label>
                            <input type="text" class="form-control" name="design_font" required value="<?php echo htmlspecialchars($editing_product['design_font'] ?? ''); ?>" placeholder="e.g. Script, Block, Serif">
                        </div>
                        <div>
                            <label class="form-label">Embroidery sizes *</label>
                            <input type="text" class="form-control" name="embroidery_sizes" required value="<?php echo htmlspecialchars($editing_product['embroidery_sizes'] ?? ''); ?>" placeholder="e.g. 2x2, 3x3, 4x4 inches">
                        </div>
                        <div>
                            <label class="form-label">Stock status *</label>
                            <select class="form-control" name="inventory_state" required>
                                <?php $selected_state = $editing_product['inventory_state'] ?? 'available'; ?>
                                <?php foreach ($inventory_states as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $selected_state === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Front photo <?php echo $editing_product ? '' : '*'; ?></label>
                            <input type="file" class="form-control" name="front_photo" accept=".jpg,.jpeg,.png,.gif" <?php echo $editing_product ? '' : 'required'; ?>>
                            <?php if (!empty($editing_product['front_photo'])): ?>
                                <img src="../assets/uploads/<?php echo htmlspecialchars($editing_product['front_photo']); ?>" alt="Front photo" class="product-photo-preview">
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="form-label">Back photo <?php echo $editing_product ? '' : '*'; ?></label>
                            <input type="file" class="form-control" name="back_photo" accept=".jpg,.jpeg,.png,.gif" <?php echo $editing_product ? '' : 'required'; ?>>
                            <?php if (!empty($editing_product['back_photo'])): ?>
                                <img src="../assets/uploads/<?php echo htmlspecialchars($editing_product['back_photo']); ?>" alt="Back photo" class="product-photo-preview">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="margin-top: 14px;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?php echo $editing_product ? 'Update Product' : 'Add Product'; ?>
                        </button>
                        <?php if ($editing_product): ?>
                            <a href="pricing_management.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Cancel edit</a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="pricing-card mb-3">
                    <h5>Active Products (<?php echo count($active_products); ?>)</h5>
                    <?php if (empty($active_products)): ?>
                        <p class="pricing-helper mb-0">No active products yet.</p>
                    <?php else: ?>
                        <?php foreach ($active_products as $product): ?>
                            <div class="pricing-card mb-2">
                                <strong><?php echo htmlspecialchars($product['product_type'] ?? 'Product'); ?></strong>
                                <div class="pricing-helper">Type: <?php echo htmlspecialchars($product['available_type'] ?? '—'); ?> | Sizes: <?php echo htmlspecialchars($product['available_sizes'] ?? '—'); ?> | Embroidery: <?php echo htmlspecialchars($product['embroidery_sizes'] ?? '—'); ?></div>
                                <div class="pricing-helper">Design/Font: <?php echo htmlspecialchars($product['design_font'] ?? '—'); ?> | Status: <?php echo htmlspecialchars($inventory_states[$product['inventory_state'] ?? 'available'] ?? 'Available'); ?></div>
                                <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <a class="btn btn-outline btn-sm" href="pricing_management.php?edit_product=<?php echo urlencode($product['id'] ?? ''); ?>"><i class="fas fa-pen"></i> Edit</a>
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_archive">
                                        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product['id'] ?? ''); ?>">
                                        <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-box-archive"></i> Archive</button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this product permanently?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product['id'] ?? ''); ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="pricing-card">
                    <h5>Archived Products (<?php echo count($archived_products); ?>)</h5>
                    <?php if (empty($archived_products)): ?>
                        <p class="pricing-helper mb-0">No archived products.</p>
                    <?php else: ?>
                        <?php foreach ($archived_products as $product): ?>
                            <div class="pricing-card mb-2">
                                <strong><?php echo htmlspecialchars($product['product_type'] ?? 'Product'); ?></strong>
                                <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                    <form method="POST" style="display:inline;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="toggle_archive">
                                        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product['id'] ?? ''); ?>">
                                        <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-rotate-left"></i> Restore</button>
                                    </form>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this archived product permanently?');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_product">
                                        <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($product['id'] ?? ''); ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>