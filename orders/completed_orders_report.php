<?php
/**
 * Paid Completed Orders Management System
 * This page displays only completed & paid orders with enhanced UI
 * Includes advanced search, pagination, and modal view functionality
 */
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Current user info
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user_role = $_SESSION['role_id'] ?? 0;

// If user_id or role_id is not in session, fetch from database
if ($current_user_id == 0 || $current_user_role == 0) {
    $session_identifier = isset($_SESSION['username']) ? $_SESSION['username'] : 
                         (isset($_SESSION['email']) ? $_SESSION['email'] : '');
    
    if ($session_identifier) {
        $userQuery = "SELECT u.id, u.role_id FROM users u WHERE u.email = ? OR u.name = ? LIMIT 1";
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param("ss", $session_identifier, $session_identifier);
        $stmt->execute();
        $userResult = $stmt->get_result();
        
        if ($userResult && $userResult->num_rows > 0) {
            $userData = $userResult->fetch_assoc();
            $current_user_id = (int)$userData['id'];
            $current_user_role = (int)$userData['role_id'];
            
            $_SESSION['user_id'] = $current_user_id;
            $_SESSION['role_id'] = $current_user_role;
        }
        $stmt->close();
    }
}

if ($current_user_id == 0) {
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Filters
$order_id_filter = $_GET['order_id_filter'] ?? '';
$customer_name_filter = $_GET['customer_name_filter'] ?? '';
$user_id_filter = $_GET['user_id_filter'] ?? '';
$tracking_id = $_GET['tracking_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$limit = (int)($_GET['limit'] ?? 10);
$page = (int)($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;

// Role condition
$roleCondition = ($current_user_role != 1) ? " AND i.user_id = $current_user_id" : "";

// Build WHERE conditions first
$whereConditions = "WHERE i.status='done' AND i.pay_status='paid' $roleCondition";

// Apply filters
if ($order_id_filter) $whereConditions .= " AND i.order_id LIKE '%" . $conn->real_escape_string($order_id_filter) . "%'";
if ($customer_name_filter) $whereConditions .= " AND c.name LIKE '%" . $conn->real_escape_string($customer_name_filter) . "%'";
if ($user_id_filter && ($current_user_role==1 || $user_id_filter==$current_user_id)) $whereConditions .= " AND i.user_id='" . $conn->real_escape_string($user_id_filter) . "'";
if ($tracking_id) $whereConditions .= " AND i.tracking_number LIKE '%" . $conn->real_escape_string($tracking_id) . "%'";
if ($date_from) $whereConditions .= " AND DATE(i.issue_date) >= '" . $conn->real_escape_string($date_from) . "'";
if ($date_to) $whereConditions .= " AND DATE(i.issue_date) <= '" . $conn->real_escape_string($date_to) . "'";

// Count query
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u2 ON i.user_id = u2.id
             $whereConditions";

$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);

// Main query with all joins
$sql = "SELECT i.*, c.name AS customer_name, 
               p.payment_id, p.amount_paid, p.payment_method, p.payment_date, p.pay_by,
               u1.name as paid_by_name,
               u2.name AS user_name,
               i.slip as payment_slip, i.pay_status as order_pay_status,
               i.created_at as order_created_at
        FROM order_header i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN payments p ON i.order_id = p.order_id
        LEFT JOIN users u1 ON p.pay_by = u1.id
        LEFT JOIN users u2 ON i.user_id = u2.id
        $whereConditions";

// Pagination
$sql .= " ORDER BY i.order_id DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

// Fetch users for admin filter dropdown
$usersResult = $conn->query("SELECT id, name FROM users ORDER BY name ASC");

// Include UI components
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Paid Completed Orders</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <style>
.print-btn {
    background-color: #28a745;
    color: white;
    border: none;
    padding: 8px 10px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    margin-left: 5px;
    transition: background-color 0.3s;
}

.print-btn:hover {
    background-color: #218838;
}

.print-btn:active {
    transform: scale(0.95);
}

.actions {
    white-space: nowrap;
}

/* Style for Created Time column */
.created-time {
    white-space: nowrap;
    font-size: 0.9em;
    color: #666;
}

.created-date {
    display: block;
    font-weight: 500;
    color: #333;
}

.created-time-only {
    display: block;
    font-size: 0.8em;
    color: #999;
}
</style>
</head>

<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/loader.php'); ?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Paid Completed Orders</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- Order Tracking and Filter Section -->
                <div class="tracking-container">
                   
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="order_id_filter">Order ID</label>
                            <input type="text" id="order_id_filter" name="order_id_filter" 
                                   placeholder="Enter order ID" 
                                   value="<?php echo htmlspecialchars($order_id_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="customer_name_filter">Customer Name</label>
                            <input type="text" id="customer_name_filter" name="customer_name_filter" 
                                   placeholder="Enter customer name" 
                                   value="<?php echo htmlspecialchars($customer_name_filter); ?>">
                        </div>
                        
                        <!-- User ID Filter - Only show for admin users -->
                        <?php if ($current_user_role == 1): ?>
                            <div class="form-group">
                                <label for="user_id_filter">User</label>
                                <select id="user_id_filter" name="user_id_filter">
                                    <option value="">All Users</option>
                                    <?php if ($usersResult && $usersResult->num_rows > 0): ?>
                                        <?php while ($userRow = $usersResult->fetch_assoc()): ?>
                                            <option value="<?php echo htmlspecialchars($userRow['id']); ?>" 
                                                    <?php echo ($user_id_filter == $userRow['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($userRow['name']) . ' (ID: ' . $userRow['id'] . ')'; ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="tracking_id">Tracking ID</label>
                            <input type="text" id="tracking_id" name="tracking_id" 
                                   placeholder="Enter tracking ID" 
                                   value="<?php echo htmlspecialchars($tracking_id); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_from">Date From</label>
                            <input type="date" id="date_from" name="date_from" 
                                   value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="date_to">Date To</label>
                            <input type="date" id="date_to" name="date_to" 
                                   value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        
                        <div class="form-group">
                            <div class="button-group">
                                <button type="submit" class="search-btn">
                                    <i class="fas fa-search"></i>
                                    Search
                                </button>
                                <button type="button" class="search-btn" onclick="clearFilters()" style="background: #6c757d;">
                                    <i class="fas fa-times"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Order Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">
                        <?php echo ($current_user_role == 1) ? 'Total Paid Completed Orders' : 'Total Paid Completed Orders'; ?>
                    </div>
                </div>

                <!-- Orders Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Created Time</th>
                                <th>Customer Name</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th>Pay Status</th>
                                <th>Tracking Number</th>
                                <th>Processed By</th>
                                <?php if ($current_user_role == 1): ?>
                                    <th>User</th>
                                <?php endif; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ordersTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- Order ID -->
                                        <td class="order-id">
                                            <?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>
                                        </td>
                                        
                                        <!-- Created Time Column -->
                                        <td class="created-time">
                                            <?php
                                            if (isset($row['order_created_at']) && !empty($row['order_created_at'])) {
                                                $createdAt = new DateTime($row['order_created_at']);
                                                echo '<span class="created-date">' . $createdAt->format('Y-m-d') . '</span>';
                                                echo '<span class="created-time-only">' . $createdAt->format('H:i:s') . '</span>';
                                            } else {
                                                echo '<span style="color: #999; font-style: italic;">N/A</span>';
                                            }
                                            ?>
                                        </td>
                                        
                                        <!-- Customer Name with ID -->
                                        <td class="customer-name">
                                            <?php
                                            $customerName = isset($row['customer_name']) ? htmlspecialchars($row['customer_name']) : 'N/A';
                                            $customerId = isset($row['customer_id']) ? htmlspecialchars($row['customer_id']) : '';
                                            echo $customerName . ($customerId ? " ($customerId)" : "");
                                            ?>
                                        </td>
                                       
                                        <!-- Total Amount with Currency -->
                                        <td class="amount">
                                            <?php
                                            $amount = isset($row['total_amount']) ? (float)$row['total_amount'] : 0;
                                            $currency = isset($row['currency']) ? $row['currency'] : 'lkr';
                                            $currencySymbol = ($currency == 'usd') ? '$' : 'Rs';
                                            echo $currencySymbol . number_format($amount, 2);
                                            ?>
                                        </td>
                                        
                                        <!-- Order Status Badge -->
                                        <td>
                                            <span class="status-badge status-completed">Completed</span>
                                        </td>
                                        
                                        <!-- Payment Status Badge -->
                                        <td>
                                            <span class="status-badge pay-status-paid">Paid</span>
                                        </td>
                                        
                                        <!-- Tracking Number -->
                                        <td class="tracking-number">
                                            <?php
                                            if (isset($row['tracking_number']) && !empty($row['tracking_number'])) {
                                                echo htmlspecialchars($row['tracking_number']);
                                            } else {
                                                echo '<span style="color: #999; font-style: italic;">Not assigned</span>';
                                            }
                                            ?>
                                        </td>
                                        
                                        <!-- Processed By User -->
                                        <td>
                                            <?php
                                            echo isset($row['paid_by_name']) ? htmlspecialchars($row['paid_by_name']) : 'N/A';
                                            ?>
                                        </td>
                                        
                                        <!-- User Column - Only show for admin users -->
                                        <?php if ($current_user_role == 1): ?>
                                            <td>
                                                <?php
                                                $userName = isset($row['user_name']) ? htmlspecialchars($row['user_name']) : 'N/A';
                                                $interface = isset($row['interface']) ? $row['interface'] : '';
                                                $userId = isset($row['user_id']) ? htmlspecialchars($row['user_id']) : '';
                                                
                                                echo $userName;
                                                
                                                // Display user ID in small text
                                                if ($userId) {
                                                    echo "<br><span style='color: #666; font-size: 0.8em;'>ID: $userId</span>";
                                                }
                                                
                                                // Display (leads) if interface is 'leads'
                                                if ($interface == 'leads') {
                                                    echo "<br><span style='color: #666; font-size: 0.9em;'>(leads)</span>";
                                                }
                                                ?>
                                            </td>
                                        <?php endif; ?>
                                        
                                        <!-- Action Buttons -->
                                        <td class="actions">
                                            <button class="action-btn view-btn" title="View Order Details" 
                                                    onclick="openOrderModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>', '<?php echo isset($row['interface']) ? htmlspecialchars($row['interface']) : ''; ?>')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <!-- Print Button -->
                                            <button class="action-btn print-btn" title="Print Order" 
                                                    onclick="printOrder('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
                                                <i class="fas fa-print"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo ($current_user_role == 1) ? '10' : '9'; ?>" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        No paid completed orders found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> entries
                    </div>
                    <div class="pagination-controls">
                        <?php if ($page > 1): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&user_id_filter=<?php echo urlencode($user_id_filter); ?>&tracking_id=<?php echo urlencode($tracking_id); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Order View Modal -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/order_view_modal.php'); ?>

    <script>
// Enhanced JavaScript functionality matching the second code

let currentOrderId = null;
let currentInterface = null;
let currentPaymentSlip = null;
let currentPayStatus = null;

// Current user role from PHP
const currentUserRole = <?php echo $current_user_role; ?>;
const currentUserId = <?php echo $current_user_id; ?>;

// Clear all filter inputs
function clearFilters() {
    document.getElementById('order_id_filter').value = '';
    document.getElementById('customer_name_filter').value = '';
    document.getElementById('tracking_id').value = '';
    document.getElementById('date_from').value = '';
    document.getElementById('date_to').value = '';

    // Only clear user_id_filter for admin users (if it exists)
    const userIdFilter = document.getElementById('user_id_filter');
    if (userIdFilter && currentUserRole == 1) {
        userIdFilter.value = '';
    }
    
    window.location.href = window.location.pathname;
}

// Enhanced openOrderModal function
function openOrderModal(orderId, interface = null) {
    if (!orderId || orderId.trim() === '') {
        alert('Order ID is required to view order details.');
        return;
    }
    
    console.log('Opening modal for Order ID:', orderId, 'Interface:', interface);
    
    currentOrderId = orderId.trim();
    currentInterface = interface;
    
    const modal = document.getElementById('orderModal');
    const modalContent = document.getElementById('modalContent');
    const downloadBtn = document.getElementById('downloadBtn');
    const viewPaymentSlipBtn = document.getElementById('viewPaymentSlipBtn');
    
    // Show modal
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Show loading state
    modalContent.innerHTML = `
        <div class="modal-loading">
            <i class="fas fa-spinner fa-spin"></i>
            Loading ${interface === 'leads' ? 'lead' : 'order'} details for Order ID: ${currentOrderId}...
        </div>
    `;
    downloadBtn.style.display = 'none';
    viewPaymentSlipBtn.style.display = 'none';
    
    // Determine which PHP file to use based on interface
    const phpFile = (interface === 'leads') ? '../leads/leads_download.php' : 'download_order.php';
    const fetchUrl = phpFile + '?id=' + encodeURIComponent(currentOrderId);
    
    console.log('Fetching from:', fetchUrl);
    
    fetch(fetchUrl, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        }
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(data => {
        console.log('Data received:', data.length, 'characters');
        if (data.trim() === '') {
            throw new Error('No data received from server');
        }
        modalContent.innerHTML = data;
        downloadBtn.style.display = 'inline-flex';
        
        // Check for payment slip and update button visibility
        checkPaymentSlipAvailability();
    })
    .catch(error => {
        console.error('Error loading order details:', error);
        const itemType = (interface === 'leads') ? 'lead' : 'order';
        modalContent.innerHTML = `
            <div class="modal-error" style="text-align: center; padding: 20px; color: #dc3545;">
                <i class="fas fa-exclamation-triangle" style="font-size: 2em; margin-bottom: 10px;"></i>
                <h4>Error Loading ${itemType.charAt(0).toUpperCase() + itemType.slice(1)} Details</h4>
                <p>Order ID: ${currentOrderId}</p>
                <p>Error: ${error.message}</p>
                <p>Please check if the ${phpFile} file exists and is accessible.</p>
                <button onclick="retryLoadOrder()" class="btn btn-primary" style="margin-top: 10px;">
                    <i class="fas fa-redo"></i> Retry
                </button>
            </div>
        `;
    });
}

// Function to check payment slip availability
function checkPaymentSlipAvailability() {
    if (!currentOrderId) return;
    
    // Fetch payment slip information from server
    fetch('get_payment_slip_info.php?order_id=' + encodeURIComponent(currentOrderId), {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            currentPaymentSlip = data.payment_slip;
            currentPayStatus = data.pay_status;
            
            const viewPaymentSlipBtn = document.getElementById('viewPaymentSlipBtn');
            
            // Show button for all paid orders
            if (currentPayStatus === 'paid') {
                viewPaymentSlipBtn.style.display = 'inline-flex';
            } else {
                viewPaymentSlipBtn.style.display = 'none';
            }
        } else {
            console.log('No payment slip information available');
        }
    })
    .catch(error => {
        console.error('Error checking payment slip:', error);
    });
}

// Function to view payment slip
function viewPaymentSlip() {
    // Check if payment slip exists
    if (!currentPaymentSlip || currentPaymentSlip.trim() === '') {
        alert('This order has no payment slip.');
        return;
    }
    
    // Construct the payment slip URL
    const slipUrl = '/order_management/dist/uploads/payment_slips/' + encodeURIComponent(currentPaymentSlip);
    
    // Open payment slip in new tab
    window.open(slipUrl, '_blank');
}

// Retry loading order 
function retryLoadOrder() {
    if (currentOrderId) {
        openOrderModal(currentOrderId, currentInterface);
    }
}

// Close order modal 
function closeOrderModal() {
    const modal = document.getElementById('orderModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    currentOrderId = null;
    currentInterface = null;
    currentPaymentSlip = null;
    currentPayStatus = null;
}

// Download order 
function downloadOrder() {
    if (!currentOrderId) {
        alert('No order selected for download.');
        return;
    }
    
    const phpFile = (currentInterface === 'leads') ? '../leads/leads_download.php' : 'download_order.php';
    const downloadUrl = phpFile + '?id=' + encodeURIComponent(currentOrderId) + '&download=1';
    
    console.log('Downloading from:', downloadUrl);
    window.open(downloadUrl, '_blank');
}

// Close modal when clicking outside 
document.getElementById('orderModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeOrderModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeOrderModal();
    }
});

// Print order function
function printOrder(orderId) {
    if (!orderId || orderId.trim() === '') {
        alert('Order ID is required to print order.');
        return;
    }
    
    console.log('Printing Order ID:', orderId);
    
    // Construct the print URL
    const printUrl = 'download_order_print.php?id=' + encodeURIComponent(orderId.trim());
    
    // Open print page in new window
    const printWindow = window.open(printUrl, '_blank');
}

// Initialize page functionality when DOM is loaded 
document.addEventListener('DOMContentLoaded', function() {
    console.log('Paid completed orders page loaded, initializing...');
    
    const tableRows = document.querySelectorAll('.orders-table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.transform = 'translateX(2px)';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.transform = 'translateX(0)';
        });
    });
    
    const modal = document.getElementById('orderModal');
    const modalContent = document.getElementById('modalContent');
    if (!modal || !modalContent) {
        console.error('Modal elements not found! Check HTML structure.');
    }
});
</script>

    <!-- Include Footer and Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php'); ?>

</body>
</html>