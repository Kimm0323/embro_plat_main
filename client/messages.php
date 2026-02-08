<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$error = '';
$success = '';

$shops_stmt = $pdo->prepare("
    SELECT DISTINCT s.id AS shop_id,
        s.shop_name,
        s.owner_id,
        u.fullname AS owner_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    JOIN users u ON s.owner_id = u.id
    WHERE o.client_id = ?
    ORDER BY s.shop_name
");
$shops_stmt->execute([$client_id]);
$shops = $shops_stmt->fetchAll();

$selected_shop_id = isset($_GET['shop_id']) ? (int) $_GET['shop_id'] : 0;
$active_shop = null;
foreach ($shops as $shop) {
    if ($selected_shop_id === 0 || $shop['shop_id'] === $selected_shop_id) {
        $active_shop = $shop;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message = sanitize($_POST['message'] ?? '');
    $receiver_id = (int) ($_POST['receiver_id'] ?? 0);

    if (!$message) {
        $error = 'Please enter a message before sending.';
    } elseif ($receiver_id <= 0) {
        $error = 'Select a shop to message.';
    } else {
        $valid_stmt = $pdo->prepare("SELECT COUNT(*) FROM shops s JOIN orders o ON o.shop_id = s.id WHERE s.owner_id = ? AND o.client_id = ?");
        $valid_stmt->execute([$receiver_id, $client_id]);
        if ((int) $valid_stmt->fetchColumn() === 0) {
            $error = 'You can only message shops you have orders with.';
        } else {
            $insert_stmt = $pdo->prepare("INSERT INTO chats (sender_id, receiver_id, message) VALUES (?, ?, ?)");
            $insert_stmt->execute([$client_id, $receiver_id, $message]);
            create_notification(
                $pdo,
                $receiver_id,
                null,
                'message',
                'New message from ' . $_SESSION['user']['fullname'] . '.'
            );
            $success = 'Message sent.';
        }
    }
}

$messages = [];
if ($active_shop) {
    $partner_id = (int) $active_shop['owner_id'];

    $mark_stmt = $pdo->prepare("UPDATE chats SET read_status = 1 WHERE receiver_id = ? AND sender_id = ?");
    $mark_stmt->execute([$client_id, $partner_id]);

    $messages_stmt = $pdo->prepare("
        SELECT c.*, u.fullname AS sender_name
        FROM chats c
        JOIN users u ON c.sender_id = u.id
        WHERE (c.sender_id = ? AND c.receiver_id = ?)
           OR (c.sender_id = ? AND c.receiver_id = ?)
        ORDER BY c.created_at ASC
        LIMIT 200
    ");
    $messages_stmt->execute([$client_id, $partner_id, $partner_id, $client_id]);
    $messages = $messages_stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .messages-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        .conversation-list {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            max-height: 600px;
            overflow-y: auto;
        }
        .conversation-item {
            display: block;
            padding: 16px;
            border-bottom: 1px solid #edf2f7;
            color: inherit;
            text-decoration: none;
        }
        .conversation-item.active {
            background: #f8fafc;
            border-left: 4px solid #4f46e5;
        }
        .chat-window {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            padding: 20px;
            min-height: 400px;
            display: flex;
            flex-direction: column;
        }
        .message-stream {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        .message-bubble {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 16px;
            margin-bottom: 12px;
            position: relative;
        }
        .message-bubble.client {
            margin-left: auto;
            background: #4f46e5;
            color: #fff;
        }
        .message-bubble.partner {
            margin-right: auto;
            background: #edf2f7;
        }
        .message-meta {
            font-size: 12px;
            margin-top: 6px;
            opacity: 0.7;
        }
        .message-form textarea {
            width: 100%;
            min-height: 90px;
            resize: vertical;
        }
        @media (max-width: 960px) {
            .messages-layout {
                grid-template-columns: 1fr;
            }
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
                <li><a href="messages.php" class="nav-link active">Messages</a></li>
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
            <h2>Messages & Clarifications</h2>
            <p class="text-muted">Talk directly with shop owners about orders, revisions, and requirements.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php elseif ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="messages-layout">
            <div>
                <div class="card">
                    <strong>Conversations</strong>
                    <p class="text-muted small">Shops you have placed orders with.</p>
                </div>
                <div class="conversation-list">
                    <?php if (!empty($shops)): ?>
                        <?php foreach ($shops as $shop): ?>
                            <?php $is_active = $active_shop && $shop['shop_id'] === $active_shop['shop_id']; ?>
                            <a class="conversation-item <?php echo $is_active ? 'active' : ''; ?>" href="messages.php?shop_id=<?php echo (int) $shop['shop_id']; ?>">
                                <strong><?php echo htmlspecialchars($shop['shop_name']); ?></strong>
                                <div class="text-muted small">Owner: <?php echo htmlspecialchars($shop['owner_name']); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-3 text-muted">No shops available yet. Place an order to start a conversation.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-window">
                <?php if ($active_shop): ?>
                    <div class="mb-3">
                        <h4><?php echo htmlspecialchars($active_shop['shop_name']); ?></h4>
                        <p class="text-muted">Chatting with <?php echo htmlspecialchars($active_shop['owner_name']); ?></p>
                    </div>
                    <div class="message-stream">
                        <?php if (!empty($messages)): ?>
                            <?php foreach ($messages as $message): ?>
                                <?php $is_client = (int) $message['sender_id'] === $client_id; ?>
                                <div class="message-bubble <?php echo $is_client ? 'client' : 'partner'; ?>">
                                    <div><?php echo nl2br(htmlspecialchars($message['message'])); ?></div>
                                    <div class="message-meta">
                                        <?php echo htmlspecialchars($message['sender_name']); ?> · <?php echo date('M d, Y h:i A', strtotime($message['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted">No messages yet. Start the conversation below.</div>
                        <?php endif; ?>
                    </div>
                    <form class="message-form" method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="receiver_id" value="<?php echo (int) $active_shop['owner_id']; ?>">
                        <textarea name="message" class="form-control" placeholder="Write a message to the shop owner..."></textarea>
                        <button type="submit" name="send_message" class="btn btn-primary mt-2"><i class="fas fa-paper-plane"></i> Send</button>
                    </form>
                <?php else: ?>
                    <div class="text-muted">Select a shop to view messages.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
