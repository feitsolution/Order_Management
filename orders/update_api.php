<?php
// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/order_management/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get form data
    $courier_id = isset($_POST['courier_id']) ? intval($_POST['courier_id']) : 0;
    $client_id = isset($_POST['client_id']) ? trim($_POST['client_id']) : '';
    $api_key = isset($_POST['api_key']) ? trim($_POST['api_key']) : '';
    
    // Validate input
    if ($courier_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid courier ID']);
        exit();
    }

    
    if (empty($api_key)) {
        echo json_encode(['success' => false, 'message' => 'API Key is required']);
        exit();
    }
    
    // Check if courier exists and get current settings
    $checkSql = "SELECT courier_id, courier_name, client_id, api_key FROM couriers WHERE courier_id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("i", $courier_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Courier not found']);
        exit();
    }
    
    $courier = $checkResult->fetch_assoc();
    $old_client_id = $courier['client_id'];
    $old_api_key = $courier['api_key'];
    
    // Check if there are actual changes
    $changes = [];
    $log_changes = []; // For database logging (masked)
    
    if ($old_client_id !== $client_id) {
        $client_change = "Client ID changed from '" . ($old_client_id ?: 'empty') . "' to '" . $client_id . "'";
        $changes[] = $client_change;
        $log_changes[] = $client_change;
    }
    
    if ($old_api_key !== $api_key) {
        // Full API key for response display
        $full_change = "API Key changed from '" . ($old_api_key ?: 'empty') . "' to '" . $api_key . "'";
        $changes[] = $full_change;
        
       
    }
    
    if (empty($changes)) {
        echo json_encode([
            'success' => false, 
            'message' => 'No changes were made to the API settings'
        ]);
        exit();
    }
    
    // Update courier API settings
    $updateSql = "UPDATE couriers SET 
                    client_id = ?, 
                    api_key = ?, 
                    updated_at = CURRENT_TIMESTAMP 
                  WHERE courier_id = ?";
    
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param("ssi", $client_id, $api_key, $courier_id);
    
    if ($updateStmt->execute()) {
        if ($updateStmt->affected_rows > 0) {
            // Prepare log details
            $log_details = "API settings updated : " . $courier['courier_name'] . " (ID: " . $courier_id . "). Changes: " . implode('| ', $changes);
            
            // Insert into user_logs table
            $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)";
            $logStmt = $conn->prepare($logSql);
            
            // Get user ID from session (assuming it's stored there)
            $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
            $action_type = 'API_UPDATE';
            
            $logStmt->bind_param("isis", $user_id, $action_type, $courier_id, $log_details);
            
            if ($logStmt->execute()) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'API settings updated successfully for ' . $courier['courier_name'],
                    'changes' => $changes,
                    'details' => $log_details
                ]);
            } else {
                // Even if logging fails, the update was successful
                error_log('Failed to log API update: ' . $conn->error);
                echo json_encode([
                    'success' => true, 
                    'message' => 'API settings updated successfully for ' . $courier['courier_name'] . ' (logging failed)',
                    'changes' => $changes
                ]);
            }
            
            $logStmt->close();
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'No changes were made to the API settings'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to update API settings: ' . $conn->error
        ]);
    }
    
    $updateStmt->close();
    $checkStmt->close();
    
} catch (Exception $e) {
    error_log('API Update Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An unexpected error occurred while updating API settings'
    ]);
} finally {
    // Close database connection
    if (isset($conn)) {
        $conn->close();
    }
}
?>
