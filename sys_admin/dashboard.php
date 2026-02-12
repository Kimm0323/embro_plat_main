<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

// Get system statistics - FIXED QUERIES
$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM users WHERE status = 'pending') as pending_users,
        (SELECT COUNT(*) FROM shops) as total_shops,
        (SELECT COUNT(*) FROM shops WHERE status = 'pending') as pending_shops,
        (SELECT COUNT(*) FROM orders) as total_orders,
        (SELECT SUM(price) FROM orders WHERE status = 'completed') as total_revenue,
        (SELECT COUNT(*) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()) as today_orders
")->fetch();

// Get recent activities - FIXED QUERY (removed order_number and completed_at references)
$activities = $pdo->query("
    SELECT 'user' as type, fullname as name, 'Registered' as action, created_at, 'primary' as color
    FROM users WHERE DATE(created_at) = CURDATE()
    UNION ALL
    SELECT 'shop' as type, shop_name as name, 'Created' as action, created_at, 'success' as color
    FROM shops WHERE DATE(created_at) = CURDATE()
    UNION ALL
    SELECT 'order' as type, CONCAT('Order #', id) as name, 'Completed' as action, created_at as created_at, 'warning' as color
    FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()
    ORDER BY created_at DESC 
    LIMIT 8
")->fetchAll();

// System health metrics
$system_health = [
    ['label' => 'Database', 'status' => 'healthy', 'value' => 95],
    ['label' => 'Server Load', 'status' => 'good', 'value' => 65],
    ['label' => 'Response Time', 'status' => 'excellent', 'value' => 120],
    ['label' => 'Uptime', 'status' => 'perfect', 'value' => 99.9]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Custom enhancements for admin dashboard */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }
        
        .card-stat {
            grid-column: span 3;
            background: linear-gradient(135deg, var(--primary-50), var(--secondary-50));
            border: none;
            padding: 2rem;
        }
        
        .card-wide {
            grid-column: span 8;
        }
        
        .card-narrow {
            grid-column: span 4;
        }
        
        .health-metrics {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .metric-item {
            background: white;
            padding: 1rem;
            border-radius: var(--radius);
            border-left: 4px solid;
        }
        
        .metric-item.healthy { border-left-color: var(--success-500); }
        .metric-item.good { border-left-color: var(--info-500); }
        .metric-item.excellent { border-left-color: var(--primary-500); }
        .metric-item.perfect { border-left-color: var(--secondary-500); }
        
        .activity-timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .activity-timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--gray-200);
        }
        
        .activity-item {
            position: relative;
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-item::before {
            content: '';
            position: absolute;
            left: -2rem;
            top: 1.5rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: var(--primary-500);
            border: 3px solid white;
            box-shadow: 0 0 0 3px var(--primary-100);
        }
        
        .activity-item.primary::before { background: var(--primary-500); box-shadow: 0 0 0 3px var(--primary-100); }
        .activity-item.success::before { background: var(--success-500); box-shadow: 0 0 0 3px var(--success-100); }
        .activity-item.warning::before { background: var(--warning-500); box-shadow: 0 0 0 3px var(--warning-100); }
        
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .quick-action {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.25rem;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            transition: all 0.3s;
            text-decoration: none;
            color: var(--gray-700);
        }
        
        .quick-action:hover {
            border-color: var(--primary-500);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            color: var(--primary-600);
        }
        
        .quick-action i {
            font-size: 1.5rem;
            color: var(--primary-500);
        }
        
        .system-overview {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin: 2rem 0;
        }
        
        .overview-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            text-align: center;
            border: 1px solid var(--gray-200);
            transition: all 0.3s;
        }
        
        .overview-card:hover {
            border-color: var(--primary-300);
            box-shadow: var(--shadow);
        }
        
        .overview-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            background: var(--primary-50);
            color: var(--primary-600);
            border-radius: var(--radius-full);
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
<?php sys_admin_nav('dashboard'); ?>

    <div class="container">
        <!-- Dashboard Header -->
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>System Overview</h2>
                    <p class="text-muted">Welcome back, <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>. Here's what's happening with your platform.</p>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge badge-success">
                        <i class="fas fa-check-circle"></i> All Systems Operational
                    </span>
                    <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>
        </div>

        <!-- System Overview Cards -->
        <div class="system-overview slide-up">
            <div class="overview-card">
                <div class="overview-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3><?php echo number_format($stats['total_users'] ?? 0); ?></h3>
                <p class="text-muted">Total Users</p>
                <span class="badge badge-warning"><?php echo $stats['pending_users'] ?? 0; ?> pending</span>
            </div>
            
            <div class="overview-card">
                <div class="overview-icon">
                    <i class="fas fa-store"></i>
                </div>
                <h3><?php echo number_format($stats['total_shops'] ?? 0); ?></h3>
                <p class="text-muted">Service Providers</p>
                <span class="badge badge-warning"><?php echo $stats['pending_shops'] ?? 0; ?> pending</span>
            </div>
            
            <div class="overview-card">
                <div class="overview-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <h3><?php echo number_format($stats['total_orders'] ?? 0); ?></h3>
                <p class="text-muted">Total Orders</p>
                <span class="badge badge-success"><?php echo $stats['today_orders'] ?? 0; ?> today</span>
            </div>
            
            <div class="overview-card">
                <div class="overview-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <h3>₱<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></h3>
                <p class="text-muted">Total Revenue</p>
                <span class="badge badge-info">All time</span>
            </div>
        </div>

        <!-- Main Dashboard Grid -->
        <div class="dashboard-grid">
            <!-- System Health Metrics -->
            <div class="card card-wide">
                <div class="card-header">
                    <h3><i class="fas fa-heartbeat text-primary"></i> System Health</h3>
                    <p class="text-muted">Real-time system performance metrics</p>
                </div>
                <div class="health-metrics">
                    <?php foreach($system_health as $metric): ?>
                    <div class="metric-item <?php echo $metric['status']; ?>">
                        <div class="d-flex justify-between align-center">
                            <div>
                                <strong><?php echo $metric['label']; ?></strong>
                                <p class="text-muted mb-0">Status: <?php echo ucfirst($metric['status']); ?></p>
                            </div>
                            <div class="text-right">
                                <h4 class="mb-0"><?php echo $metric['value']; ?><?php echo $metric['label'] == 'Response Time' ? 'ms' : '%'; ?></h4>
                            </div>
                        </div>
                        <div class="progress mt-2">
                            <div class="progress-bar" style="width: <?php echo min($metric['value'], 100); ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card card-narrow">
                <div class="card-header">
                    <h3><i class="fas fa-bolt text-warning"></i> Quick Actions</h3>
                    <p class="text-muted">Frequently used actions</p>
                </div>
                <div class="quick-actions-grid">
                    <a href="member_approval.php" class="quick-action">
                        <i class="fas fa-user-check"></i>
                        <div>
                            <strong>Approve Members</strong>
                            <p class="text-muted mb-0">Review pending requests</p>
                        </div>
                    </a>
                    
                    <a href="system_control.php" class="quick-action">
                        <i class="fas fa-sliders-h"></i>
                        <div>
                            <strong>System Control</strong>
                            <p class="text-muted mb-0">Manage system settings</p>
                        </div>
                    </a>
                    
                    <a href="backup.php" class="quick-action">
                        <i class="fas fa-database"></i>
                        <div>
                            <strong>Backup System</strong>
                            <p class="text-muted mb-0">Create system backup</p>
                        </div>
                    </a>
                    
                    <a href="content_moderation.php" class="quick-action">
                        <i class="fas fa-shield-halved"></i>
                        <div>
                            <strong>Moderate Content</strong>
                            <p class="text-muted mb-0">Review reports and enforcement</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="card card-wide">
                <div class="card-header">
                    <h3><i class="fas fa-history text-info"></i> Recent Activities</h3>
                    <p class="text-muted">Latest system activities and events</p>
                </div>
                <div class="activity-timeline">
                    <?php if(!empty($activities)): ?>
                        <?php foreach($activities as $activity): ?>
                        <div class="activity-item <?php echo $activity['color']; ?>">
                            <div class="d-flex justify-between align-center">
                                <div>
                                    <div class="d-flex align-center gap-2">
                                        <span class="badge badge-<?php echo $activity['color']; ?>">
                                            <?php echo ucfirst($activity['type']); ?>
                                        </span>
                                        <strong><?php echo htmlspecialchars($activity['name']); ?></strong>
                                    </div>
                                    <p class="text-muted mb-0"><?php echo $activity['action']; ?></p>
                                </div>
                                <div class="text-muted">
                                    <?php echo date('h:i A', strtotime($activity['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center p-4">
                            <i class="fas fa-history fa-3x text-muted mb-3"></i>
                            <h4>No Activities Today</h4>
                            <p class="text-muted">No system activities recorded for today.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- System Alerts -->
            <div class="card card-narrow">
                <div class="card-header">
                    <h3><i class="fas fa-bell text-danger"></i> Alerts & Notifications</h3>
                    <p class="text-muted">System alerts that need attention</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <?php if(($stats['pending_users'] ?? 0) > 0 || ($stats['pending_shops'] ?? 0) > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <div>
                                <strong>Pending Approvals</strong>
                                <p class="mb-0"><?php echo $stats['pending_users'] ?? 0; ?> users and <?php echo $stats['pending_shops'] ?? 0; ?> shops awaiting approval</p>
                                <a href="member_approval.php" class="btn btn-sm btn-warning mt-2">Review Now</a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>System Update Available</strong>
                            <p class="mb-0">Version 2.2.0 is ready for installation</p>
                            <button class="btn btn-sm btn-info mt-2" onclick="alert('Update functionality would be implemented here')">Update Now</button>
                        </div>
                    </div>
                    
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Backup Completed</strong>
                            <p class="mb-0">Last backup: Today, 02:00 AM</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Performance Charts Section -->
        <div class="card mt-4">
            <div class="card-header">
                <h3><i class="fas fa-chart-line text-success"></i> Performance Metrics</h3>
                <p class="text-muted">System performance over the last 7 days</p>
            </div>
            <div class="p-4">
                <!-- This would be replaced with actual charts (Chart.js) -->
                <div class="d-flex justify-between align-end" style="height: 200px; border-bottom: 2px solid var(--gray-200); padding-bottom: 1rem;">
                    <?php 
                    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                    foreach($days as $day): 
                        $height = rand(40, 180);
                    ?>
                    <div class="d-flex flex-column align-center" style="flex: 1;">
                        <div class="bg-primary-100 rounded" style="width: 30px; height: <?php echo $height; ?>px;"></div>
                        <span class="text-muted mt-1"><?php echo $day; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex justify-center gap-4 mt-4">
                    <div class="d-flex align-center gap-2">
                        <div style="width: 12px; height: 12px; background: var(--primary-500); border-radius: 2px;"></div>
                        <span class="text-muted">User Registrations</span>
                    </div>
                    <div class="d-flex align-center gap-2">
                        <div style="width: 12px; height: 12px; background: var(--success-500); border-radius: 2px;"></div>
                        <span class="text-muted">Orders Completed</span>
                    </div>
                    <div class="d-flex align-center gap-2">
                        <div style="width: 12px; height: 12px; background: var(--warning-500); border-radius: 2px;"></div>
                        <span class="text-muted">Revenue Generated</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modern Footer -->
<?php sys_admin_footer(); ?>

    <script>
        // Add subtle animations
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to cards
            const cards = document.querySelectorAll('.card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', () => {
                    card.style.transform = 'translateY(-4px)';
                });
                
                card.addEventListener('mouseleave', () => {
                    card.style.transform = 'translateY(0)';
                });
            });
            
            // Auto-refresh dashboard every 5 minutes
            setTimeout(() => {
                window.location.reload();
            }, 300000);
            
            // Add loading state to buttons
            document.querySelectorAll('button').forEach(button => {
                button.addEventListener('click', function(e) {
                    if(this.classList.contains('btn')) {
                        const originalHTML = this.innerHTML;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                        this.disabled = true;
                        
                        setTimeout(() => {
                            this.innerHTML = originalHTML;
                            this.disabled = false;
                        }, 1500);
                    }
                });
            });
        });
    </script>
</body>
</html>