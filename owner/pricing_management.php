<?php
session_start();

require_once '../config/db.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $base_prices_input = $_POST['base_prices'] ?? [];
        $complexity_input = $_POST['complexity_multipliers'] ?? [];
        $add_on_input = $_POST['add_ons'] ?? [];
        $rush_fee_percent = filter_var($_POST['rush_fee_percent'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($rush_fee_percent === false || $rush_fee_percent < 0) {
            throw new RuntimeException('Please provide a valid rush fee percentage (0 or greater).');
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
        ];

        $update_stmt = $pdo->prepare('UPDATE shops SET pricing_settings = ? WHERE id = ?');
        $update_stmt->execute([json_encode($pricing_payload), $shop['id']]);

        $shop_stmt->execute([$owner_id]);
        $shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);
        $pricing_settings = resolve_pricing_settings($shop, $default_pricing_settings);

        $success = 'Pricing settings updated. New quotes are now reflected in client place order.';
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = 'Failed to update pricing settings: ' . $e->getMessage();
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
            </div>
        </div>
    </div>
</body>
</html>