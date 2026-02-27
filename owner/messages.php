<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$error = '';
$success = '';

$team_stmt = $pdo->prepare(" 
    SELECT
        u.id AS contact_id,
        u.fullname AS contact_name,
        MAX(s.shop_name) AS shop_name,
        MAX(s.id) AS shop_id,
        MAX(ch.created_at) AS last_message_at,
        (
            SELECT c2.message
            FROM chats c2
            WHERE (c2.sender_id = u.id AND c2.receiver_id = :owner_id_team_last_message_in)
               OR (c2.sender_id = :owner_id_team_last_message_out AND c2.receiver_id = u.id)
            ORDER BY c2.created_at DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT COUNT(*)
            FROM chats c3
            WHERE c3.sender_id = u.id
               AND c3.receiver_id = :owner_id_team_unread
              AND c3.read_status = 0
        ) AS unread_count,
        CASE WHEN ss.staff_role = 'hr' THEN 'HR' ELSE 'Staff' END AS contact_role
    FROM shops s
    JOIN shop_staffs ss ON ss.shop_id = s.id
    JOIN users u ON ss.user_id = u.id
    LEFT JOIN chats ch
     ON (ch.sender_id = u.id AND ch.receiver_id = :owner_id_team_join_in)
      OR (ch.sender_id = :owner_id_team_join_out AND ch.receiver_id = u.id)
    WHERE s.owner_id = :owner_id_team_where
      AND ss.status = 'active'
    GROUP BY u.id, u.fullname, ss.staff_role
    ORDER BY u.fullname ASC
");
$team_stmt->execute([
    'owner_id_team_last_message_in' => $owner_id,
    'owner_id_team_last_message_out' => $owner_id,
    'owner_id_team_unread' => $owner_id,
    'owner_id_team_join_in' => $owner_id,
    'owner_id_team_join_out' => $owner_id,
    'owner_id_team_where' => $owner_id,
]);
$team_members = $team_stmt->fetchAll();

$clients_stmt = $pdo->prepare("
    SELECT
        u.id AS contact_id,
        u.fullname AS contact_name,
         MAX(s.shop_name) AS shop_name,
        MAX(s.id) AS shop_id,
        MAX(ch.created_at) AS last_message_at,
        (
            SELECT c2.message
            FROM chats c2
            WHERE (c2.sender_id = u.id AND c2.receiver_id = :owner_id_client_last_message_in)
               OR (c2.sender_id = :owner_id_client_last_message_out AND c2.receiver_id = u.id)
            ORDER BY c2.created_at DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT COUNT(*)
            FROM chats c3
            WHERE c3.sender_id = u.id
              AND c3.receiver_id = :owner_id_client_unread
              AND c3.read_status = 0
        ) AS unread_count,
        'Client' AS contact_role
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    JOIN users u ON o.client_id = u.id
    LEFT JOIN chats ch
     ON (ch.sender_id = u.id AND ch.receiver_id = :owner_id_client_join_in)
      OR (ch.sender_id = :owner_id_client_join_out AND ch.receiver_id = u.id)
    WHERE s.owner_id = :owner_id_client_where
    GROUP BY u.id, u.fullname
    ORDER BY
        (MAX(ch.created_at) IS NULL) ASC,
        MAX(ch.created_at) DESC,
        u.fullname ASC
");
$clients_stmt->execute([
    'owner_id_client_last_message_in' => $owner_id,
    'owner_id_client_last_message_out' => $owner_id,
    'owner_id_client_unread' => $owner_id,
    'owner_id_client_join_in' => $owner_id,
    'owner_id_client_join_out' => $owner_id,
    'owner_id_client_where' => $owner_id,
]);
$clients = $clients_stmt->fetchAll();

$selected_partner_id = isset($_GET['partner_id']) ? (int) $_GET['partner_id'] : 0;
$contacts = array_merge($team_members, $clients);
$active_contact = null;
foreach ($contacts as $contact) {
    if ($selected_partner_id === 0 || $contact['contact_id'] === $selected_partner_id) {
        $active_contact = $contact;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $message = sanitize($_POST['message'] ?? '');
    $receiver_id = (int) ($_POST['receiver_id'] ?? 0);

    if (!$message) {
        $error = 'Please enter a message before sending.';
    } elseif ($receiver_id <= 0) {
       $error = 'Select a contact to message.';
    } else {
        $team_access_stmt = $pdo->prepare("SELECT COUNT(*) FROM shop_staffs ss JOIN shops s ON ss.shop_id = s.id WHERE s.owner_id = ? AND ss.user_id = ? AND ss.status = 'active'");
        $team_access_stmt->execute([$owner_id, $receiver_id]);
        $has_team_access = (int) $team_access_stmt->fetchColumn() > 0;

        $client_access_stmt = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id WHERE o.client_id = ? AND s.owner_id = ?");
        $client_access_stmt->execute([$receiver_id, $owner_id]);
        $has_client_access = (int) $client_access_stmt->fetchColumn() > 0;

        if (!$has_team_access && !$has_client_access) {
            $error = 'You can only message your active HR/staff team and clients with orders in your shop.';
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
if ($active_contact) {
    $partner_id = (int) $active_contact['contact_id'];

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
         .conversation-item .preview {
            margin-top: 6px;
            color: #64748b;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .conversation-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }
        .unread-badge {
            background: #0ea5e9;
            color: #fff;
            min-width: 22px;
            height: 22px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
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
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <div class="container">
        <div class="dashboard-header">
            <h2>Inbox</h2>
            <p class="text-muted">Your inbox always includes HR and staff, plus clients who ordered from your shop.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php elseif ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="messages-layout">
            <div>
                <div class="card">
                    <strong>Team Inbox</strong>
                    <p class="text-muted small">HR and staff are always pinned here.</p>
                </div>
                <div class="conversation-list">
                    <?php if (!empty($team_members)): ?>
                        <?php foreach ($team_members as $member): ?>
                            <?php $is_active = $active_contact && $member['contact_id'] === $active_contact['contact_id']; ?>
                            <a class="conversation-item <?php echo $is_active ? 'active' : ''; ?>" href="messages.php?partner_id=<?php echo (int) $member['contact_id']; ?>">
                                <strong><?php echo htmlspecialchars($member['contact_name']); ?></strong>
                                <div class="text-muted small"><?php echo htmlspecialchars($member['contact_role']); ?> · <?php echo htmlspecialchars($member['shop_name']); ?></div>
                                 <div class="preview">
                                    <?php echo !empty($member['last_message']) ? htmlspecialchars($member['last_message']) : 'No messages yet.'; ?>
                                </div>
                                <div class="conversation-meta">
                                    <span class="text-muted small">
                                        <?php echo !empty($member['last_message_at']) ? date('M d, h:i A', strtotime($member['last_message_at'])) : 'Pinned contact'; ?>
                                    </span>
                                    <?php if ((int) $member['unread_count'] > 0): ?>
                                        <span class="unread-badge"><?php echo (int) $member['unread_count']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-3 text-muted">No HR or staff assigned yet.</div>
                    <?php endif; ?>
                </div>

                <div class="card mt-2">
                    <strong>Customer Inbox</strong>
                    <p class="text-muted small">Customers who ordered from your shop.</p>
                </div>
                <div class="conversation-list">
                    <?php if (!empty($clients)): ?>
                        <?php foreach ($clients as $client): ?>
                            <?php $is_active = $active_contact && $client['contact_id'] === $active_contact['contact_id']; ?>
                            <a class="conversation-item <?php echo $is_active ? 'active' : ''; ?>" href="messages.php?partner_id=<?php echo (int) $client['contact_id']; ?>">
                                <strong><?php echo htmlspecialchars($client['contact_name']); ?></strong>
                                <div class="text-muted small"><?php echo htmlspecialchars($client['contact_role']); ?> · <?php echo htmlspecialchars($client['shop_name']); ?></div>
                                <div class="preview">
                                    <?php echo !empty($client['last_message']) ? htmlspecialchars($client['last_message']) : 'No messages yet.'; ?>
                                </div>
                                <div class="conversation-meta">
                                    <span class="text-muted small">
                                        <?php echo !empty($client['last_message_at']) ? date('M d, h:i A', strtotime($client['last_message_at'])) : 'Ordered customer'; ?>
                                    </span>
                                    <?php if ((int) $client['unread_count'] > 0): ?>
                                        <span class="unread-badge"><?php echo (int) $client['unread_count']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-3 text-muted">No client conversations yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-window">
                <?php if ($active_contact): ?>
                    <div class="mb-3">
                        <h4><?php echo htmlspecialchars($active_contact['contact_name']); ?></h4>
                        <p class="text-muted"><?php echo htmlspecialchars($active_contact['contact_role']); ?> conversation · <?php echo htmlspecialchars($active_contact['shop_name']); ?></p>
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
                        <input type="hidden" name="receiver_id" value="<?php echo (int) $active_contact['contact_id']; ?>">
                        <textarea name="message" class="form-control" placeholder="Write a message..."></textarea>
                        <button type="submit" name="send_message" class="btn btn-primary mt-2"><i class="fas fa-paper-plane"></i> Send</button>
                    </form>
                <?php else: ?>
                    <div class="text-muted">Select a contact to view messages.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
