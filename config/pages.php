<?php
// C:\xampp\htdocs\Cash\config\pages.php

/**
 * This array holds the configuration for all pages that can be managed by the admin.
 * 'file' is the path to the page relative to the 'Branch' directory.
 * 'name' is the user-friendly name that will be displayed in the navigation.
 * 'icon' is the Font Awesome icon class for the page link.
 */
$managed_pages = [
    ['file' => 'dashboard.php', 'name' => 'Dashboard', 'icon' => 'fas fa-tachometer-alt'],
    ['file' => 'customer_add.php', 'name' => 'Add New Customer', 'icon' => 'fas fa-user-plus'],
    ['file' => 'view_customers.php', 'name' => 'Manage Customers', 'icon' => 'fas fa-users'],
    ['file' => 'cash_demo.php', 'name' => 'New Transaction', 'icon' => 'fas fa-money-bill-transfer'],
    ['file' => 'received_payments.php', 'name' => 'Transaction History', 'icon' => 'fas fa-history'],
    ['file' => 'customer_search.php', 'name' => 'Customer Lookup', 'icon' => 'fas fa-search'],
    ['file' => 'bank_deposits.php', 'name' => 'Bank Deposits', 'icon' => 'fas fa-bank'],
    
];
?>
