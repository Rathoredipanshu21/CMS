<?php
// Include your database connection
include '../config/db.php';

// Fetch all customers
$customers_sql = "SELECT `id`, `name`, `father_name`, `email`, `mobile_no`, `company_name`, `employee_id`, `photo_path`, `created_at` FROM `customers`";
$customers_result = mysqli_query($conn, $customers_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Report</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .customer-card { margin-bottom: 15px; }
        .transaction-card { margin: 15px 0; }
        .transaction-details-table { margin-top: 15px; }
        .customer-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
</head>
<body>
<div class="container mt-5">
    <h2 class="text-center mb-4">Customer Transactions Report</h2>

    <?php if (mysqli_num_rows($customers_result) > 0) : ?>
        <div id="customerAccordion">
            <?php while ($customer = mysqli_fetch_assoc($customers_result)) : ?>
                <?php
                // --- Logic to pre-calculate total and fetch transactions ---

                // Use prepared statements to prevent SQL injection
                $stmt_trans = mysqli_prepare($conn, "SELECT `id`, `payment_mode`, `grand_total`, `transaction_date` FROM `transactions` WHERE `customer_id` = ?");
                mysqli_stmt_bind_param($stmt_trans, "i", $customer['id']);
                mysqli_stmt_execute($stmt_trans);
                $transactions_result = mysqli_stmt_get_result($stmt_trans);

                // Fetch all transactions into an array to calculate total and reuse later
                $transactions = mysqli_fetch_all($transactions_result, MYSQLI_ASSOC);
                
                // Calculate the total amount for this customer
                $customer_total_amount = 0;
                foreach ($transactions as $transaction) {
                    $customer_total_amount += $transaction['grand_total'];
                }
                mysqli_stmt_close($stmt_trans);
                ?>

                <div class="card customer-card">
                    <div class="card-header bg-primary text-white" id="heading-<?php echo $customer['id']; ?>">
                        <div class="customer-summary">
                            <div>
                                <h5 class="mb-0">
                                    <strong><?php echo htmlspecialchars($customer['name']); ?></strong> (<?php echo htmlspecialchars($customer['company_name']); ?>)
                                </h5>
                            </div>
                            <div>
                                <span class="mr-3"><strong>Total Transactions:</strong> ₹<?php echo number_format($customer_total_amount, 2); ?></span>
                                <button class="btn btn-light btn-sm" data-toggle="collapse" data-target="#collapse-<?php echo $customer['id']; ?>" aria-expanded="false" aria-controls="collapse-<?php echo $customer['id']; ?>">
                                    View Details
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="collapse-<?php echo $customer['id']; ?>" class="collapse" aria-labelledby="heading-<?php echo $customer['id']; ?>" data-parent="#customerAccordion">
                        <div class="card-body">
                            <p>
                                <strong>Email:</strong> <?php echo htmlspecialchars($customer['email']); ?> | 
                                <strong>Mobile:</strong> <?php echo htmlspecialchars($customer['mobile_no']); ?> | 
                                <strong>Employee ID:</strong> <?php echo htmlspecialchars($customer['employee_id']); ?>
                            </p>
                            <hr>
                            
                            <?php if (count($transactions) > 0) : ?>
                                <?php foreach ($transactions as $transaction) : // Loop through the pre-fetched transactions ?>
                                    
                                    <div class="card transaction-card">
                                        <div class="card-header bg-secondary text-white">
                                            Transaction ID: <?php echo $transaction['id']; ?> | Date: <?php echo date("d-M-Y", strtotime($transaction['transaction_date'])); ?>
                                        </div>
                                        <div class="card-body">
                                            <p><strong>Grand Total:</strong> ₹<?php echo number_format($transaction['grand_total'], 2); ?></p>
                                            
                                            <?php
                                            // --- Fetch details for this specific transaction ---
                                            // Use prepared statements here as well for security
                                            $stmt_details = mysqli_prepare($conn, "SELECT `id`, `detail_type`, `denomination_or_platform`, `quantity_or_utr`, `amount` FROM `transaction_details` WHERE `transaction_id` = ?");
                                            mysqli_stmt_bind_param($stmt_details, "i", $transaction['id']);
                                            mysqli_stmt_execute($stmt_details);
                                            $details_result = mysqli_stmt_get_result($stmt_details);

                                            if (mysqli_num_rows($details_result) > 0) :
                                            ?>
                                                <table class="table table-sm table-bordered transaction-details-table">
                                                    <thead class="thead-light">
                                                        <tr>
                                                            <th>Type</th>
                                                            <th>Denomination / Platform</th>
                                                            <th>Quantity / UTR</th>
                                                            <th>Amount</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php while ($detail = mysqli_fetch_assoc($details_result)) : ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($detail['detail_type']); ?></td>
                                                                <td><?php echo htmlspecialchars($detail['denomination_or_platform']); ?></td>
                                                                <td><?php echo htmlspecialchars($detail['quantity_or_utr']); ?></td>
                                                                <td>₹<?php echo number_format($detail['amount'], 2); ?></td>
                                                            </tr>
                                                        <?php endwhile; ?>
                                                    </tbody>
                                                </table>
                                            <?php else : ?>
                                                <p class="text-muted">No specific details found for this transaction.</p>
                                            <?php 
                                            endif; 
                                            mysqli_stmt_close($stmt_details);
                                            ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-center text-muted">No transactions found for this customer.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info text-center">
            No customers found in the database.
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>