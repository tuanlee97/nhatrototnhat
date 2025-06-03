<?php
require_once __DIR__ . "/database.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/helpers.php";

function handleApiRequest($method, $uri)
{
    checkRateLimit(getClientIp());
    // Normalize URI: Remove '/api/' prefix and trailing slashes
    $endpoint = preg_replace("#^/api/#", "", $uri);
    $endpoint = rtrim($endpoint, "/");

    $routes = [
        // Auth
        "POST:login" => [
            "file" => "../api/auth.php",
            "handler" => "login",
            "middleware" => "non-auth",
        ],
        "POST:logout" => [
            "file" => "../api/auth.php",
            "handler" => "logout",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "POST:refresh" => [
            "file" => "../api/auth.php",
            "handler" => "refreshToken",
            "middleware" => "non-auth",
        ],
        // Users
        "GET:user" => [
            "file" => "../api/users.php",
            "handler" => "getCurrentUser",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "GET:users" => [
            "file" => "../api/users.php",
            "handler" => "getUsers",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "POST:users" => [
            "file" => "../api/users.php",
            "handler" => "createUser",
            "middleware" => "auth:admin,owner,employee",
        ],
        "PUT:users/([0-9]+)" => [
            "file" => "../api/users.php",
            "handler" => "updateUser",
            "middleware" => "auth:admin,owner,employee",
        ],
        "PATCH:users/([0-9]+)" => [
            "file" => "../api/users.php",
            "handler" => "patchUser",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "DELETE:users/([0-9]+)" => [
            "file" => "../api/users.php",
            "handler" => "deleteUser",
            "middleware" => "auth:admin,owner,employee",
        ],
        "POST:register" => [
            "file" => "../api/users.php",
            "handler" => "registerUser",
            "middleware" => "non-auth",
        ],
        "POST:register/google" => [
            "file" => "../api/users.php",
            "handler" => "registerGoogleUser",
            "middleware" => "non-auth",
        ],
        "POST:users/forgot-password" => [
            "file" => "../api/users.php",
            "handler" => "forgotPassword",
            "middleware" => "non-auth",
        ],
        "POST:users/reset-password" => [
            "file" => "../api/users.php",
            "handler" => "resetPassword",
            "middleware" => "non-auth",
        ],
        // QR code upload
        "POST:upload-qr" => [
            "file" => "../api/upload.php",
            "handler" => "uploadQrCode",
            "middleware" => "auth:admin,owner,employee",
        ],
        // Branches
        "GET:branches" => [
            "file" => "../api/branches.php",
            "handler" => "getBranches",
            "middleware" => null,
        ],
        "POST:branches" => [
            "file" => "../api/branches.php",
            "handler" => "createBranch",
            "middleware" => "auth:owner",
        ],
        "GET:branches/([0-9]+)" => [
            "file" => "../api/branches.php",
            "handler" => "getBranchById",
            "middleware" => "auth:admin,owner",
        ],
        "PUT:branches/([0-9]+)" => [
            "file" => "../api/branches.php",
            "handler" => "updateBranch",
            "middleware" => "auth:owner",
        ],
        "DELETE:branches/([0-9]+)" => [
            "file" => "../api/branches.php",
            "handler" => "deleteBranch",
            "middleware" => "auth:owner",
        ],
        "GET:branches/([0-9]+)/rooms_customers" => [
            "file" => "../api/branches.php",
            "handler" => "getRoomsAndCustomersByBranchId",
            "middleware" => "auth:admin,owner,employee",
        ],
        // Rooms
        "GET:rooms" => [
            "file" => "../api/rooms.php",
            "handler" => "getRooms",
            "middleware" => null,
        ],
        "POST:rooms" => [
            "file" => "../api/rooms.php",
            "handler" => "createRoom",
            "middleware" => "auth:owner",
        ],
        "GET:rooms/([0-9]+)" => [
            "file" => "../api/rooms.php",
            "handler" => "getRoomById",
            "middleware" => null,
        ],
        "PUT:rooms/([0-9]+)" => [
            "file" => "../api/rooms.php",
            "handler" => "updateRoom",
            "middleware" => "auth:owner",
        ],
        "PATCH:rooms/([0-9]+)" => [
            "file" => "../api/rooms.php",
            "handler" => "patchRoom",
            "middleware" => "auth:owner",
        ],
        "DELETE:rooms/([0-9]+)" => [
            "file" => "../api/rooms.php",
            "handler" => "deleteRoom",
            "middleware" => "auth:owner",
        ],
        "POST:rooms/change" => [
            "file" => "../api/rooms.php",
            "handler" => "changeRoom",
            "middleware" => "auth:admin,owner,employee",
        ],
        // Room Occupants
        "GET:room_occupants" => [
            "file" => "../api/room_occupants.php",
            "handler" => "getOccupantsByRoom",
            "middleware" => "auth:admin,owner,employee",
        ],
        "POST:room_occupants" => [
            "file" => "../api/room_occupants.php",
            "handler" => "createRoomOccupant",
            "middleware" => "auth:owner,employee",
        ],
        // 'GET:room_occupants/([0-9]+)' => ['file' => '../api/room_occupants.php', 'handler' => 'getRoomOccupantById', 'middleware' => 'auth:admin,owner,employee,customer'],
        "PUT:room_occupants" => [
            "file" => "../api/room_occupants.php",
            "handler" => "updateRoomOccupants",
            "middleware" => "auth:admin,owner,employee",
        ],
        // 'PATCH:room_occupants/([0-9]+)' => ['file' => '../api/room_occupants.php', 'handler' => 'patchRoomOccupant', 'middleware' => 'auth:owner,employee'],
        "DELETE:room_occupants/([0-9]+)" => [
            "file" => "../api/room_occupants.php",
            "handler" => "deleteRoomOccupant",
            "middleware" => "auth:admin,owner,employee",
        ],

        // Room Types
        "GET:room_types" => [
            "file" => "../api/room_types.php",
            "handler" => "getRoomTypes",
            "middleware" => null,
        ],
        "POST:room_types" => [
            "file" => "../api/room_types.php",
            "handler" => "createRoomType",
            "middleware" => "auth:owner",
        ],
        "GET:room_types/([0-9]+)" => [
            "file" => "../api/room_types.php",
            "handler" => "getRoomTypeById",
            "middleware" => null,
        ],
        "PUT:room_types/([0-9]+)" => [
            "file" => "../api/room_types.php",
            "handler" => "updateRoomType",
            "middleware" => "auth:owner",
        ],
        "PATCH:room_types/([0-9]+)" => [
            "file" => "../api/room_types.php",
            "handler" => "patchRoomType",
            "middleware" => "auth:owner",
        ],
        "DELETE:room_types/([0-9]+)" => [
            "file" => "../api/room_types.php",
            "handler" => "deleteRoomType",
            "middleware" => "auth:owner",
        ],
        // Services
        "GET:services" => [
            "file" => "../api/services.php",
            "handler" => "getServices",
            "middleware" => null,
        ],
        "POST:services" => [
            "file" => "../api/services.php",
            "handler" => "createService",
            "middleware" => "auth:owner",
        ],
        "GET:services/([0-9]+)" => [
            "file" => "../api/services.php",
            "handler" => "getServiceById",
            "middleware" => null,
        ],
        "PUT:services/([0-9]+)" => [
            "file" => "../api/services.php",
            "handler" => "updateService",
            "middleware" => "auth:owner",
        ],
        "DELETE:services/([0-9]+)" => [
            "file" => "../api/services.php",
            "handler" => "deleteService",
            "middleware" => "auth:owner",
        ],
        // Employees
        "POST:employees" => [
            "file" => "../api/employees.php",
            "handler" => "createEmployee",
            "middleware" => "auth:owner,employee",
        ],
        "PUT:employees/([0-9]+)" => [
            "file" => "../api/employees.php",
            "handler" => "updateEmployee",
            "middleware" => "auth:owner,employee",
        ],
        "PATCH:employees/([0-9]+)" => [
            "file" => "../api/employees.php",
            "handler" => "patchEmployee",
            "middleware" => "auth:owner,employee",
        ],
        "DELETE:employees/([0-9]+)" => [
            "file" => "../api/employees.php",
            "handler" => "deleteEmployee",
            "middleware" => "auth:owner,employee",
        ],
        // Utility Usage
        "GET:utility_usage" => [
            "file" => "../api/utility_usage.php",
            "handler" => "getUtilityUsage",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "POST:utility_usage" => [
            "file" => "../api/utility_usage.php",
            "handler" => "createUtilityUsage",
            "middleware" => "auth:owner,employee",
        ],
        "POST:utility_usage/bulk" => [
            "file" => "../api/utility_usage.php",
            "handler" => "createBulkUtilityUsage",
            "middleware" => "auth:owner,employee",
        ],
        // "GET:utility_usage/([0-9]+)" => [
        //     "file" => "../api/utility_usage.php",
        //     "handler" => "getUtilityUsageById",
        //     "middleware" => "auth:admin,owner,employee,customer",
        // ],
        "PUT:utility_usage/([0-9]+)" => [
            "file" => "../api/utility_usage.php",
            "handler" => "updateUtilityUsage",
            "middleware" => "auth:owner,employee",
        ],
        "DELETE:utility_usage/([0-9]+)" => [
            "file" => "../api/utility_usage.php",
            "handler" => "deleteUtilityUsage",
            "middleware" => "auth:owner",
        ],
        "GET:utility_usage/latest" => [
            "file" => "../api/utility_usage.php",
            "handler" => "getLatestUtilityReading",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "GET:utility_usage/summary" => [
            "file" => "../api/utility_usage.php",
            "handler" => "getUtilityUsageSummary",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
      
        // Maintenance Requests
        "POST:maintenance-requests" => [
            "file" => "../api/maintenance_requests.php",
            "handler" => "createMaintenanceRequest",
            "middleware" => "auth:customer",
        ],
        "GET:maintenance-requests/customer/([0-9]+)" => [
            "file" => "../api/maintenance_requests.php",
            "handler" => "getCustomerMaintenanceRequests",
            "middleware" => "auth:customer",
        ],
        "GET:maintenance-requests" => [
            "file" => "../api/maintenance_requests.php",
            "handler" => "getAllMaintenanceRequests",
            "middleware" => "auth:admin,owner,employee",
        ],
        "PUT:maintenance-requests/([0-9]+)" => [
            "file" => "../api/maintenance_requests.php",
            "handler" => "updateMaintenanceRequest",
            "middleware" => "auth:admin,owner,employee",
        ],
        "DELETE:maintenance-requests/([0-9]+)" => [
            "file" => "../api/maintenance_requests.php",
            "handler" => "deleteMaintenanceRequest",
            "middleware" => "auth:admin",
        ],
        // Notifications
        "GET:notifications" => [
            "file" => "../api/notifications.php",
            "handler" => "getNotifications",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "POST:notifications" => [
            "file" => "../api/notifications.php",
            "handler" => "createNotification",
            "middleware" => "auth:owner",
        ],
        "GET:notifications/([0-9]+)" => [
            "file" => "../api/notifications.php",
            "handler" => "getNotificationById",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "PATCH:notifications/([0-9]+)" => [
            "file" => "../api/notifications.php",
            "handler" => "patchNotification",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "DELETE:notifications/([0-9]+)" => [
            "file" => "../api/notifications.php",
            "handler" => "deleteNotification",
            "middleware" => "auth:owner",
        ],
        // Tickets
        "POST:tickets" => [
            "file" => "../api/tickets.php",
            "handler" => "createTicket",
            "middleware" => "auth:customer",
        ],
        "GET:tickets/customer/([0-9]+)" => [
            "file" => "../api/tickets.php",
            "handler" => "getCustomerTickets",
            "middleware" => "auth:customer",
        ],
        "GET:tickets" => [
            "file" => "../api/tickets.php",
            "handler" => "getAllTickets",
            "middleware" => "auth:admin,owner,employee",
        ],
        "PUT:tickets/([0-9]+)" => [
            "file" => "../api/tickets.php",
            "handler" => "updateTicket",
            "middleware" => "auth:admin,owner,employee",
        ],
        "DELETE:tickets/([0-9]+)" => [
            "file" => "../api/tickets.php",
            "handler" => "deleteTicket",
            "middleware" => "auth:admin",
        ],
        // Contracts
        "GET:contracts" => [
            "file" => "../api/contracts.php",
            "handler" => "getContracts",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "POST:contracts" => [
            "file" => "../api/contracts.php",
            "handler" => "createContract",
            "middleware" => "auth:owner,employee",
        ],
        "GET:contracts/([0-9]+)" => [
            "file" => "../api/contracts.php",
            "handler" => "getContractById",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "PUT:contracts/([0-9]+)" => [
            "file" => "../api/contracts.php",
            "handler" => "updateContract",
            "middleware" => "auth:owner,employee",
        ],
        "POST:contracts/end" => [
            "file" => "../api/contracts.php",
            "handler" => "endContract",
            "middleware" => "auth:admin,owner,employee",
        ],
        "DELETE:contracts/([0-9]+)" => [
            "file" => "../api/contracts.php",
            "handler" => "deleteContract",
            "middleware" => "auth:owner",
        ],
        // Payments
        "GET:payments" => [
            "file" => "../api/payments.php",
            "handler" => "getPayments",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "POST:payments" => [
            "file" => "../api/payments.php",
            "handler" => "createPayment",
            "middleware" => "auth:owner,employee,customer",
        ],
        "GET:payments/([0-9]+)" => [
            "file" => "../api/payments.php",
            "handler" => "getPaymentById",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "PUT:payments/([0-9]+)" => [
            "file" => "../api/payments.php",
            "handler" => "updatePayment",
            "middleware" => "auth:owner,employee",
        ],
        "PATCH:payments/([0-9]+)" => [
            "file" => "../api/payments.php",
            "handler" => "patchPayment",
            "middleware" => "auth:owner,employee",
        ],
        "DELETE:payments/([0-9]+)" => [
            "file" => "../api/payments.php",
            "handler" => "deletePayment",
            "middleware" => "auth:owner",
        ],
        // Invoices
        "GET:invoices" => [
            "file" => "../api/invoices.php",
            "handler" => "getInvoices",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "POST:invoices" => [
            "file" => "../api/invoices.php",
            "handler" => "createInvoice",
            "middleware" => "auth:owner,employee",
        ],
        "POST:invoices/bulk" => [
            "file" => "../api/invoices.php",
            "handler" => "createBulkInvoices",
            "middleware" => "auth:owner,employee",
        ],
        "GET:invoices/([0-9]+)" => [
            "file" => "../api/invoices.php",
            "handler" => "getInvoiceDetails",
            "middleware" => "auth:admin,owner,employee,customer",
        ],
        "PUT:invoices/([0-9]+)" => [
            "file" => "../api/invoices.php",
            "handler" => "updateInvoice",
            "middleware" => "auth:owner,employee",
        ],
        "PATCH:invoices/([0-9]+)" => [
            "file" => "../api/invoices.php",
            "handler" => "patchInvoice",
            "middleware" => "auth:owner,employee",
        ],
        "DELETE:invoices/([0-9]+)" => [
            "file" => "../api/invoices.php",
            "handler" => "deleteInvoice",
            "middleware" => "auth:owner",
        ],

        // Employee Assignments
        "GET:employee_assignments" => [
            "file" => "../api/employee_assignments.php",
            "handler" => "getEmployeeAssignments",
            "middleware" => "auth:owner",
        ],
        "POST:employee_assignments" => [
            "file" => "../api/employee_assignments.php",
            "handler" => "createEmployeeAssignment",
            "middleware" => "auth:owner,employee",
        ],
        "GET:employee_assignments/([0-9]+)" => [
            "file" => "../api/employee_assignments.php",
            "handler" => "getEmployeeAssignmentById",
            "middleware" => "auth:owner",
        ],
        "PUT:employee_assignments/([0-9]+)" => [
            "file" => "../api/employee_assignments.php",
            "handler" => "updateEmployeeAssignment",
            "middleware" => "auth:owner,employee",
        ],
        "PATCH:employee_assignments/([0-9]+)" => [
            "file" => "../api/employee_assignments.php",
            "handler" => "patchEmployeeAssignment",
            "middleware" => "auth:owner,employee",
        ],
        "DELETE:employee_assignments/([0-9]+)" => [
            "file" => "../api/employee_assignments.php",
            "handler" => "deleteEmployeeAssignment",
            "middleware" => "auth:owner,employee",
        ],
        // Branch Customers
        "GET:branch_customers" => [
            "file" => "../api/branch_customers.php",
            "handler" => "getBranchCustomers",
            "middleware" => "auth:owner,employee",
        ],
        "POST:branch_customers" => [
            "file" => "../api/branch_customers.php",
            "handler" => "createBranchCustomer",
            "middleware" => "auth:owner,employee",
        ],
        "GET:branch_customers/([0-9]+)" => [
            "file" => "../api/branch_customers.php",
            "handler" => "getBranchCustomerById",
            "middleware" => "auth:owner,employee",
        ],
        "PUT:branch_customers/([0-9]+)" => [
            "file" => "../api/branch_customers.php",
            "handler" => "updateBranchCustomer",
            "middleware" => "auth:owner,employee",
        ],
        "PATCH:branch_customers/([0-9]+)" => [
            "file" => "../api/branch_customers.php",
            "handler" => "patchBranchCustomer",
            "middleware" => "auth:owner,employee",
        ],
        "DELETE:branch_customers/([0-9]+)" => [
            "file" => "../api/branch_customers.php",
            "handler" => "deleteBranchCustomer",
            "middleware" => "auth:owner,employee",
        ],
        // Reports
    // Reports - Admin: Reports for all branches
        "GET:reports/revenue/all" => [
            "file" => "../api/reports.php",
            "handler" => "getAllBranchesRevenueReport",
            "middleware" => "auth:admin",
        ],
        "GET:reports/rooms/all" => [
            "file" => "../api/reports.php",
            "handler" => "getAllBranchesRoomStatusReport",
            "middleware" => "auth:admin",
        ],
        "GET:reports/contracts/all" => [
            "file" => "../api/reports.php",
            "handler" => "getAllBranchesContractReport",
            "middleware" => "auth:admin",
        ],
        "GET:reports/utility-usage/all" => [
            "file" => "../api/reports.php",
            "handler" => "getAllBranchesUtilityUsageReport",
            "middleware" => "auth:admin",
        ],
        "GET:reports/maintenance/all" => [
            "file" => "../api/reports.php",
            "handler" => "getAllBranchesMaintenanceReport",
            "middleware" => "auth:admin",
        ],

        // Reports - Owner: Reports for owned branches
        "GET:reports/revenue/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getRevenueReport",
            "middleware" => "auth:owner",
        ],
        "GET:reports/rooms/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getRoomStatusReport",
            "middleware" => "auth:owner",
        ],
        "GET:reports/contracts/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getContractReport",
            "middleware" => "auth:owner",
        ],
        "GET:reports/utility-usage/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getUtilityUsageReport",
            "middleware" => "auth:owner",
        ],
        "GET:reports/maintenance/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getMaintenanceReport",
            "middleware" => "auth:owner",
        ],

        // Reports - Employee: Reports for assigned branches
        "GET:reports/revenue/employee/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getAssignedBranchesRevenueReport",
            "middleware" => "auth:employee",
        ],
        "GET:reports/rooms/employee/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getAssignedBranchesRoomStatusReport",
            "middleware" => "auth:employee",
        ],
        "GET:reports/contracts/employee/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getAssignedBranchesContractReport",
            "middleware" => "auth:employee",
        ],
        "GET:reports/utility-usage/employee/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getAssignedBranchesUtilityUsageReport",
            "middleware" => "auth:employee",
        ],
        "GET:reports/maintenance/employee/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getAssignedBranchesMaintenanceReport",
            "middleware" => "auth:employee",
        ],

        // Reports - Customer: User-specific reports
        "GET:reports/customer/([0-9]+)/contracts" => [
            "file" => "../api/reports.php",
            "handler" => "getCustomerContracts",
            "middleware" => "auth:customer",
        ],
        "GET:reports/customer/([0-9]+)/invoices" => [
            "file" => "../api/reports.php",
            "handler" => "getCustomerInvoices",
            "middleware" => "auth:customer",
        ],
        "GET:reports/customer/([0-9]+)/utility-usage" => [
            "file" => "../api/reports.php",
            "handler" => "getCustomerUtilityUsage",
            "middleware" => "auth:customer",
        ],
        "GET:reports/customer/([0-9]+)/maintenance" => [
            "file" => "../api/reports.php",
            "handler" => "getCustomerMaintenanceRequests",
            "middleware" => "auth:customer",
        ],
        "GET:reports/customer/([0-9]+)/invoices/([0-9]+)" => [
            "file" => "../api/reports.php",
            "handler" => "getCustomerInvoiceDetails",
            "middleware" => "auth:customer",
        ],
        // Config
        "GET:config" => [
            "file" => "../api/utils/config.php",
            "handler" => "getConfig",
            "middleware" => null,
        ],
    ];

    foreach ($routes as $route => $config) {

        list($routeMethod, $routePattern) = explode(":", $route);
        $pattern = "#^" . $routePattern . '$#';
        // Extract query string and match base path
        $endpointWithoutQuery = strtok($endpoint, "?");
        if (
            $method === $routeMethod &&
            preg_match($pattern, $endpointWithoutQuery, $matches)
        ) {
            try {
                // Apply middleware
                if ($config["middleware"] === "non-auth") {
                    nonAuthMiddleware();
                } elseif (
                    $config["middleware"] &&
                    strpos($config["middleware"], "auth") === 0
                ) {
                    $roles = explode(":", $config["middleware"])[1];
                    authMiddleware($roles);
                }

                // Resolve file path
                $absolutePath = realpath(__DIR__ . "/" . $config["file"]);
                if ($absolutePath === false) {
                    error_log(
                        "Không tìm thấy file: " .
                            __DIR__ .
                            "/" .
                            $config["file"]
                    );
                    responseJson(
                        ["message" => "Không tìm thấy file handler"],
                        500
                    );
                    return;
                }

                // Include handler file and call function
                require_once $absolutePath;
                if (function_exists($config["handler"])) {
                    // Pass route parameters (e.g., ID) to handler
                    array_shift($matches); // Remove full match
                    call_user_func_array($config["handler"], $matches);
                } else {
                    error_log("Handler không tồn tại: " . $config["handler"]);
                    responseJson(["message" => "Handler không tồn tại"], 500);
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
                responseJson(["message" => "Lỗi máy chủ"], 500);
            }
            return;
        }
    }

    // Log unmatched endpoint for debugging
    error_log("Endpoint không tồn tại: $method $endpoint");
    responseJson(["message" => "Endpoint không tồn tại"], 404);
}
?>
