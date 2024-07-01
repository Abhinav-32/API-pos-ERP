<?php
// File: api_handler.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Function to handle invoice insertion
function insert_invoice() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $requiredFields = [
        'transactionSiteCode', 'orderType', 'intgInvoiceId', 'omsInvoiceNo',
        'omsInvoiceDate', 'tradeGroup', 'valueDetails', 'deliveryDetails', 'referenceNo', 'eInvoiceAppl'
    ];

    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "$field is required"]);
            exit;
        }
    }

    $validOrderTypes = ['NEW', 'RETURN', 'EXCHANGE'];
    if (!in_array($data['orderType'], $validOrderTypes)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid orderType']);
        exit;
    }

    if (in_array($data['orderType'], ['RETURN', 'EXCHANGE'])) {
        if (empty($data['parentErpOrderId']) && empty($data['parentIntgRefOrderId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'parentErpOrderId or parentIntgRefOrderId is required for RETURN or EXCHANGE orders']);
            exit;
        }
    }

    if (!empty($data['erpOrderId']) && !empty($data['intgRefOrderId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Both erpOrderId and intgRefOrderId cannot be present together']);
        exit;
    }

    if (!is_string($data['intgInvoiceId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'intgInvoiceId must be a string']);
        exit;
    }

    if ($data['orderType'] === 'RETURN' && !is_string($data['parentIntgInvoiceId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'parentIntgInvoiceId must be a string for RETURN order type']);
        exit;
    }

    $orderDate = strtotime($data['orderDate'] ?? null);
    $omsInvoiceDate = strtotime($data['omsInvoiceDate']);
    $channelInvoiceDate = !empty($data['channelInvoiceDate']) ? strtotime($data['channelInvoiceDate']) : null;

    if ($omsInvoiceDate < $orderDate) {
        http_response_code(400);
        echo json_encode(['error' => 'omsInvoiceDate cannot be earlier than orderDate']);
        exit;
    }

    if ($channelInvoiceDate !== null && $channelInvoiceDate < $orderDate) {
        http_response_code(400);
        echo json_encode(['error' => 'channelInvoiceDate cannot be earlier than orderDate']);
        exit;
    }

    $validTradeGroups = ['LOCAL', 'INTER STATE', 'EXPORT/IMPORT'];
    if (!in_array($data['tradeGroup'], $validTradeGroups)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid tradeGroup']);
        exit;
    }

    $deliveryDetails = $data['deliveryDetails'];
    if (!isset($deliveryDetails['billToShipToSame']) || !in_array($deliveryDetails['billToShipToSame'], [0, 1])) {
        http_response_code(400);
        echo json_encode(['error' => 'Value for billToShipToSame is not from the accepted list [0, 1]']);
        exit;
    }

    if ($deliveryDetails['billToShipToSame'] == 0 && empty($deliveryDetails['shippingDetails']['addressDetails'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Shipping address is required']);
        exit;
    }

    if (!empty($deliveryDetails['billingDetails']) && empty($deliveryDetails['billingDetails']['addressDetails'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Billing address is required']);
        exit;
    }

    if (!validateTransporterId($deliveryDetails['transporterId'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Value does not exist in the integration master mapping or is not valid']);
        exit;
    }

    $valueDetails = $data['valueDetails'];
    if ($valueDetails['invoiceValue'] <= 0 || $valueDetails['invoiceValue'] > 999999.99) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid invoiceValue']);
        exit;
    }
    if (!is_numeric($valueDetails['invoiceRoundOff'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid invoiceRoundOff']);
        exit;
    }
    if ($valueDetails['invoicePayableAmount'] !== ($valueDetails['invoiceValue'] + $valueDetails['invoiceRoundOff'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Mismatch in invoicePayableAmount calculation']);
        exit;
    }
    if ($valueDetails['codAmount'] < 0 || $valueDetails['codAmount'] > $valueDetails['invoicePayableAmount']) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid codAmount']);
        exit;
    }

    if (empty($deliveryDetails['itemDetails']) || !is_array($deliveryDetails['itemDetails']) || count($deliveryDetails['itemDetails']) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'At least one item detail is required']);
        exit;
    }

    foreach ($deliveryDetails['itemDetails'] as $item) {
        if (empty($item['itemCode']) || !is_string($item['itemCode'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or missing itemCode']);
            exit;
        }
        if (!empty($item['erpOrderDetId']) && !empty($item['intgRefOrderDetId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Both erpOrderDetId and intgRefOrderDetId cannot be present together']);
            exit;
        }
        if (!empty($item['intgBatchId']) && empty($item['intgBatchDetId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'intgBatchDetId is required when intgBatchId is present']);
            exit;
        }
        if (!empty($item['intgBatchDetId']) && empty($item['intgBatchId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'intgBatchId is required when intgBatchDetId is present']);
            exit;
        }
        if (empty($item['hsnsacCode']) || !is_numeric($item['hsnsacCode'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or missing hsnsacCode']);
            exit;
        }
        if (empty($item['intgInvoiceDetId']) || !is_string($item['intgInvoiceDetId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or missing intgInvoiceDetId']);
            exit;
        }
        if (empty($item['batchSerialNo']) || !is_string($item['batchSerialNo'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or missing batchSerialNo']);
            exit;
        }
        if (empty($item['invoiceQuantity']) || !is_numeric($item['invoiceQuantity']) || $item['invoiceQuantity'] <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or missing invoiceQuantity']);
            exit;
        }
        if (empty($item['itemRate']) || !is_numeric($item['itemRate']) || $item['itemRate'] <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or missing itemRate']);
            exit;
        }
        $calculatedGrossAmount = round($item['invoiceQuantity'] * $item['itemRate'], 2);
        if (empty($item['grossAmount']) || !is_numeric($item['grossAmount']) || $item['grossAmount'] != $calculatedGrossAmount) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or incorrect grossAmount']);
            exit;
        }
        $calculatedNetAmount = round(
            $item['grossAmount'] - ($item['applicableCharges']['itemDiscount'] ?? 0) + ($item['applicableCharges']['codCharge'] ?? 0) +
            ($item['applicableCharges']['giftWrapCharge'] ?? 0) + ($item['applicableCharges']['shippingCharge'] ?? 0) +
            ($item['applicableCharges']['otherCharges'] ?? 0),
            2
        );
        if (empty($item['netAmount']) || !is_numeric($item['netAmount']) || $item['netAmount'] != $calculatedNetAmount) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or incorrect netAmount']);
            exit;
        }

        $validTaxRegimes = ['G', 'V'];
        if (!in_array($item['itemTaxDetails']['taxRegime'], $validTaxRegimes)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid taxRegime']);
            exit;
        }

        $calculatedTaxableAmount = round($item['netAmount'] - $item['itemTaxDetails']['taxAmount'], 2);
        if ($item['itemTaxDetails']['taxableAmount'] != $calculatedTaxableAmount) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid taxableAmount']);
            exit;
        }

        if ($item['itemTaxDetails']['taxRegime'] === 'G' && $data['tradeGroup'] === 'LOCAL') {
            $expectedTaxRate = $item['itemTaxDetails']['cgstRate'] + $item['itemTaxDetails']['sgstRate'] + $item['itemTaxDetails']['cessRate'];
        } elseif ($data['tradeGroup'] === 'INTER STATE' || $data['tradeGroup'] === 'EXPORT/IMPORT') {
            $expectedTaxRate = $item['itemTaxDetails']['igstRate'] + $item['itemTaxDetails']['cessRate'];
        } else {
            $expectedTaxRate = $item['itemTaxDetails']['taxRate'];
        }
        if ($item['itemTaxDetails']['taxRate'] != $expectedTaxRate) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid taxRate']);
            exit;
        }

        if ($item['itemTaxDetails']['taxRegime'] === 'G' && $data['tradeGroup'] === 'LOCAL') {
            $expectedTaxAmount = $item['itemTaxDetails']['cgstAmount'] + $item['itemTaxDetails']['sgstAmount'] + $item['itemTaxDetails']['cessAmount'];
        } elseif ($data['tradeGroup'] === 'INTER STATE' || $data['tradeGroup'] === 'EXPORT/IMPORT') {
            $expectedTaxAmount = $item['itemTaxDetails']['igstAmount'] + $item['itemTaxDetails']['cessAmount'];
        } else {
            $expectedTaxAmount = round($item['itemTaxDetails']['taxableAmount'] * $item['itemTaxDetails']['taxRate'] / 100, 2);
        }
        if ($item['itemTaxDetails']['taxAmount'] != $expectedTaxAmount) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid taxAmount']);
            exit;
        }

        if ($item['itemTaxDetails']['taxRegime'] === 'V') {
            if ($item['itemTaxDetails']['cgstRate'] != 0 || $item['itemTaxDetails']['sgstRate'] != 0 || $item['itemTaxDetails']['cessRate'] != 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Applicable GST & CESS rates should be 0 when taxRegime = V']);
                exit;
            }
        }
        if ($data['tradeGroup'] !== 'LOCAL') {
            if ($item['itemTaxDetails']['cgstRate'] != 0 || $item['itemTaxDetails']['sgstRate'] != 0) {
                http_response_code(400);
                echo json_encode(['error' => 'cgstRate and sgstRate should be 0 for interstate invoices']);
                exit;
            }
        }
        if ($data['tradeGroup'] === 'LOCAL') {
            if ($item['itemTaxDetails']['igstRate'] != 0) {
                http_response_code(400);
                echo json_encode(['error' => 'igstRate should be 0 for local invoices']);
                exit;
            }
        }
        if ($item['itemTaxDetails']['cgstAmount'] != round($item['itemTaxDetails']['taxableAmount'] * $item['itemTaxDetails']['cgstRate'] / 100, 2, PHP_ROUND_HALF_DOWN)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid CGST Amount']);
            exit;
        }
        if ($item['itemTaxDetails']['sgstAmount'] != round($item['itemTaxDetails']['taxableAmount'] * $item['itemTaxDetails']['sgstRate'] / 100, 2, PHP_ROUND_HALF_DOWN)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid SGST Amount']);
            exit;
        }
        if ($item['itemTaxDetails']['igstAmount'] != round($item['itemTaxDetails']['taxableAmount'] * $item['itemTaxDetails']['igstRate'] / 100, 2, PHP_ROUND_HALF_DOWN)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid IGST Amount']);
            exit;
        }
        if ($item['itemTaxDetails']['cessAmount'] != round($item['itemTaxDetails']['taxableAmount'] * $item['itemTaxDetails']['cessRate'] / 100, 2, PHP_ROUND_HALF_DOWN)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid CESS Amount']);
            exit;
        }
    }

    if (!in_array($data['eInvoiceAppl'], [0, 1])) {
        http_response_code(400);
        echo json_encode(['error' => 'Value for eInvoiceAppl is not from the accepted list [0, 1]']);
        exit;
    }

    if ($data['eInvoiceAppl'] == 1) {
        $eInvoiceDetails = $data['eInvoiceDetails'];
        if (empty($eInvoiceDetails['irnNumber']) || !preg_match('/^[a-zA-Z0-9]{64}$/', $eInvoiceDetails['irnNumber'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid irnNumber']);
            exit;
        }
        if (empty($eInvoiceDetails['ackNumber']) || !is_numeric($eInvoiceDetails['ackNumber']) || strlen($eInvoiceDetails['ackNumber']) > 25) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid ackNumber']);
            exit;
        }
        if (strlen($eInvoiceDetails['qrCodeData']) > 2000) {
            http_response_code(400);
            echo json_encode(['error' => 'qrCodeData exceeds maximum length of 2000 characters']);
            exit;
        }
    }

    $erpResponse = insertIntoERP($data);

    if ($erpResponse['success']) {
        echo json_encode([
            'success' => true,
            'ginesysInvoiceNumber' => $erpResponse['ginesysInvoiceNumber']
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to insert into ERP']);
    }
}

// Function to send order data to Ginesys
function send_order_to_ginesys($order_id) {
    $order = wc_get_order($order_id); // Fetch order details from WooCommerce
    // Prepare order data
    $order_data = [
        'order_id' => $order->get_id(),
        'total' => $order->get_total(),
        'items' => [],
    ];
    // Loop through each item in the order
    foreach ($order->get_items() as $item) {
        $order_data['items'][] = [
            'product_id' => $item->get_product_id(),
            'quantity' => $item->get_quantity(),
        ];
    }
    $url = 'https://api.ginesys.com/order'; // Ginesys API endpoint for order data
    $response = wp_remote_post($url, [
        'method' => 'POST', // HTTP method for creating resource
        'body' => json_encode($order_data), // Convert order data array to JSON format
        'headers' => [
            'Authorization' => 'Bearer ' . get_ginesys_api_token(), // Bearer Token for Ginesys API
            'Content-Type' => 'application/json', // Content type header
        ],
    ]);
    if (is_wp_error($response)) {
        // Handle error
        error_log('Failed to send order: ' . $response->get_error_message());
    } else {
        // Handle success
        error_log('Order sent successfully.');
    }
    return $response;
}

// Hook to send order data to Ginesys when order is created
add_action('woocommerce_thankyou', 'send_order_to_ginesys');

// Function to update WooCommerce product inventory
function update_woocommerce_inventory($product_id, $stock_quantity) {
    $url = "https://yourwoocommerce.com/wp-json/wc/v3/products/{$product_id}"; // WooCommerce API URL for updating product
    $data = [
        'stock_quantity' => $stock_quantity, // Data to update the stock quantity
    ];
    $response = wp_remote_post($url, [
        'method' => 'PUT', // HTTP method for updating resource
        'body' => json_encode($data), // Convert data array to JSON format
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('consumer_key:consumer_secret'), // Basic Auth for WooCommerce API
            'Content-Type' => 'application/json', // Content type header
        ],
    ]);
    if (is_wp_error($response)) {
        error_log('Failed to update inventory: ' . $response->get_error_message());
        return false;
    } else {
        error_log('Inventory updated successfully for product ID: ' . $product_id);
        return true;
    }
}

// Function to fetch inventory data from Ginesys
function fetch_inventory_from_ginesys() {
    $url = 'https://api.ginesys.com/inventory'; // Ginesys API endpoint for inventory data
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . get_ginesys_api_token(), // Bearer Token for Ginesys API
            'Content-Type' => 'application/json', // Content type header
        ],
    ]);
    if (is_wp_error($response)) {
        error_log('Failed to fetch inventory: ' . $response->get_error_message());
        return [];
    } else {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Failed to parse Ginesys inventory data: ' . json_last_error_msg());
            return [];
        }
        return $data;
    }
}

// Function to get Ginesys API token
function get_ginesys_api_token() {
    return 'your_ginesys_api_token';
}

// Example usage
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_GET['action'] === 'fetch_inventory') {
    $inventory_data = fetch_inventory_from_ginesys();
    if (!empty($inventory_data)) {
        foreach ($inventory_data as $item) {
            update_woocommerce_inventory($item['product_id'], $item['stock_quantity']);
        }
    } else {
        error_log('No inventory data fetched from Ginesys.');
    }
}

// Schedule event to fetch inventory data
if (!wp_next_scheduled('fetch_inventory_data_event')) {
    wp_schedule_event(time(), 'hourly', 'fetch_inventory_data_event');
}

// Hook the function to the event
add_action('fetch_inventory_data_event', 'fetch_inventory_from_ginesys');

// Function to unschedule the event (optional)
function remove_fetch_inventory_data_event() {
    wp_clear_scheduled_hook('fetch_inventory_data_event');
}

register_deactivation_hook(__FILE__, 'remove_fetch_inventory_data_event');

// Mock ERP Integration: Replace with actual ERP integration logic
function insertIntoERP($invoiceData) {
    // This is a mock function. Replace with actual ERP integration logic.
    // Simulate success response from ERP
    return [
        'success' => true,
        'ginesysInvoiceNumber' => 'GIN-INV-' . rand(1000, 9999)
    ];
}
?>
Points Covered:
1.Invoice Insertion: The script validates and inserts invoices into the Ginesys system.

2.Order Data Transmission: It captures order data from WooCommerce and sends it to Ginesys.

3.Inventory Synchronization: The script fetches inventory data from Ginesys and updates WooCommerce inventory.

4.Automation and Scheduling: The script uses WordPress cron jobs to automate the fetching of inventory data.


Setup Steps:
1.WooCommerce API Setup: Ensure WooCommerce REST API is enabled and use the Consumer Key and Consumer Secret for API access.

2.Ginesys API Access: Use the provided API keys or tokens for authentication.

3.Cron Jobs: Set up cron jobs for continuous data synchronization.

4.Error Handling and Logging: Implement error handling to manage API request failures and set up logging for debugging purposes.

Testing: Thoroughly test the integration in a staging environment before deploying to production.