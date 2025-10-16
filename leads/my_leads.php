<?php
/**
 * My Assigned Leads Management System
 * This page displays leads assigned to the logged-in user where interface='leads'
 * Includes search, pagination, and modal view functionality
 */

// Start session management
session_start();

// Authentication check - redirect if not logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear output buffers before redirect
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Get logged-in user ID
$logged_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($logged_user_id <= 0) {
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

/**
 * SEARCH AND PAGINATION PARAMETERS
 */
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$order_id_filter = isset($_GET['order_id_filter']) ? trim($_GET['order_id_filter']) : '';
$customer_name_filter = isset($_GET['customer_name_filter']) ? trim($_GET['customer_name_filter']) : '';
$phone_filter = isset($_GET['phone_filter']) ? trim($_GET['phone_filter']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$pay_status_filter = isset($_GET['pay_status_filter']) ? trim($_GET['pay_status_filter']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

/**
 * DATABASE QUERIES
 * Main query to fetch leads assigned to logged-in user (orders with interface='leads')
 */

// Base SQL for counting total records - FILTERED BY LOGGED USER
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN users u ON i.user_id = u.id
             WHERE i.interface = 'leads' AND i.user_id = $logged_user_id";

// Main query with all required joins - FILTERED BY LOGGED USER
$sql = "SELECT i.*, 
               c.name as customer_name, 
               c.phone as customer_phone,
               u.id as user_id,
               u.name as user_name,
               u.email as user_email,
               i.pay_status as order_pay_status,
               i.created_at,
               i.updated_at
        FROM order_header i 
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN users u ON i.user_id = u.id
        WHERE i.interface = 'leads' AND i.user_id = $logged_user_id";

// Build search conditions
$searchConditions = [];

// General search condition
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        i.order_id LIKE '%$searchTerm%' OR 
                        c.name LIKE '%$searchTerm%' OR 
                        c.phone LIKE '%$searchTerm%' OR 
                        i.issue_date LIKE '%$searchTerm%' OR 
                        i.due_date LIKE '%$searchTerm%' OR 
                        i.total_amount LIKE '%$searchTerm%' OR
                        i.status LIKE '%$searchTerm%' OR 
                        i.pay_status LIKE '%$searchTerm%')";
}

// Specific Order ID filter
if (!empty($order_id_filter)) {
    $orderIdTerm = $conn->real_escape_string($order_id_filter);
    $searchConditions[] = "i.order_id LIKE '%$orderIdTerm%'";
}

// Specific Customer Name filter
if (!empty($customer_name_filter)) {
    $customerNameTerm = $conn->real_escape_string($customer_name_filter);
    $searchConditions[] = "c.name LIKE '%$customerNameTerm%'";
}

// Phone filter
if (!empty($phone_filter)) {
    $phoneTerm = $conn->real_escape_string($phone_filter);
    $searchConditions[] = "c.phone LIKE '%$phoneTerm%'";
}

// Date range filter
if (!empty($date_from)) {
    $dateFromTerm = $conn->real_escape_string($date_from);
    $searchConditions[] = "DATE(i.issue_date) >= '$dateFromTerm'";
}

if (!empty($date_to)) {
    $dateToTerm = $conn->real_escape_string($date_to);
    $searchConditions[] = "DATE(i.issue_date) <= '$dateToTerm'";
}

// Status filter
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "i.status = '$statusTerm'";
}

// Payment Status filter
if (!empty($pay_status_filter)) {
    $payStatusTerm = $conn->real_escape_string($pay_status_filter);
    $searchConditions[] = "i.pay_status = '$payStatusTerm'";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " AND (" . implode(' AND ', $searchConditions) . ")";
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY i.order_id DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);

// Get logged user info for display
$userInfoQuery = "SELECT name, email FROM users WHERE id = $logged_user_id";
$userInfoResult = $conn->query($userInfoQuery);
$userInfo = $userInfoResult->fetch_assoc();

// Include navigation components
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');

?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>My Assigned Leads</title>
    
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

.user-info-banner {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.user-info-banner h6 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.user-info-banner p {
    margin: 5px 0 0 0;
    font-size: 14px;
    opacity: 0.9;
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
                        <h5 class="mb-0 font-medium">My Assigned Leads</h5>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- User Information Banner -->
                <!-- <div class="user-info-banner">
                    <h6>Welcome, <?php echo htmlspecialchars($userInfo['name']); ?></h6>
                    <p>Viewing leads assigned to you (User ID: <?php echo $logged_user_id; ?>)</p>
                </div> -->
                
                <!-- Leads Filter Section -->
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
                        
                        <div class="form-group">
                            <label for="phone_filter">Phone Number</label>
                            <input type="text" id="phone_filter" name="phone_filter" 
                                   placeholder="Enter phone number" 
                                   value="<?php echo htmlspecialchars($phone_filter); ?>">
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
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo ($status_filter == 'pending') ? 'selected' : ''; ?>>Pending</option>
                                <option value="waiting" <?php echo ($status_filter == 'waiting') ? 'selected' : ''; ?>>Waiting</option>
                                <option value="pickup" <?php echo ($status_filter == 'pickup') ? 'selected' : ''; ?>>Pickup</option>
                                <option value="processing" <?php echo ($status_filter == 'processing') ? 'selected' : ''; ?>>Processing</option>
                                <option value="dispatch" <?php echo ($status_filter == 'dispatch') ? 'selected' : ''; ?>>Dispatched</option>
                                <option value="pending to deliver" <?php echo ($status_filter == 'pending to deliver') ? 'selected' : ''; ?>>Pending to Deliver</option>
                                <option value="return" <?php echo ($status_filter == 'return') ? 'selected' : ''; ?>>Return</option>
                                <option value="return complete" <?php echo ($status_filter == 'return complete') ? 'selected' : ''; ?>>Return Complete</option>
                                <option value="return_handover" <?php echo ($status_filter == 'return_handover') ? 'selected' : ''; ?>>Return Handover</option>
                                <option value="delivered" <?php echo ($status_filter == 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                <option value="done" <?php echo ($status_filter == 'done') ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancel" <?php echo ($status_filter == 'cancel') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="pay_status_filter">Payment Status</label>
                            <select id="pay_status_filter" name="pay_status_filter">
                                <option value="">All Payment Status</option>
                                <option value="paid" <?php echo ($pay_status_filter == 'paid') ? 'selected' : ''; ?>>Paid</option>
                                <option value="unpaid" <?php echo ($pay_status_filter == 'unpaid') ? 'selected' : ''; ?>>Unpaid</option>
                            </select>
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

                <!-- Leads Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">My Assigned Leads</div>
                </div>

                <!-- Leads Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer Name</th>
                                <th>Phone Number</th>
                                <th>Total Amount</th>
                                <th>Pay Status</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="leadsTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- Order ID -->
                                        <td class="order-id">
                                            <?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>
                                        </td>
                                        
                                        <!-- Customer Name -->
                                        <td class="customer-name">
                                            <?php echo isset($row['customer_name']) ? htmlspecialchars($row['customer_name']) : 'N/A'; ?>
                                        </td>
                                        
                                        <!-- Phone Number -->
                                        <td class="phone-number">
                                            <?php echo isset($row['customer_phone']) ? htmlspecialchars($row['customer_phone']) : 'N/A'; ?>
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
                                        
                                        <!-- Payment Status Badge -->
                                        <td>
                                            <?php
                                            $payStatus = isset($row['pay_status']) ? $row['pay_status'] : 'unpaid';
                                            if ($payStatus == 'paid'): ?>
                                                <span class="status-badge pay-status-paid">Paid</span>
                                            <?php else: ?>
                                                <span class="status-badge pay-status-unpaid">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Order Status Badge -->
                                        <td>
                                            <?php
                                            $status = isset($row['status']) ? $row['status'] : '';
                                            $statusText = '';
                                            $badgeClass = '';
                                            
                                            switch ($status) {
                                                case 'pending':
                                                    $statusText = 'Pending';
                                                    $badgeClass = 'status-pending';
                                                    break;
                                                case 'waiting':
                                                    $statusText = 'Waiting';
                                                    $badgeClass = 'status-waiting';
                                                    break;
                                                case 'pickup':
                                                    $statusText = 'Pickup';
                                                    $badgeClass = 'status-pickup';
                                                    break;
                                                case 'processing':
                                                    $statusText = 'Processing';
                                                    $badgeClass = 'status-processing';
                                                    break;
                                                case 'dispatch':
                                                    $statusText = 'Dispatched';
                                                    $badgeClass = 'status-dispatched';
                                                    break;
                                                case 'pending to deliver':
                                                case 'reschedule':
                                                case 'date changed':
                                                case 'rearranged':
                                                    $statusText = 'Pending to Deliver';
                                                    $badgeClass = 'status-pending-deliver';
                                                    break;
                                                case 'return':
                                                    $statusText = 'Return';
                                                    $badgeClass = 'status-return';
                                                    break;
                                                case 'return complete':
                                                    $statusText = 'Return Complete';
                                                    $badgeClass = 'status-return-complete';
                                                    break;
                                                case 'return_handover': 
                                                    $statusText = 'Return Handover';
                                                    $badgeClass = 'status-return-handover';
                                                    break;
                                                case 'delivered':
                                                    $statusText = 'Delivered';
                                                    $badgeClass = 'status-delivered';
                                                    break;
                                                case 'done':
                                                    $statusText = 'Completed';
                                                    $badgeClass = 'status-completed';
                                                    break;
                                                case 'cancel':
                                                    $statusText = 'Cancelled';
                                                    $badgeClass = 'status-cancelled';
                                                    break;
                                                default:
                                                    $statusText = $status;
                                                    $badgeClass = 'status-default';
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $badgeClass; ?>"><?php echo $statusText; ?></span>
                                        </td>
                                        
                                        <!-- Action Buttons -->
                                        <td class="actions">
                                            <button class="action-btn view-btn" title="View Lead Details" 
                                                    onclick="openLeadModal('<?php echo isset($row['order_id']) ? htmlspecialchars($row['order_id']) : ''; ?>')">
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
                                    <td colspan="7" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        No leads assigned to you
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
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&status_filter=<?php echo urlencode($status_filter); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&status_filter=<?php echo urlencode($status_filter); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&order_id_filter=<?php echo urlencode($order_id_filter); ?>&customer_name_filter=<?php echo urlencode($customer_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&status_filter=<?php echo urlencode($status_filter); ?>&pay_status_filter=<?php echo urlencode($pay_status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Lead View Modal -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/order_view_modal.php'); ?>

    <script>
    // Lead-specific JavaScript functionality
    let currentLeadId = null;

    // Clear all filter inputs
    function clearFilters() {
        document.getElementById('order_id_filter').value = '';
        document.getElementById('customer_name_filter').value = '';
        document.getElementById('phone_filter').value = '';
        document.getElementById('date_from').value = '';
        document.getElementById('date_to').value = '';
        document.getElementById('status_filter').value = '';
        document.getElementById('pay_status_filter').value = '';
        
        window.location.href = window.location.pathname;
    }

    // Open lead modal
    function openLeadModal(leadId) {
        if (!leadId || leadId.trim() === '') {
            alert('Lead ID is required to view lead details.');
            return;
        }
        
        console.log('Opening modal for Lead ID:', leadId);
        
        currentLeadId = leadId.trim();
        
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
                Loading lead details for Lead ID: ${currentLeadId}...
            </div>
        `;
        downloadBtn.style.display = 'none';
        viewPaymentSlipBtn.style.display = 'none';
        
        // Use leads download PHP file
        const fetchUrl = '../leads/leads_download.php?id=' + encodeURIComponent(currentLeadId);
        
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
            
            // Check for payment slip availability
            checkPaymentSlipAvailability();
        })
        .catch(error => {
            console.error('Error loading lead details:', error);
            modalContent.innerHTML = `
                <div class="modal-error" style="text-align: center; padding: 20px; color: #dc3545;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2em; margin-bottom: 10px;"></i>
                    <h4>Error Loading Lead Details</h4>
                    <p>Lead ID: ${currentLeadId}</p>
                    <p>Error: ${error.message}</p>
                    <p>Please check if the leads_download.php file exists and is accessible.</p>
                    <button onclick="retryLoadLead()" class="btn btn-primary" style="margin-top: 10px;">
                        <i class="fas fa-redo"></i> Retry
                    </button>
                </div>
            `;
        });
    }

    // Check payment slip availability
    function checkPaymentSlipAvailability() {
        if (!currentLeadId) return;
        
        // Fetch payment slip information from server
        fetch('get_payment_slip_info.php?order_id=' + encodeURIComponent(currentLeadId), {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const viewPaymentSlipBtn = document.getElementById('viewPaymentSlipBtn');
                
                if (data.pay_status === 'paid') {
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

    // View payment slip
    function viewPaymentSlip() {
        if (!currentLeadId) {
            alert('No lead selected.');
            return;
        }
        
        // Construct the payment slip URL
        const slipUrl = '/order_management/dist/uploads/payment_slips/' + encodeURIComponent(currentLeadId) + '.jpg';
        
        // Open payment slip in new tab
        window.open(slipUrl, '_blank');
    }

    // Retry loading lead
    function retryLoadLead() {
        if (currentLeadId) {
            openLeadModal(currentLeadId);
        }
    }

    // Close lead modal
    function closeOrderModal() {
        const modal = document.getElementById('orderModal');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        currentLeadId = null;
    }

    // Download lead
    function downloadOrder() {
        if (!currentLeadId) {
            alert('No lead selected for download.');
            return;
        }
        
        const downloadUrl = '../leads/leads_download.php?id=' + encodeURIComponent(currentLeadId) + '&download=1';
        
        console.log('Downloading from:', downloadUrl);
        window.open(downloadUrl, '_blank');
    }

    // Print order function
    function printOrder(orderId) {
        if (!orderId || orderId.trim() === '') {
            alert('Order ID is required to print order.');
            return;
        }
        
        console.log('Printing Order ID:', orderId);
        
        // Construct the print URL
        const printUrl = '/order_management/dist/orders/download_order_print.php?id=' + encodeURIComponent(orderId.trim());

        
        // Open print page in new window
        const printWindow = window.open(printUrl, '_blank');
        
        // Optional: Auto-print when page loads (uncomment if needed)
        // printWindow.onload = function() {
        //     printWindow.print();
        // };
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

    // Initialize page functionality when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        console.log('My Assigned Leads page loaded, initializing...');
        
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