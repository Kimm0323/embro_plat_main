<?php
// System Constants
define('SITE_NAME', 'Embroidery Platform');
define('SITE_URL', 'http://localhost/web-app/');
define('UPLOAD_PATH', $_SERVER['DOCUMENT_ROOT'] . '/web-app/assets/uploads/');

// User Roles
define('ROLE_SYS_ADMIN', 'sys_admin');
define('ROLE_OWNER', 'owner');
define('ROLE_HR', 'hr');
define('ROLE_STAFF', 'staff');
define('ROLE_staff', 'staff');
define('ROLE_CLIENT', 'client');

// Session settings
define('SESSION_TIMEOUT_SECONDS', 30 * 60);

// Order Statuses
define('STATUS_PENDING', 'pending');
define('STATUS_ACCEPTED', 'accepted');
define('STATUS_REJECTED', 'rejected');
define('STATUS_IN_PROGRESS', 'in_progress');
define('STATUS_COMPLETED', 'completed');
define('STATUS_CANCELLED', 'cancelled');

// Fulfillment Statuses
define('FULFILLMENT_PENDING', 'pending');
define('FULFILLMENT_READY_FOR_PICKUP', 'ready_for_pickup');
define('FULFILLMENT_OUT_FOR_DELIVERY', 'out_for_delivery');
define('FULFILLMENT_DELIVERED', 'delivered');
define('FULFILLMENT_CLAIMED', 'claimed');
define('FULFILLMENT_FAILED', 'failed');

// Shop Statuses
define('SHOP_PENDING', 'pending');
define('SHOP_ACTIVE', 'active');
define('SHOP_SUSPENDED', 'suspended');

// File upload settings
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);
define('ALLOWED_DOC_TYPES', ['pdf', 'doc', 'docx']);
define('MEDIA_RETENTION_DAYS', 180);
?>