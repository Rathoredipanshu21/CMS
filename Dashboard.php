<?php
// welcome.php
include 'config/db.php';

// --- Fetch Statistics and cast/round to integers ---

// 1. Total Customers
$total_customers_result = $conn->query("SELECT COUNT(id) as total FROM customers");
$total_customers = $total_customers_result->fetch_assoc()['total'];

// 2. Financials - MODIFICATION: Round all values to get integers
$financials_result = $conn->query("SELECT SUM(final_payable_amount) as total_revenue, SUM(total_cash_amount) as total_cash, SUM(total_online_amount) as total_online FROM transactions");
$financials = $financials_result->fetch_assoc();
$total_revenue = round($financials['total_revenue'] ?? 0);
$total_cash = round($financials['total_cash'] ?? 0);
$total_online = round($financials['total_online'] ?? 0);

// 3. Data for Monthly Revenue Chart (Bar Chart) - MODIFICATION: Round values
$monthly_revenue_data = array_fill(0, 12, 0);
$current_year = date('Y');
$monthly_sql = "SELECT MONTH(transaction_date) as month, SUM(final_payable_amount) as total FROM transactions WHERE YEAR(transaction_date) = ? GROUP BY MONTH(transaction_date)";
$stmt = $conn->prepare($monthly_sql);
$stmt->bind_param("i", $current_year);
$stmt->execute();
$monthly_result = $stmt->get_result();
while($row = $monthly_result->fetch_assoc()){
    $monthly_revenue_data[$row['month'] - 1] = round($row['total']);
}
$stmt->close();

// 4. NEW: Fetch Recent Transactions
$recent_transactions_sql = "SELECT party_name, final_payable_amount, transaction_date FROM transactions ORDER BY id DESC LIMIT 5";
$recent_transactions_result = $conn->query($recent_transactions_sql);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f7f8fc;
        }
        .card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .icon-wrapper {
            width: 4rem;
            height: 4rem;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.75rem;
        }
    </style>
</head>
<body class="p-4 md:p-6">

    <div class="container mx-auto">
        <!-- Header Section -->
        <div data-aos="fade-down" class="p-8 mb-8 rounded-2xl text-white bg-gradient-to-r from-indigo-600 to-purple-600 shadow-2xl shadow-indigo-200">
            <h1 class="text-4xl font-extrabold tracking-tight">Dashboard</h1>
            <p class="mt-2 text-lg text-indigo-200">Hello! Here is your business snapshot for today, <?php echo date("l, F j, Y"); ?>.</p>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="card" data-aos="fade-up" data-aos-delay="100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Total Revenue</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1">₹<?php echo number_format($total_revenue, 0); ?></p>
                    </div>
                    <div class="icon-wrapper bg-gradient-to-tr from-green-500 to-green-300">
                        <i class="fas fa-wallet"></i>
                    </div>
                </div>
            </div>
            <div class="card" data-aos="fade-up" data-aos-delay="200">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Total Customers</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1"><?php echo $total_customers; ?></p>
                    </div>
                    <div class="icon-wrapper bg-gradient-to-tr from-blue-500 to-blue-300">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="card" data-aos="fade-up" data-aos-delay="300">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Cash Received</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1">₹<?php echo number_format($total_cash, 0); ?></p>
                    </div>
                    <div class="icon-wrapper bg-gradient-to-tr from-yellow-500 to-yellow-300">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>
            <div class="card" data-aos="fade-up" data-aos-delay="400">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Online Payments</p>
                        <p class="text-3xl font-bold text-gray-900 mt-1">₹<?php echo number_format($total_online, 0); ?></p>
                    </div>
                    <div class="icon-wrapper bg-gradient-to-tr from-purple-500 to-purple-300">
                        <i class="fas fa-credit-card"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts & Recent Activity -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Monthly Revenue Chart -->
            <div class="lg:col-span-2 card" data-aos="fade-right">
                <h3 class="text-xl font-semibold text-gray-700 mb-4">Monthly Revenue (<?php echo $current_year; ?>)</h3>
                <div class="h-80"><canvas id="monthlyRevenueChart"></canvas></div>
            </div>
            
            <!-- NEW: Recent Activity -->
            <div class="lg:col-span-1 card" data-aos="fade-left">
                <h3 class="text-xl font-semibold text-gray-700 mb-4">Recent Activity</h3>
                <div class="space-y-4">
                    <?php if ($recent_transactions_result->num_rows > 0): ?>
                        <?php while($row = $recent_transactions_result->fetch_assoc()): ?>
                            <div class="flex items-center">
                                <div class="p-3 rounded-full bg-green-100 mr-4">
                                    <i class="fas fa-check text-green-600"></i>
                                </div>
                                <div>
                                    <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($row['party_name']); ?></p>
                                    <p class="text-sm text-gray-500">₹<?php echo number_format($row['final_payable_amount'], 0); ?> - <span class="text-xs"><?php echo date("d M, Y", strtotime($row['transaction_date'])); ?></span></p>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-center text-gray-500 py-4">No recent transactions found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- AOS Animation Library -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        AOS.init({ duration: 800, once: true });

        // --- Monthly Revenue Bar Chart with Gradient ---
        const monthlyCtx = document.getElementById('monthlyRevenueChart').getContext('2d');
        const gradient = monthlyCtx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(79, 70, 229, 0.8)');
        gradient.addColorStop(1, 'rgba(129, 140, 248, 0.5)');

        new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                datasets: [{
                    label: 'Revenue',
                    data: <?php echo json_encode($monthly_revenue_data); ?>,
                    backgroundColor: gradient,
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, ticks: { callback: value => '₹' + (value / 1000) + 'k' } },
                    x: { grid: { display: false } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: context => ` Revenue: ₹${parseInt(context.parsed.y).toLocaleString('en-IN')}`
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>