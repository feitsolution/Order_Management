<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) ob_end_clean();
    header("Location: /order_management/dist/pages/login.php");
    exit();
}

include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Get current user info
$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user_role = $_SESSION['role_id'] ?? 0;

// Filters
$search = $_GET['search'] ?? '';
$order_id_filter = $_GET['order_id_filter'] ?? '';
$customer_name_filter = $_GET['customer_name_filter'] ?? '';
$tracking_id = $_GET['tracking_id'] ?? '';
$courier_id_filter = $_GET['courier_id_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? ''; // Status filter
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$limit = $_GET['limit'] ?? 20;
$page = $_GET['page'] ?? 1;
$offset = ($page - 1) * $limit;

// Role-based access
$roleCondition = ($current_user_role != 1) ? " AND i.user_id = $current_user_id" : "";

// Base SQL with updated logic for return_handover
$sql = "SELECT i.order_id, i.customer_id, c.name AS customer_name, i.tracking_number,
               i.total_amount, 
               CASE 
                   WHEN i.status = 'return_handover' THEN COALESCE(co.return_fee_value, 0)
                   ELSE i.delivery_fee
               END AS delivery_fee,
               i.subtotal, i.status,
               co.courier_name, 
               COALESCE(co.return_fee_value, 0) AS return_fee_value,
               CASE 
                   WHEN i.status = 'delivered' THEN i.subtotal
                   WHEN i.status = 'done' THEN i.subtotal
                   WHEN i.status = 'return_handover' THEN 0
                   WHEN COALESCE(co.return_fee_value, 0) = 0 THEN 0
                   ELSE ROUND(i.delivery_fee * (COALESCE(co.return_fee_value, 0) / 100), 2)
               END AS after_amount
        FROM order_header i
        LEFT JOIN customers c ON i.customer_id = c.customer_id
        LEFT JOIN couriers co ON i.courier_id = co.courier_id
        WHERE 1 $roleCondition";

// Search filters
$searchConditions = [];

// Only show 'delivered', 'return_handover', and 'done' if no status filter is selected
if (!$status_filter) {
    $searchConditions[] = "(i.status='delivered' OR i.status='return_handover' OR i.status='done')";
} else {
    $searchConditions[] = "i.status = '" . $conn->real_escape_string($status_filter) . "'";
}

if ($search) $searchConditions[] = "(i.order_id LIKE '%$search%' OR c.name LIKE '%$search%' OR i.tracking_number LIKE '%$search%')";
if ($order_id_filter) $searchConditions[] = "i.order_id LIKE '%" . $conn->real_escape_string($order_id_filter) . "%'";
if ($customer_name_filter) $searchConditions[] = "c.name LIKE '%" . $conn->real_escape_string($customer_name_filter) . "%'";
if ($tracking_id) $searchConditions[] = "i.tracking_number LIKE '%" . $conn->real_escape_string($tracking_id) . "%'";
if ($courier_id_filter) $searchConditions[] = "i.courier_id = '" . $conn->real_escape_string($courier_id_filter) . "'";
if ($date_from) $searchConditions[] = "DATE(i.issue_date) >= '" . $conn->real_escape_string($date_from) . "'";
if ($date_to) $searchConditions[] = "DATE(i.issue_date) <= '" . $conn->real_escape_string($date_to) . "'";

if ($searchConditions) $sql .= " AND " . implode(' AND ', $searchConditions);

$sql .= " ORDER BY i.order_id DESC LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

// Count total rows - fix the count query to match the main query conditions
$countSql = "SELECT COUNT(*) as total FROM order_header i 
             LEFT JOIN customers c ON i.customer_id = c.customer_id
             LEFT JOIN couriers co ON i.courier_id = co.courier_id
             WHERE 1 $roleCondition";
if ($searchConditions) $countSql .= " AND " . implode(' AND ', $searchConditions);
$totalRows = (int)$conn->query($countSql)->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $limit);

// Fetch couriers for filter
$couriersResult = $conn->query("SELECT courier_id, courier_name FROM couriers ORDER BY courier_name ASC");

// Include navbar & sidebar
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/navbar.php');
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/sidebar.php');
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Payment Report</title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/head.php'); ?>
    <link rel="stylesheet" href="../assets/css/style.css" />
    <link rel="stylesheet" href="../assets/css/orders.css" />
    <style>
        .total-row { font-weight:bold; background:#f9f9f9; }
        .tracking-container .form-group { margin-right: 15px; margin-bottom: 10px; }
        .return-fee-info { 
            font-size: 0.9em; 
            color: #666; 
            font-style: italic; 
        }
        .return-summary-container {
            animation: slideIn 0.3s ease-in-out;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
        }
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
        .unpaid-mark {
            color: #dc3545;
            font-style: italic;
            font-weight: bold;
        }
        .return-fee-mark {
            color: #28a745;
            font-weight: bold;
        }
        .no-return-mark {
            color: #6c757d;
            font-style: italic;
        }
        .subtotal-mark {
            color: #007bff;
            font-weight: bold;
        }
    </style>
</head>

<body>
<div class="pc-container">
    <div class="pc-content">
        <div class="page-header">
            <div class="page-block">
                <div class="page-header-title">
                    <h5 class="mb-0 font-medium">Return Payment Report</h5>
                </div>
            </div>
        </div>

        <div class="tracking-container">
            <form class="tracking-form" method="GET">
                <div class="form-group">
                    <label>Order ID</label>
                    <input type="text" name="order_id_filter" value="<?= htmlspecialchars($order_id_filter) ?>" placeholder="Order ID">
                </div>
                <div class="form-group">
                    <label>Customer</label>
                    <input type="text" name="customer_name_filter" value="<?= htmlspecialchars($customer_name_filter) ?>" placeholder="Customer Name">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status_filter">
                        <option value="">All Statuses</option>
                        <option value="delivered" <?= ($status_filter=='delivered')?'selected':'' ?>>Delivered</option>
                        <option value="return_handover" <?= ($status_filter=='return_handover')?'selected':'' ?>>Return Handover</option>
                        <option value="done" <?= ($status_filter=='done')?'selected':'' ?>>Complete</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tracking ID</label>
                    <input type="text" name="tracking_id" value="<?= htmlspecialchars($tracking_id) ?>" placeholder="Tracking ID">
                </div>
                <div class="form-group">
                    <label>Courier</label>
                    <select name="courier_id_filter">
                        <option value="">All Couriers</option>
                        <?php if($couriersResult && $couriersResult->num_rows>0): while($rowC = $couriersResult->fetch_assoc()): ?>
                            <option value="<?= $rowC['courier_id'] ?>" <?= ($courier_id_filter==$rowC['courier_id'])?'selected':'' ?>>
                                <?= htmlspecialchars($rowC['courier_name']) ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="form-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="form-group">
                    <button type="submit" class="search-btn"><i class="fas fa-search"></i> Search</button>
                    <button type="button" class="search-btn" style="background:#6c757d;" onclick="window.location.href='payment_report.php'">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </form>
        </div>

        <div class="order-count-container">
            <div class="order-count-number"><?= number_format($totalRows) ?></div>
            <div class="order-count-dash">-</div>
            <div class="order-count-subtitle">Total Orders</div>
        </div>

        <?php 
        // Calculate return summary totals for all filtered results (not just current page)
        $summarySql = "SELECT 
                       COUNT(*) as total_orders,
                       SUM(i.total_amount) as sum_total_amount,
                       SUM(i.subtotal) as sum_subtotal,
                       SUM(CASE 
                           WHEN i.status = 'return_handover' THEN COALESCE(co.return_fee_value, 0)
                           ELSE i.delivery_fee
                       END) as sum_delivery_fee,
                       SUM(CASE 
                           WHEN i.status = 'delivered' THEN i.subtotal
                           WHEN i.status = 'done' THEN i.subtotal
                           WHEN i.status = 'return_handover' THEN 0
                           WHEN COALESCE(co.return_fee_value, 0) = 0 THEN 0
                           ELSE ROUND(i.delivery_fee * (COALESCE(co.return_fee_value, 0) / 100), 2)
                       END) as sum_after_amount,
                       COUNT(CASE WHEN i.status = 'delivered' THEN 1 END) as delivered_orders,
                       COUNT(CASE WHEN i.status = 'return_handover' THEN 1 END) as return_orders,
                       COUNT(CASE WHEN i.status = 'done' THEN 1 END) as done_orders
                FROM order_header i
                LEFT JOIN customers c ON i.customer_id = c.customer_id
                LEFT JOIN couriers co ON i.courier_id = co.courier_id
                WHERE 1 $roleCondition";
        
        if ($searchConditions) $summarySql .= " AND " . implode(' AND ', $searchConditions);
        
        $summaryResult = $conn->query($summarySql);
        $summaryData = $summaryResult->fetch_assoc();
        ?>

        <div class="table-wrapper">
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th>Total Amount</th>
                        <th>Subtotal</th>
                        <th>Delivery Fee</th>
                        <th>After Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sumTotalAmount = 0; 
                    $sumSubtotal = 0; 
                    $sumDelivery = 0; 
                    $sumAfterAmount = 0;

                    if ($result && $result->num_rows > 0):
                        while($row = $result->fetch_assoc()):
                            $sumTotalAmount += $row['total_amount'];
                            $sumSubtotal += $row['subtotal'];
                            $sumDelivery += $row['delivery_fee'];
                            $sumAfterAmount += $row['after_amount'];
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($row['order_id']) ?></td>
                            <td><?= htmlspecialchars($row['customer_name']) ?></td>
                            <td><?= ($row['status'] == 'done') ? 'Complete' : htmlspecialchars($row['status']) ?></td>
                            <td><?= number_format($row['total_amount'],2) ?></td>
                            <td><?= number_format($row['subtotal'],2) ?></td>
                            <td><?= number_format($row['delivery_fee'],2) ?></td>
                            <td>
                                <?php if($row['status'] == 'delivered' || $row['status'] == 'done'): ?>
                                    <span class="subtotal-mark"><?= number_format($row['subtotal'],2) ?></span>
                                <?php elseif($row['status'] == 'return_handover'): ?>
                                    <span class="no-return-mark">0.00</span>
                                <?php elseif($row['after_amount'] > 0): ?>
                                    <span class="return-fee-mark"><?= number_format($row['after_amount'],2) ?></span>
                                <?php else: ?>
                                    <span class="return-fee-mark">0.00</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                        <tr class="total-row">
                            <td colspan="3" style="text-align:right;">Totals</td>
                            <td><?= number_format($sumTotalAmount,2) ?></td>
                            <td><?= number_format($sumSubtotal,2) ?></td>
                            <td><?= number_format($sumDelivery,2) ?></td>
                            <td>
                                <span class="return-fee-mark"><?= number_format($sumAfterAmount,2) ?></span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="7" style="text-align:center;">No orders found</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="pagination">
            <?php for($i=1;$i<=$totalPages;$i++): ?>
                <button class="page-btn <?= ($i==$page)?'active':'' ?>" onclick="window.location.href='?page=<?= $i ?>&order_id_filter=<?= urlencode($order_id_filter) ?>&customer_name_filter=<?= urlencode($customer_name_filter) ?>&status_filter=<?= urlencode($status_filter) ?>&tracking_id=<?= urlencode($tracking_id) ?>&courier_id_filter=<?= urlencode($courier_id_filter) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>'">
                    <?= $i ?>
                </button>
            <?php endfor; ?>
        </div>

    </div>
</div>

<?php include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/include/scripts.php'); ?>
</body>
</html>