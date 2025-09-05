<?php
// welcome.php
include '../config/db.php';

// --- (1) FETCH STATISTICS CARDS DATA ---

// Total Revenue (Commission)
$total_revenue = 0; // Default value
$revenue_result = $conn->query("SELECT SUM(commission_amount) as total FROM transactions");
if ($revenue_result) {
    $total_revenue = $revenue_result->fetch_assoc()['total'] ?? 0;
}

// Total Customers
$total_customers = 0; // Default value
$customers_result = $conn->query("SELECT COUNT(id) as total FROM customers");
if ($customers_result) {
    $total_customers = $customers_result->fetch_assoc()['total'] ?? 0;
}

// Total Transactions
$total_transactions = 0; // Default value
$transactions_result = $conn->query("SELECT COUNT(id) as total FROM transactions");
if ($transactions_result) {
    $total_transactions = $transactions_result->fetch_assoc()['total'] ?? 0;
}

// Total Companies
$total_companies = 0; // Default value
$companies_result = $conn->query("SELECT COUNT(id) as total FROM company_commissions");
if ($companies_result) {
    $total_companies = $companies_result->fetch_assoc()['total'] ?? 0;
}

// NEW: Total Transacted Amount
$total_transacted_amount = 0; // Default value
$transacted_result = $conn->query("SELECT SUM(actual_paid_amount) as total FROM transactions");
if ($transacted_result) {
    $total_transacted_amount = $transacted_result->fetch_assoc()['total'] ?? 0;
}


// --- (2) FETCH CHART DATA ---

// A. Monthly Revenue (Bar Chart)
$monthly_revenue_data = array_fill(0, 12, 0);
$current_year = date('Y');
// NOTE: This chart still shows total transaction amounts, not commission.
$monthly_sql = "SELECT MONTH(transaction_date) as month, SUM(actual_paid_amount) as total FROM transactions WHERE YEAR(transaction_date) = ? GROUP BY MONTH(transaction_date)";
$stmt_monthly = $conn->prepare($monthly_sql);
if ($stmt_monthly) {
    $stmt_monthly->bind_param("i", $current_year);
    $stmt_monthly->execute();
    $monthly_result = $stmt_monthly->get_result();
    if ($monthly_result) {
        while($row = $monthly_result->fetch_assoc()){
            $monthly_revenue_data[$row['month'] - 1] = round($row['total']);
        }
    }
    $stmt_monthly->close();
}

// B. Payment Mode Distribution (Doughnut Chart)
$cash_total = 0;
$online_total = 0;
$payment_mode_sql = "SELECT SUM(t.amount) as total_amount, td.detail_type FROM transactions t JOIN transaction_details td ON t.id = td.transaction_id WHERE td.detail_type IN ('cash', 'online') GROUP BY td.detail_type";
$payment_mode_result = $conn->query($payment_mode_sql);
if ($payment_mode_result) {
    while($row = $payment_mode_result->fetch_assoc()){
        if ($row['detail_type'] == 'cash') {
            $cash_total = $row['total_amount'] ?? 0;
        } else if ($row['detail_type'] == 'online') {
            $online_total = $row['total_amount'] ?? 0;
        }
    }
}


// C. Top Performing Companies (Horizontal Bar Chart)
$top_companies_labels = [];
$top_companies_data = [];
$top_companies_sql = "SELECT company_name, SUM(grand_total) as total_collected FROM transactions GROUP BY company_name ORDER BY total_collected DESC LIMIT 5";
$top_companies_result = $conn->query($top_companies_sql);
if ($top_companies_result) {
    while($row = $top_companies_result->fetch_assoc()){
        $top_companies_labels[] = $row['company_name'];
        $top_companies_data[] = round($row['total_collected']);
    }
}


// --- (3) FETCH RECENT ACTIVITY ---
$recent_transactions_sql = "
    SELECT t.actual_paid_amount, t.transaction_date, c.name as customer_name 
    FROM transactions t 
    JOIN customers c ON t.customer_id = c.id
    ORDER BY t.id DESC 
    LIMIT 5
";
$recent_transactions_result = $conn->query($recent_transactions_sql);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f7f8fc; }
        .stat-card {
            background: white;
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.07), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .stat-card:hover { transform: translateY(-8px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        .chart-card { background: white; border-radius: 1.5rem; padding: 2rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.07); }
    </style>
</head>
<body class="p-4 md:p-6">

    <div class="container mx-auto">
        <div data-aos="fade-down" class="mb-8">
            <h1 class="text-4xl font-extrabold tracking-tight text-gray-800">Admin Dashboard</h1>
            <p class="mt-2 text-lg text-gray-500">Welcome back! Here's your business overview.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6 mb-8">
            <div class="stat-card border-l-8 border-green-500" data-aos="fade-up" data-aos-delay="100">
                <i class="fas fa-wallet fa-3x absolute -right-2 -bottom-2 text-green-500 opacity-70"></i>
                <p class="text-sm font-medium text-gray-500">Total Revenue</p>
                <p class="text-4xl font-bold text-gray-900 mt-1">₹<?php echo number_format($total_revenue, 0); ?></p>
            </div>
            <div class="stat-card border-l-8 border-cyan-500" data-aos="fade-up" data-aos-delay="200">
                <i class="fas fa-exchange-alt fa-3x absolute -right-2 -bottom-2 text-cyan-500 opacity-70"></i>
                <p class="text-sm font-medium text-gray-500">Total Transacted</p>
                <p class="text-4xl font-bold text-gray-900 mt-1">₹<?php echo number_format($total_transacted_amount, 0); ?></p>
            </div>
            <div class="stat-card border-l-8 border-blue-500" data-aos="fade-up" data-aos-delay="300">
                <i class="fas fa-users fa-3x absolute -right-2 -bottom-2 text-blue-500 opacity-70"></i>
                <p class="text-sm font-medium text-gray-500">Total Customers</p>
                <p class="text-4xl font-bold text-gray-900 mt-1"><?php echo $total_customers; ?></p>
            </div>
            <div class="stat-card border-l-8 border-purple-500" data-aos="fade-up" data-aos-delay="400">
                <i class="fas fa-receipt fa-3x absolute -right-2 -bottom-2 text-purple-500 opacity-70"></i>
                <p class="text-sm font-medium text-gray-500">Total Transactions</p>
                <p class="text-4xl font-bold text-gray-900 mt-1"><?php echo $total_transactions; ?></p>
            </div>
            <div class="stat-card border-l-8 border-orange-500" data-aos="fade-up" data-aos-delay="500">
                <i class="fas fa-building fa-3x absolute -right-2 -bottom-2 text-orange-500 opacity-70"></i>
                <p class="text-sm font-medium text-gray-500">Total Companies</p>
                <p class="text-4xl font-bold text-gray-900 mt-1"><?php echo $total_companies; ?></p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 mb-8">
            <div class="lg:col-span-3 chart-card" data-aos="fade-right">
                <h3 class="text-xl font-semibold text-gray-700 mb-4">Monthly Revenue (<?php echo $current_year; ?>)</h3>
                <div class="h-96"><canvas id="monthlyRevenueChart"></canvas></div>
            </div>
            <div class="lg:col-span-2 space-y-6">
                <div class="chart-card h-60" data-aos="fade-left">
                    <h3 class="text-xl font-semibold text-gray-700 mb-4">Payment Modes</h3>
                    <div class="h-40"><canvas id="paymentModeChart"></canvas></div>
                </div>
                 <div class="chart-card" data-aos="fade-left" data-aos-delay="100">
                    <h3 class="text-xl font-semibold text-gray-700 mb-4">Top Companies</h3>
                    <div class="h-64"><canvas id="topCompaniesChart"></canvas></div>
                </div>
            </div>
        </div>
        
        <div class="chart-card" data-aos="fade-up">
            <h3 class="text-xl font-semibold text-gray-700 mb-4">Recent Activity</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <tbody class="divide-y divide-gray-200">
                        <?php if ($recent_transactions_result && $recent_transactions_result->num_rows > 0): ?>
                            <?php while($row = $recent_transactions_result->fetch_assoc()): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-4 px-2"><div class="p-3 rounded-full bg-green-100"><i class="fas fa-check text-green-600"></i></div></td>
                                    <td class="py-4 px-2"><p class="font-semibold text-gray-800"><?php echo htmlspecialchars($row['customer_name']); ?></p></td>
                                    <td class="py-4 px-2"><p class="text-sm text-gray-500">made a payment.</p></td>
                                    <td class="py-4 px-2 text-right"><p class="font-bold text-gray-800">₹<?php echo number_format($row['actual_paid_amount'], 0); ?></p></td>
                                    <td class="py-4 px-2 text-right"><p class="text-sm text-gray-500"><?php echo date("d M, Y", strtotime($row['transaction_date'])); ?></p></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center text-gray-500 py-8">No recent transactions found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({ duration: 800, once: true });

        const formatCurrency = (value) => `₹${parseInt(value).toLocaleString('en-IN')}`;
        const formatK = (value) => `₹${(value / 1000)}k`;

        // 1. Monthly Revenue Bar Chart
        const monthlyCtx = document.getElementById('monthlyRevenueChart').getContext('2d');
        const revenueGradient = monthlyCtx.createLinearGradient(0, 0, 0, 400);
        revenueGradient.addColorStop(0, 'rgba(79, 70, 229, 0.8)');
        revenueGradient.addColorStop(1, 'rgba(129, 140, 248, 0.5)');
        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode($monthly_revenue_data); ?>,
                    backgroundColor: revenueGradient,
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 2, borderRadius: 8, borderSkipped: false,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { callback: formatK } }, x: { grid: { display: false } } },
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ` Revenue: ${formatCurrency(c.parsed.y)}` } } }
            }
        });

        // 2. Payment Mode Doughnut Chart
        const paymentCtx = document.getElementById('paymentModeChart').getContext('2d');
        new Chart(paymentCtx, {
            type: 'doughnut',
            data: {
                labels: ['Cash', 'Online'],
                datasets: [{
                    data: [<?php echo $cash_total; ?>, <?php echo $online_total; ?>],
                    backgroundColor: ['#10B981', '#3B82F6'],
                    borderColor: '#ffffff',
                    borderWidth: 4,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '70%',
                plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => ` ${c.label}: ${formatCurrency(c.parsed)}` } } }
            }
        });

        // 3. Top Companies Horizontal Bar Chart
        const companiesCtx = document.getElementById('topCompaniesChart').getContext('2d');
        const companiesGradient = companiesCtx.createLinearGradient(0, 0, 500, 0);
        companiesGradient.addColorStop(0, 'rgba(249, 115, 22, 0.5)');
        companiesGradient.addColorStop(1, 'rgba(251, 146, 60, 0.8)');
        new Chart(companiesCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($top_companies_labels); ?>,
                datasets: [{
                    label: 'Total Collected',
                    data: <?php echo json_encode($top_companies_data); ?>,
                    backgroundColor: companiesGradient,
                    borderColor: 'rgba(249, 115, 22, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                scales: { y: { grid: { display: false } }, x: { ticks: { callback: formatK } } },
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => ` Collected: ${formatCurrency(c.parsed.x)}` } } }
            }
        });
    </script>
</body>
</html>