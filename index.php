<?php
session_start();
require_once 'config/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Embroidery Platform - Professional Embroidery Services</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="index.php" class="navbar-brand">
                <i class="fas fa-threads"></i> Embroidery Platform
            </a>
            <ul class="navbar-nav">
                <li><a href="index.php" class="nav-link active">Home</a></li>
                <li><a href="#features" class="nav-link">Features</a></li>
                <li><a href="#roles" class="nav-link">Roles</a></li>
                <li><a href="#about" class="nav-link">About</a></li>
                <?php if(isset($_SESSION['user'])): ?>
                    <?php 
                    $dashboard_url = '';
                    switch($_SESSION['user']['role']) {
                        case 'sys_admin': $dashboard_url = 'sys_admin/dashboard.php'; break;
                        case 'owner': $dashboard_url = 'owner/dashboard.php'; break;
                        case 'hr': $dashboard_url = 'staff/dashboard.php'; break;
                        case 'staff': $dashboard_url = 'staff/dashboard.php'; break;
                        case 'staff': $dashboard_url = 'staff/dashboard.php'; break;
                        case 'client': $dashboard_url = 'client/dashboard.php'; break;
                        default: $dashboard_url = 'index.php'; break;
                    }
                    ?>
                    <li><a href="<?php echo $dashboard_url; ?>" class="nav-link">Dashboard</a></li>
                    <li><a href="auth/logout.php" class="nav-link">Logout</a></li>
                <?php else: ?>
                    <li><a href="auth/login.php" class="nav-link">Login</a></li>
                    <li><a href="auth/register.php?type=client" class="btn btn-primary">Get Started</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <h1 class="hero-title">Professional Embroidery Services Platform</h1>
            <p class="hero-subtitle">
                Connect with the best embroidery service providers. From custom designs to bulk orders, 
                we provide a complete solution for all your embroidery needs.
            </p>
            <div class="hero-actions">
                <?php if(!isset($_SESSION['user'])): ?>
                    <a href="auth/register.php?type=client" class="btn btn-primary btn-lg">
                        <i class="fas fa-shopping-cart"></i> Order Services
                    </a>
                    <a href="auth/register.php?type=owner" class="btn btn-outline-primary btn-lg">
                        <i class="fas fa-store"></i> Register as Provider
                    </a>
                <?php else: ?>
                    <a href="<?php echo $dashboard_url; ?>" class="btn btn-primary btn-lg">
                        <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="section">
        <div class="container">
            <h2 class="section-title">Why Choose Our Platform?</h2>
            <div class="feature-grid">
                <div class="card text-center">
                    <div class="card-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h3>Quick Order Processing</h3>
                    <p class="text-muted">Get your embroidery orders processed quickly with our efficient workflow system.</p>
                </div>
                
                <div class="card text-center">
                    <div class="card-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <h3>Secure Platform</h3>
                    <p class="text-muted">Your data and transactions are protected with enterprise-grade security.</p>
                </div>
                
                <div class="card text-center">
                    <div class="card-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3>Real-time Tracking</h3>
                    <p class="text-muted">Track your orders in real-time from design to delivery.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Roles Section -->
    <section id="roles" class="section section-alt">
        <div class="container">
            <h2 class="section-title">Platform Roles</h2>
            <div class="role-grid">
                <div class="card text-center">
                    <div class="card-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <h3>Client</h3>
                    <p class="text-muted">Place orders, customize designs, track progress, and rate providers.</p>
                    <a href="auth/register.php?type=client" class="btn btn-outline-primary mt-3">Register as Client</a>
                </div>
                
                <div class="card text-center">
                    <div class="card-icon">
                        <i class="fas fa-store"></i>
                    </div>
                    <h3>Shop Owner</h3>
                    <p class="text-muted">Manage your shop, staff, orders, and view earnings & performance.</p>
                    <a href="auth/register.php?type=owner" class="btn btn-outline-primary mt-3">Register as Owner</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <?php
    try {
        $total_shops = $pdo->query("SELECT COUNT(*) as count FROM shops WHERE status = 'active'")->fetch()['count'];
        $total_orders = $pdo->query("SELECT COUNT(*) as count FROM orders")->fetch()['count'];
        $total_users = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch()['count'];
        $completed_orders = $pdo->query("SELECT COUNT(*) as count FROM orders WHERE status = 'completed'")->fetch()['count'];
    } catch(Exception $e) {
        $total_shops = $total_orders = $total_users = $completed_orders = 0;
    }
    ?>
    <section class="section" id="about">
        <div class="container">
            <h2 class="section-title">Trusted by the Embroidery Community</h2>
            <div class="stats-grid">
                <div class="stat-card text-center">
                    <div class="stat-icon"><i class="fas fa-store"></i></div>
                    <div class="stat-number"><?php echo $total_shops; ?>+</div>
                    <div class="stat-label">Active Service Providers</div>
                </div>
                <div class="stat-card text-center">
                    <div class="stat-icon"><i class="fas fa-clipboard-check"></i></div>
                    <div class="stat-number"><?php echo $total_orders; ?>+</div>
                    <div class="stat-label">Orders Processed</div>
                </div>
                <div class="stat-card text-center">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-number"><?php echo $total_users; ?>+</div>
                    <div class="stat-label">Happy Users</div>
                </div>
                <div class="stat-card text-center">
                    <div class="stat-icon"><i class="fas fa-award"></i></div>
                    <div class="stat-number"><?php echo $completed_orders; ?>+</div>
                    <div class="stat-label">Completed Projects</div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="section section-alt">
        <div class="container">
            <div class="card text-center">
                <h2>Ready to Get Started?</h2>
                <p class="hero-subtitle">
                Join thousands of satisfied customers and service providers on our platform.
                Whether you need embroidery services or want to offer them, we have the perfect solution for you.
            </p>
                <div class="hero-actions">
                    <?php if(!isset($_SESSION['user'])): ?>
                        <a href="auth/register.php?type=client" class="btn btn-primary btn-lg">
                            <i class="fas fa-user-plus"></i> Sign Up as Client
                        </a>
                        <a href="auth/register.php?type=owner" class="btn btn-outline-primary btn-lg">
                            <i class="fas fa-store"></i> Register Your Shop
                        </a>
                    <?php else: ?>
                        <a href="<?php echo $dashboard_url; ?>" class="btn btn-primary btn-lg">
                            <i class="fas fa-rocket"></i> Launch Dashboard
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 Embroidery Platform. All rights reserved.</p>
            <small class="text-muted">Professional platform connecting embroidery service providers with customers worldwide.</small>
        </div>
    </footer>
</body>
</html>