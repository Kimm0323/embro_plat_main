<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$error = '';
$success = '';

$clients_stmt = $pdo->prepare("
    SELECT DISTINCT u.id AS client_id,
        u.fullname AS client_name,
        s.shop_name,
        s.id AS shop_id
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    JOIN users u ON o.client_id = u.id
    WHERE s.owner_id = ?
    ORDER BY u.fullname
");
$clients_stmt->execute([$owner_id]);
$clients = $clients_stmt->fetchAll();

$selected_client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
$active_client = null;
foreach ($clients as $client) {
    if ($selected_client_id === 0 || $client['client_id'] === $selected_client_id) {
        $active_client = $client;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message = sanitize($_POST['message'] ?? '');
    $receiver_id = (int) ($_POST['receiver_id'] ?? 0);

    if (!$message) {
        $error = 'Please enter a message before sending.';
    } elseif ($receiver_id <= 0) {
        $error = 'Select a client to message.';
    } else {
        $valid_stmt = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id WHERE o.client_id = ? AND s.owner_id = ?");
        $valid_stmt->execute([$receiver_id, $owner_id]);
        if ((int) $valid_stmt->fetchColumn() === 0) {
            $error = 'You can only message clients with active orders in your shop.';
        } else {
            $insert_stmt = $pdo->prepare("INSERT INTO chats (sender_id, receiver_id, message) VALUES (?, ?, ?)");
            $insert_stmt->execute([$owner_id, $receiver_id, $message]);
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
if ($active_client) {
    $partner_id = (int) $active_client['client_id'];

    $mark_stmt = $pdo->prepare("UPDATE chats SET read_status = 1 WHERE receiver_id = ? AND sender_id = ?");
    $mark_stmt->execute([$owner_id, $partner_id]);

    $messages_stmt = $pdo->prepare("
        SELECT c.*, u.fullname AS sender_name
        FROM chats c
        JOIN users u ON c.sender_id = u.id
        WHERE (c.sender_id = ? AND c.receiver_id = ?)
           OR (c.sender_id = ? AND c.receiver_id = ?)
        ORDER BY c.created_at ASC
        LIMIT 200
    ");
    $messages_stmt->execute([$owner_id, $partner_id, $partner_id, $owner_id]);
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
            border-left: 4px solid #0ea5e9;
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
        .message-bubble.owner {
            margin-left: auto;
            background: #0ea5e9;
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
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> Shop Owner
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="reviews.php" class="nav-link">Reviews</a></li>
                <li><a href="messages.php" class="nav-link active">Messages</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="earnings.php" class="nav-link">Earnings</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>

            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header">
            <h2>Client Messages</h2>
            <p class="text-muted">Respond to client questions and confirm order details.</p>
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
                    <p class="text-muted small">Clients with active orders in your shop.</p>
                </div>
                <div class="conversation-list">
                    <?php if (!empty($clients)): ?>
                        <?php foreach ($clients as $client): ?>
                            <?php $is_active = $active_client && $client['client_id'] === $active_client['client_id']; ?>
                            <a class="conversation-item <?php echo $is_active ? 'active' : ''; ?>" href="messages.php?client_id=<?php echo (int) $client['client_id']; ?>">
                                <strong><?php echo htmlspecialchars($client['client_name']); ?></strong>
                                <div class="text-muted small">Shop: <?php echo htmlspecialchars($client['shop_name']); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-3 text-muted">No client conversations yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-window">
                <?php if ($active_client): ?>
                    <div class="mb-3">
                        <h4><?php echo htmlspecialchars($active_client['client_name']); ?></h4>
                        <p class="text-muted">Conversation for <?php echo htmlspecialchars($active_client['shop_name']); ?></p>
                    </div>
                    <div class="message-stream">
                        <?php if (!empty($messages)): ?>
                            <?php foreach ($messages as $message): ?>
                                <?php $is_owner = (int) $message['sender_id'] === $owner_id; ?>
                                <div class="message-bubble <?php echo $is_owner ? 'owner' : 'partner'; ?>">
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
                        <input type="hidden" name="receiver_id" value="<?php echo (int) $active_client['client_id']; ?>">
                        <textarea name="message" class="form-control" placeholder="Write a message to the client..."></textarea>
                        <button type="submit" name="send_message" class="btn btn-primary mt-2"><i class="fas fa-paper-plane"></i> Send</button>
                    </form>
                <?php else: ?>
                    <div class="text-muted">Select a client to view messages.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
