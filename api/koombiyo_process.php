<?php
// Start session FIRST before any output
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Disable error reporting for production
error_reporting(0);

// Clear any existing output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Function to log user actions
function logUserAction($conn, $user_id, $action_type, $inquiry_id, $details = null) {
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
    return $stmt->execute();
}

/**
 * Get user-friendly FDE API status message
 * Handles both New Parcel API and Existing Parcel API status codes
 */
function getFdeStatusMessage($status_code, $api_type = 'new') {
    if ($api_type === 'existing') {
        // FDE Existing Parcel API status messages
        $existing_status_messages = [
            200 => 'Successfully insert the parcel',
            201 => 'Incorrect waybill type. Only allow CRE or CCP',
            202 => 'The waybill is used',
            203 => 'The waybill is not yet assigned',
            204 => 'Inactive Client',
            205 => 'Invalid order id',
            206 => 'Invalid weight',
            207 => 'Empty or invalid parcel description',
            208 => 'Empty or invalid name',
            209 => 'Invalid contact number 1',
            210 => 'Invalid contact number 2',
            211 => 'Empty or invalid address',
            212 => 'Empty or invalid amount (If you have CRE numbers, you can ignore or set as a 0 value to this)',
            213 => 'Invalid city',
            214 => 'Parcel insert unsuccessfully',
            215 => 'Invalid or inactive client',
            216 => 'Invalid API key',
            217 => 'Invalid exchange value',
            218 => 'System maintain mode is activated'
        ];
        
        return isset($existing_status_messages[$status_code]) ? $existing_status_messages[$status_code] : 'Unknown error occurred';
    } else {
        // FDE New Parcel API status messages (default)
        $new_status_messages = [
            200 => 'Successful insert',
            201 => 'Inactive Client',
            202 => 'Invalid order id',
            203 => 'Invalid weight',
            204 => 'Empty or invalid parcel description',
            205 => 'Empty or invalid name',
            206 => 'Contact number 1 is not valid',
            207 => 'Contact number 2 is not valid',
            208 => 'Empty or invalid address',
            209 => 'Invalid City',
            210 => 'Unsuccessful insert, try again',
            211 => 'Invalid API key',
            212 => 'Invalid or inactive client',
            213 => 'Invalid exchange value',
            214 => 'System maintain mode is activated'
        ];
        
        return isset($new_status_messages[$status_code]) ? $new_status_messages[$status_code] : 'Unknown error occurred';
    }
}

// Function to set session message and redirect
function setMessageAndRedirect($type, $message, $redirect_url = null) {
    $_SESSION["order_{$type}"] = $message;
    
    // Default redirect to create order page
    if (!$redirect_url) {
        $redirect_url = "/order_management/dist/orders/create_order.php";
    }
    
    // Clear any output buffers before redirect
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header("Location: " . $redirect_url);
    exit();
}

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Validate required fields
        if (empty($_POST['customer_name'])) {
            throw new Exception("Customer name is required.");
        }
        
        // Get customer details early
        $customer_name = trim($_POST['customer_name']);
        $customer_email = trim($_POST['customer_email'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        
        // Additional customer validation (optional but recommended)
        if (!empty($customer_email) && !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }
        
        if (!empty($customer_phone) && !preg_match('/^[0-9+\-\s()]+$/', $customer_phone)) {
            throw new Exception("Invalid phone number format.");
        }

        // Check if products are added
        if (empty($_POST['order_product'])) {
            throw new Exception("At least one product must be added to the order.");
        }

        // Validate that at least one product is selected (not empty)
        $valid_products = array_filter($_POST['order_product'], function($product_id) {
            return !empty($product_id);
        });
        
        if (empty($valid_products)) {
            throw new Exception("Please select at least one valid product for the order.");
        }

        // Begin transaction
        $conn->begin_transaction();
        
        // Get current user ID from session (default to 1 if not set)
        $user_id = $_SESSION['user_id'] ?? 1;
        
        // Handle address fields according to actual database schema
        $address_line1 = trim($_POST['address_line1'] ?? '');
        $address_line2 = trim($_POST['address_line2'] ?? '');
        $city_id = !empty($_POST['city_id']) ? intval($_POST['city_id']) : null;
        
        // Debug log for city_id from POST
        error_log("DEBUG - POST city_id: " . ($_POST['city_id'] ?? 'NOT_SET'));
        error_log("DEBUG - Processed city_id: " . ($city_id ?? 'NULL'));
        
        // Find or create customer
        $customer_id = 0;
        $checkCustomerSql = "SELECT customer_id, city_id FROM customers WHERE name = ? AND email = ?";
        $stmt = $conn->prepare($checkCustomerSql);
        $stmt->bind_param("ss", $customer_name, $customer_email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $customer = $result->fetch_assoc();
            $customer_id = $customer['customer_id'];
            
            // If city_id is not provided in POST but exists in customer record, use existing
            if (empty($city_id) && !empty($customer['city_id'])) {
                $city_id = $customer['city_id'];
            }
            
            // Update existing customer information
            $updateCustomerSql = "UPDATE customers SET 
                                 phone = ?, 
                                 address_line1 = ?, 
                                 address_line2 = ?, 
                                 city_id = ?, 
                                 status = 'Active' 
                                 WHERE customer_id = ?";
            $stmt = $conn->prepare($updateCustomerSql);
            $stmt->bind_param("sssii", $customer_phone, $address_line1, $address_line2, $city_id, $customer_id);
            $stmt->execute();
        } else {
            // Insert new customer with correct column names
            $insertCustomerSql = "INSERT INTO customers (name, email, phone, address_line1, address_line2, city_id, status) 
                                 VALUES (?, ?, ?, ?, ?, ?, 'Active')";
            $stmt = $conn->prepare($insertCustomerSql);
            $stmt->bind_param("sssssi", $customer_name, $customer_email, $customer_phone, $address_line1, $address_line2, $city_id);
            $stmt->execute();
            $customer_id = $conn->insert_id;
        }

        // ADDITIONAL CHECK: If city_id is still null, try to get it from the customer record again
        if (empty($city_id) && !empty($customer_id)) {
            $getCustomerCitySql = "SELECT city_id FROM customers WHERE customer_id = ?";
            $customerCityStmt = $conn->prepare($getCustomerCitySql);
            $customerCityStmt->bind_param("i", $customer_id);
            $customerCityStmt->execute();
            $customerCityResult = $customerCityStmt->get_result();
            
            if ($customerCityResult && $customerCityResult->num_rows > 0) {
                $customerCityData = $customerCityResult->fetch_assoc();
                $city_id = $customerCityData['city_id'];
            }
        }
        
        // Debug log after all city_id processing
        error_log("DEBUG - Final city_id after customer processing: " . ($city_id ?? 'NULL'));
        error_log("DEBUG - Customer ID: " . $customer_id);
        
        // Prepare order details
        $order_date = $_POST['order_date'] ?? date('Y-m-d');
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
        
        // Get notes from form input
        $notes = $_POST['notes'] ?? "";
        
        // Get currency from form input
        $currency = isset($_POST['order_currency']) ? strtolower($_POST['order_currency']) : 'lkr';
        
        // Separate payment status from order status
        $order_status = $_POST['order_status'] ?? 'Unpaid';
        
        // Payment status logic: only affects pay_status, not order status
        $pay_status = $order_status === 'Paid' ? 'paid' : 'unpaid';
        $pay_date = $order_status === 'Paid' ? date('Y-m-d') : null;
        
        // Order status should always start as 'pending' regardless of payment status
        $status = 'pending';
        
        // Detailed calculation of totals
        $products = $_POST['order_product'];
        $product_prices = $_POST['order_product_price'];
        $discounts = $_POST['order_product_discount'] ?? [];
        $product_descriptions = $_POST['order_product_description'] ?? [];
        
        // Initialize subtotal to store the original price before discounts
        $subtotal_before_discounts = 0;
        $total_discount = 0;
        
        // Get delivery fee from form
        $delivery_fee = isset($_POST['delivery_fee']) ? floatval($_POST['delivery_fee']) : 0.00;
        
        // Prepare an array to store order items
        $order_items = [];
        foreach ($products as $key => $product_id) {
            // Skip empty product selections
            if (empty($product_id)) continue;
            
            $original_price = floatval($product_prices[$key] ?? 0);
            $discount = floatval($discounts[$key] ?? 0);
            $description = $product_descriptions[$key] ?? '';
            
            // Ensure discount doesn't exceed price
            $discount = min($discount, $original_price);
            
            // Calculate subtotal before discount
            $subtotal_before_discounts += $original_price;
            $total_discount += $discount;
            
            // Store item details for insertion
            $order_items[] = [
                'product_id' => $product_id,
                'original_price' => $original_price,
                'discount' => $discount,
                'description' => $description
            ];
        }
        
        // Final total calculation with delivery fee
        $total_amount = $subtotal_before_discounts - $total_discount + $delivery_fee;
        
        // Insert order_header
        $insertOrderSql = "INSERT INTO order_header (
            customer_id, user_id, issue_date, due_date, 
            subtotal, discount, total_amount, delivery_fee,
            notes, currency, status, pay_status, pay_date, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($insertOrderSql);
        $stmt->bind_param(
            "iissddddsssssi", 
            $customer_id, $user_id, $order_date, $due_date, 
            $subtotal_before_discounts, $total_discount, $total_amount, $delivery_fee,
            $notes, $currency, $status, $pay_status, $pay_date, $user_id
        );
        $stmt->execute();
        $order_id = $conn->insert_id;
        
        // Order items insertion
        $insertItemSql = "INSERT INTO order_items (
            order_id, product_id, unit_price, discount, 
            total_amount, pay_status, status, description
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertItemSql);

        foreach ($order_items as $item) {
            // Calculate the price after discount
            $item_price_after_discount = $item['original_price'] - $item['discount'];
            
            $stmt->bind_param(
                "iiddssss", 
                $order_id, 
                $item['product_id'], 
                $item['original_price'],      // unit_price (original price)
                $item['discount'], 
                $item_price_after_discount,   // total_amount (price after discount)
                $pay_status, 
                $status,     
                $item['description']
            );
            $stmt->execute();
        }

        // COURIER AND TRACKING ASSIGNMENT WITH ENHANCED API
        // Get default courier (can be is_default = 1, 2, or 3)
        $getDefaultCourierSql = "SELECT courier_id, courier_name, api_key, client_id, is_default FROM couriers WHERE is_default IN (1, 2, 3) AND status = 'active' ORDER BY is_default ASC LIMIT 1";
        $courierResult = $conn->query($getDefaultCourierSql);

        $tracking_assigned = false;
        $courier_warning = '';

        if ($courierResult && $courierResult->num_rows > 0) {
            $defaultCourier = $courierResult->fetch_assoc();
            $default_courier_id = $defaultCourier['courier_id'];
            $courier_type = $defaultCourier['is_default']; // 1 = internal tracking, 2 = FDE New API, 3 = FDE Existing API
            $api_key = $defaultCourier['api_key'];
            $client_id = $defaultCourier['client_id'];
            $courier_name = $defaultCourier['courier_name'];
            
            // Fixed: Use == for comparison, not assignment
            if ($default_courier_id == 11) {
                // Fardar Courier Processing
                if ($courier_type == 1) {
                    // INTERNAL TRACKING SYSTEM
                    // Get an unused tracking number for this courier
                    $getTrackingSql = "SELECT tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' LIMIT 1";
                    $trackingStmt = $conn->prepare($getTrackingSql);
                    $trackingStmt->bind_param("i", $default_courier_id);
                    $trackingStmt->execute();
                    $trackingResult = $trackingStmt->get_result();
                    
                    if ($trackingResult && $trackingResult->num_rows > 0) {
                        $trackingData = $trackingResult->fetch_assoc();
                        $tracking_number = $trackingData['tracking_id'];
                        
                        // Update the tracking record to 'used'
                        $updateTrackingSql = "UPDATE tracking SET status = 'used' WHERE tracking_id = ? AND courier_id = ?";
                        $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                        $updateTrackingStmt->bind_param("si", $tracking_number, $default_courier_id);
                        $updateTrackingStmt->execute();
                        
                        // Update order_header with courier info and set status to 'dispatch'
                        $updateOrderHeaderSql = "UPDATE order_header SET 
                                                courier_id = ?, 
                                                tracking_number = ?, 
                                                status = 'dispatch' 
                                                WHERE order_id = ?";
                        $updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
                        $updateOrderStmt->bind_param("isi", $default_courier_id, $tracking_number, $order_id);
                        $updateOrderStmt->execute();
                        
                        // Update all order_items status to 'dispatch'
                        $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                        $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                        $updateItemsStmt->bind_param("i", $order_id);
                        $updateItemsStmt->execute();
                        
                        // Update the main status variable for later use
                        $status = 'dispatch';
                        $tracking_assigned = true;
                        
                    } else {
                        $courier_warning = "No unused tracking numbers available for {$courier_name}";
                    }
                    
                } else if ($courier_type == 2) {
                    // FDE NEW PARCEL API INTEGRATION
                    
                    // Include the FDE API function
                    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/api/fde_new_parcel_api.php');
                    
                    // CITY HANDLING
                    $city_name = '';
                    $proceed_with_api = false;
                    
                    // Debug log before city processing
                    error_log("DEBUG - About to process city for FDE New API - city_id: " . ($city_id ?? 'NULL'));
                    
                    if (!empty($city_id)) {
                        $getCityNameSql = "SELECT city_name FROM city_table WHERE city_id = ? AND is_active = 1";
                        $cityStmt = $conn->prepare($getCityNameSql);
                        $cityStmt->bind_param("i", $city_id);
                        $cityStmt->execute();
                        $cityResult = $cityStmt->get_result();
                        
                        if ($cityResult && $cityResult->num_rows > 0) {
                            $cityData = $cityResult->fetch_assoc();
                            $city_name = $cityData['city_name'];
                            $proceed_with_api = true; // Valid city found, proceed with API
                            
                            error_log("DEBUG - City found: " . $city_name);
                        } else {
                            $city_name = 'Unknown City'; // Fallback if city not found
                            $proceed_with_api = false; // Don't proceed with API
                            
                            error_log("DEBUG - City ID exists but city not found in database");
                        }
                    } else {
                        $city_name = 'City Not Specified'; // Fallback if city_id is empty
                        $proceed_with_api = false; // Don't proceed with API
                        
                        error_log("DEBUG - No city_id provided");
                    }
                    
                    // Only proceed with API call if we have a valid city
                    if ($proceed_with_api) {
                        // Prepare data for FDE New Parcel API
                        $parcel_weight = '1'; // Default weight
                        $parcel_description = 'Order #' . $order_id . ' - ' . count($order_items) . ' items';
                        
                        // Calculate API amount based on payment status
                        // If order is marked as 'Paid', send 0 to API, otherwise send total_amount
                        $api_amount = ($order_status === 'Paid') ? 0 : $total_amount;
                        
                        // Use customer data for API call
                        $fde_api_data = array(
                            'api_key' => $api_key,
                            'client_id' => $client_id,
                            'order_id' => $order_id,
                            'parcel_weight' => $parcel_weight,
                            'parcel_description' => $parcel_description,
                            'recipient_name' => $customer_name,
                            'recipient_contact_1' => $customer_phone,
                            'recipient_contact_2' => '',
                            'recipient_address' => trim($address_line1 . ' ' . $address_line2),
                            'recipient_city' => $city_name,
                            'amount' => $api_amount,
                            'exchange' => '0'
                        );
                        
                        // Call FDE New Parcel API
                        $fde_response = callFdeApi($fde_api_data);
                        
                        // Parse FDE response - handle both JSON and error responses
                        $fde_result = null;
                        
                        // Check if response starts with "Curl error:"
                        if (strpos($fde_response, 'Curl error:') === 0) {
                            // cURL error occurred
                            $fde_result = [
                                'success' => false,
                                'error' => $fde_response
                            ];
                        } else {
                            // Try to decode JSON response
                            $fde_result = json_decode($fde_response, true);
                            
                            // If JSON decode failed, treat as error
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                $fde_result = [
                                    'success' => false,
                                    'error' => 'Invalid JSON response',
                                    'raw_response' => $fde_response
                                ];
                            }
                        }
                        
                        // Check for successful API response
                        if ($fde_result && (
                            (isset($fde_result['status']) && $fde_result['status'] == 200) ||
                            (isset($fde_result['success']) && $fde_result['success'] == true) ||
                            (isset($fde_result['waybill_no']) && !empty($fde_result['waybill_no']))
                        )) {
                            // API call successful
                            $tracking_number = $fde_result['waybill_no'] ?? ($fde_result['tracking_number'] ?? 'FDE' . $order_id);
                            
                            // Update order_header with courier info and set status to 'dispatch'
                            $updateOrderHeaderSql = "UPDATE order_header SET 
                                                    courier_id = ?, 
                                                    tracking_number = ?, 
                                                    status = 'dispatch'
                                                    WHERE order_id = ?";
                            $updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
                            $updateOrderStmt->bind_param("isi", $default_courier_id, $tracking_number, $order_id);
                            $updateOrderStmt->execute();
                            
                            // Update all order_items status to 'dispatch'
                            $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                            $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                            $updateItemsStmt->bind_param("i", $order_id);
                            $updateItemsStmt->execute();
                            
                            // Update the main status variable for later use
                            $status = 'dispatch';
                            $tracking_assigned = true;
                            
                        } else {
                            // FDE API call failed - Enhanced error handling
                            $error_status_code = null;
                            $error_message = 'Unknown error occurred';
                            
                            // Extract status code and get user-friendly message
                            if (isset($fde_result['status'])) {
                                $error_status_code = $fde_result['status'];
                                $error_message = getFdeStatusMessage($error_status_code, 'new');
                            } elseif (isset($fde_result['error'])) {
                                $error_message = $fde_result['error'];
                            } elseif (strpos($fde_response, 'Curl error:') === 0) {
                                $error_message = 'Network connection error - Please check internet connectivity';
                            }
                            
                            // Set user-friendly error message for session
                            $courier_warning = $error_message;
                        }
                        
                    } else {
                        // City not found or not specified - don't call API
                        $city_error_reason = empty($city_id) ? 'City not specified in delivery address' : 'Invalid city selected';
                        $courier_warning = $city_error_reason;
                    }
                    
                } else if ($courier_type == 3) {
                    // FDE EXISTING PARCEL API INTEGRATION
                    
                    // Include the FDE Existing Parcel API function
                    include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/api/fde_existing_parcel_api.php');
                    
                    // CITY HANDLING
                    $city_name = '';
                    $proceed_with_api = false;
                    
                    // Debug log before city processing
                    error_log("DEBUG - About to process city for FDE Existing API - city_id: " . ($city_id ?? 'NULL'));
                    
                    if (!empty($city_id)) {
                        $getCityNameSql = "SELECT city_name FROM city_table WHERE city_id = ? AND is_active = 1";
                        $cityStmt = $conn->prepare($getCityNameSql);
                        $cityStmt->bind_param("i", $city_id);
                        $cityStmt->execute();
                        $cityResult = $cityStmt->get_result();
                        
                        if ($cityResult && $cityResult->num_rows > 0) {
                            $cityData = $cityResult->fetch_assoc();
                            $city_name = $cityData['city_name'];
                            $proceed_with_api = true; // Valid city found, proceed with API
                            
                            error_log("DEBUG - City found: " . $city_name);
                        } else {
                            $city_name = 'Unknown City'; // Fallback if city not found
                            $proceed_with_api = false; // Don't proceed with API
                            
                            error_log("DEBUG - City ID exists but city not found in database");
                        }
                    } else {
                        $city_name = 'City Not Specified'; // Fallback if city_id is empty
                        $proceed_with_api = false; // Don't proceed with API
                        
                        error_log("DEBUG - No city_id provided");
                    }
                    
                    // Only proceed with API call if we have a valid city
                    if ($proceed_with_api) {
                        // Get unused tracking ID for existing parcel API
                        $getTrackingSql = "SELECT tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' LIMIT 1";
                        $trackingStmt = $conn->prepare($getTrackingSql);
                        $trackingStmt->bind_param("i", $default_courier_id);
                        $trackingStmt->execute();
                        $trackingResult = $trackingStmt->get_result();
                        
                        if ($trackingResult && $trackingResult->num_rows > 0) {
                            $trackingData = $trackingResult->fetch_assoc();
                            $tracking_id = $trackingData['tracking_id'];
                            
                            // Prepare data for FDE Existing Parcel API
                            $parcel_weight = '1'; // Default weight
                            $parcel_description = 'Order #' . $order_id . ' - ' . count($order_items) . ' items';
                            
                            // Calculate API amount based on payment status
                            // If order is marked as 'Paid', send 0 to API, otherwise send total_amount
                            $api_amount = ($order_status === 'Paid') ? 0 : $total_amount;
                            
                            // Use customer data for API call
                            $fde_existing_api_data = array(
                                'api_key' => $api_key,
                                'client_id' => $client_id,
                                'waybill_id' => $tracking_id, // Using tracking_id as waybill_id for the API
                                'order_id' => $order_id,
                                'parcel_weight' => $parcel_weight,
                                'parcel_description' => $parcel_description,
                                'recipient_name' => $customer_name,
                                'recipient_contact_1' => $customer_phone,
                                'recipient_contact_2' => '',
                                'recipient_address' => trim($address_line1 . ' ' . $address_line2),
                                'recipient_city' => $city_name,
                                'amount' => $api_amount,
                                'exchange' => '0'
                            );
                            
                            // Call FDE Existing Parcel API
                            $fde_existing_response = callFdeExistingParcelApi($fde_existing_api_data);
                            
                            // Parse FDE response - handle both JSON and error responses
                            $fde_existing_result = null;
                            
                            // Check if response starts with "Curl error:"
                            if (strpos($fde_existing_response, 'Curl error:') === 0) {
                                // cURL error occurred
                                $fde_existing_result = [
                                    'success' => false,
                                    'error' => $fde_existing_response
                                ];
                            } else {
                                // Try to decode JSON response
                                $fde_existing_result = json_decode($fde_existing_response, true);
                                
                                // If JSON decode failed, treat as error
                                if (json_last_error() !== JSON_ERROR_NONE) {
                                    $fde_existing_result = [
                                        'success' => false,
                                        'error' => 'Invalid JSON response',
                                        'raw_response' => $fde_existing_response
                                    ];
                                }
                            }
                            
                            // Check for successful API response
                            if ($fde_existing_result && (
                                (isset($fde_existing_result['status']) && $fde_existing_result['status'] == 200) ||
                                (isset($fde_existing_result['success']) && $fde_existing_result['success'] == true) ||
                                (isset($fde_existing_result['waybill_no']) && !empty($fde_existing_result['waybill_no']))
                            )) {
                                // API call successful
                                $tracking_number = $fde_existing_result['waybill_no'] ?? ($fde_existing_result['tracking_number'] ?? $tracking_id);
                                
                                // Update the tracking record to 'used'
                                $updateTrackingSql = "UPDATE tracking SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE tracking_id = ? AND courier_id = ?";
                                $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                                $updateTrackingStmt->bind_param("si", $tracking_id, $default_courier_id);
                                $updateTrackingStmt->execute();
                                
                                // Update order_header with courier info and set status to 'dispatch'
                                $updateOrderHeaderSql = "UPDATE order_header SET 
                                                        courier_id = ?, 
                                                        tracking_number = ?, 
                                                        status = 'dispatch'
                                                        WHERE order_id = ?";
                                $updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
                                $updateOrderStmt->bind_param("isi", $default_courier_id, $tracking_number, $order_id);
                                $updateOrderStmt->execute();
                                
                                // Update all order_items status to 'dispatch'
                                $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                                $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                                $updateItemsStmt->bind_param("i", $order_id);
                                $updateItemsStmt->execute();
                                
                                // Update the main status variable for later use
                                $status = 'dispatch';
                                $tracking_assigned = true;
                                
                            } else {
                                // FDE Existing API call failed - Enhanced error handling
                                $error_status_code = null;
                                $error_message = 'Invalid API key';
                                
                                // Extract status code and get user-friendly message
                                if (isset($fde_existing_result['status'])) {
                                    $error_status_code = $fde_existing_result['status'];
                                    $error_message = getFdeStatusMessage($error_status_code, 'existing');
                                } elseif (isset($fde_existing_result['error'])) {
                                    $error_message = $fde_existing_result['error'];
                                } elseif (strpos($fde_existing_response, 'Curl error:') === 0) {
                                    $error_message = 'Network connection error - Please check internet connectivity';
                                }
                                
                                // Set user-friendly error message for session
                                $courier_warning = $error_message;
                            }
                            
                        } else {
                            // No unused tracking IDs available
                            $courier_warning = "No unused tracking IDs available for {$courier_name}";
                        }
                        
                    } else {
                        // City not found or not specified - don't call API
                        $city_error_reason = empty($city_id) ? 'City not specified in delivery address' : 'Invalid city selected';
                        $courier_warning = $city_error_reason;
                    }
                }
                
            } else if ($default_courier_id == 12) {
            // Koombiyo Courier Processing Strat Here
              if ($courier_type == 1) {
                     // EMPTY BLOCK - This will cause issues
              } elseif ($courier_type == 2) {
                   // EMPTY BLOCK - This will cause issues
              } elseif ($courier_type == 3) {
                // KOOMBIYO EXISTING PARCEL API INTEGRATION
                
                // Include the Koombiyo API class
                include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/api/koombiyo_delivery_api.php');
                
                // DISTRICT AND CITY HANDLING (Koombiyo uses different system)
                $proceed_with_api = false;
                
                // Only proceed with API call if we have valid district and city mapping
                    if ($proceed_with_api) {
                        // Get unused tracking ID for Koombiyo existing parcel API
                        $getTrackingSql = "SELECT tracking_id FROM tracking WHERE courier_id = ? AND status = 'unused' LIMIT 1";
                        $trackingStmt = $conn->prepare($getTrackingSql);
                        $trackingStmt->bind_param("i", $default_courier_id);
                        $trackingStmt->execute();
                        $trackingResult = $trackingStmt->get_result();
                        
                        if ($trackingResult && $trackingResult->num_rows > 0) {
                            $trackingData = $trackingResult->fetch_assoc();
                            $tracking_id = $trackingData['tracking_id'];
                            
                            // Prepare data for Koombiyo API (using your actual API structure)
                            $order_description = 'Order #' . $order_id . ' - ' . count($order_items) . ' items';
                            
                            // Calculate COD amount - if order is marked as 'Paid', send 0, otherwise send total_amount
                            $cod_amount = ($order_status === 'Paid') ? 0 : $total_amount;
                            
                            // Use customer data for API call (adjusted to match your Koombiyo API)
                            $koombiyo_api_data = array(
                                'orderWaybillid' => $tracking_id, // Using tracking_id as waybill_id
                                'orderNo' => 'ORD' . $order_id, // Order reference number
                                'receiverName' => $customer_name,
                                'receiverStreet' => trim($address_line1 . ' ' . $address_line2),
                                'receiverDistrict' => $district_id, // Koombiyo district ID
                                'receiverCity' => $city_id_koombiyo, // Koombiyo city ID
                                'receiverPhone' => $customer_phone,
                                'description' => substr($order_description, 0, 100), // Limit description length
                                'getCod' => $cod_amount > 0 ? '1' : '0', // 1 if COD, 0 if prepaid
                                'spclNote' => $notes ? substr($notes, 0, 100) : '' // Special notes if any
                            );
                            
                            // Call Koombiyo API using your function
                            $koombiyo_response = addKoombiyoOrder($koombiyo_api_data, $api_key);
                            
                            // Check for successful API response
                            if ($koombiyo_response['success'] === true) {
                                // API call successful
                                $tracking_number = $tracking_id; // Use the same tracking ID
                                
                                // Update the tracking record to 'used'
                                $updateTrackingSql = "UPDATE tracking SET status = 'used', updated_at = CURRENT_TIMESTAMP WHERE tracking_id = ? AND courier_id = ?";
                                $updateTrackingStmt = $conn->prepare($updateTrackingSql);
                                $updateTrackingStmt->bind_param("si", $tracking_id, $default_courier_id);
                                $updateTrackingStmt->execute();
                                
                                // Update order_header with courier info and set status to 'dispatch'
                                $updateOrderHeaderSql = "UPDATE order_header SET 
                                                        courier_id = ?, 
                                                        tracking_number = ?, 
                                                        status = 'dispatch'
                                                        WHERE order_id = ?";
                                $updateOrderStmt = $conn->prepare($updateOrderHeaderSql);
                                $updateOrderStmt->bind_param("isi", $default_courier_id, $tracking_number, $order_id);
                                $updateOrderStmt->execute();
                                
                                // Update all order_items status to 'dispatch'
                                $updateOrderItemsSql = "UPDATE order_items SET status = 'dispatch' WHERE order_id = ?";
                                $updateItemsStmt = $conn->prepare($updateOrderItemsSql);
                                $updateItemsStmt->bind_param("i", $order_id);
                                $updateItemsStmt->execute();
                                
                                // Update the main status variable for later use
                                $status = 'dispatch';
                                $tracking_assigned = true;
                                
                            } else {
                                // Koombiyo API call failed
                                $error_message = $koombiyo_response['error'] ?? 'Unknown API error';
                                
                                // Set user-friendly error message for session
                                $courier_warning = "Koombiyo API Error: " . $error_message;
                                
                                // Log detailed error for debugging
                                error_log("Koombiyo API Error: " . print_r($koombiyo_response, true));
                            }
                            
                        } else {
                            // No unused tracking IDs available
                            $courier_warning = "No unused tracking IDs available for {$courier_name}";
                        }
                    }
               }  
            }
                 
        } else {
            // No courier configured
            $courier_warning = "No courier configured";
        }
        
        // If order is marked as Paid, insert into payments table
        if ($order_status === 'Paid') {
            // Default payment method to 'Cash'
            $payment_method = 'Cash';
            
            // Insert payment record
            $insertPaymentSql = "INSERT INTO payments (
                order_id, 
                amount_paid, 
                payment_method, 
                payment_date, 
                pay_by
            ) VALUES (?, ?, ?, ?, ?)";

            $current_datetime = date('Y-m-d H:i:s');

            $stmt = $conn->prepare($insertPaymentSql);
            $stmt->bind_param(
                "idssi", 
                $order_id, 
                $total_amount, 
                $payment_method, 
                $current_datetime, 
                $user_id
            );
            $stmt->execute();
        }
        
        // SINGLE USER LOG - CREATE ONLY ONE LOG ENTRY FOR ORDER CREATION
        $log_details = "Add a " . ($tracking_assigned ? 'dispatch' : 'pending') . " " . ($order_status === 'Paid' ? 'paid' : 'unpaid') . " order($order_id)" . 
                       ($total_discount > 0 ? " with discount" : "") . 
                       ($tracking_assigned && isset($tracking_number) ? " with tracking($tracking_number)" : "");
        
        // Log the order creation action - SINGLE LOG ENTRY
        $log_success = logUserAction($conn, $user_id, 'CREATE_ORDER', $order_id, $log_details);
        
        // Optional: Log any errors (but don't stop the process)
        if (!$log_success) {
            error_log("Failed to log user action for order creation: Order ID $order_id, User ID $user_id");
        }
        
        // Commit transaction
        $conn->commit();
        
        // UPDATED: Determine success message and redirect logic
        if ($tracking_assigned) {
            // Order created successfully with tracking
            $success_message = "Order #" . $order_id . " created successfully with tracking number assigned!";
            setMessageAndRedirect('success', $success_message, "download_order.php?id=" . $order_id);
        } else {
            // Order created but tracking assignment failed or skipped
            $success_message = "Order #" . $order_id . " created successfully!";
            
            if (!empty($courier_warning)) {
                // Set both success and warning messages
                $_SESSION['order_success'] = $success_message;
                $_SESSION['order_warning'] = $courier_warning;
                
                // Additional context for specific FDE errors
                if (strpos($courier_warning, 'Invalid City') !== false || strpos($courier_warning, 'Invalid city') !== false) {
                    $_SESSION['order_info'] = "Please ensure a valid delivery city is selected for automatic tracking assignment.";
                } elseif (strpos($courier_warning, 'Contact number') !== false || strpos($courier_warning, 'Invalid contact number') !== false) {
                    $_SESSION['order_info'] = "Please verify the customer's phone number format for FDE courier integration.";
                } elseif (strpos($courier_warning, 'Invalid or inactive client') !== false || strpos($courier_warning, 'Inactive Client') !== false) {
                    $_SESSION['order_info'] = "Courier API client configuration needs to be updated. Contact system administrator.";
                }
                
                // Clear any output buffers before redirect
                while (ob_get_level()) {
                    ob_end_clean();
                }
                
                header("Location: download_order.php?id=" . $order_id);
                exit();
            } else {
                setMessageAndRedirect('success', $success_message, "download_order.php?id=" . $order_id);
            }
        }
    
    } catch (Exception $e) {
        // Rollback transaction
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        
        // Log the error for debugging
        error_log("Order creation error: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        
        // Set error message and redirect
        setMessageAndRedirect('error', $e->getMessage());
    }
} else {
    // Not a POST request - redirect with info message
    setMessageAndRedirect('info', 'Invalid request method. Please use the order form to create orders.');
}
?>