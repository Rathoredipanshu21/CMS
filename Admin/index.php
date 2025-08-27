<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header("Location: Login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --sidebar-bg: #111827; /* Dark Gray */
            --sidebar-link-color: #9ca3af; /* Lighter Gray */
            --sidebar-link-hover-bg: rgba(255, 255, 255, 0.05);
            --sidebar-link-active-bg: #4f46e5; /* Indigo */
            --main-bg: #f3f4f6; /* Light Gray */
            --text-light: #ffffff;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--main-bg);
        }

        /* --- Stylish Scrollable Sidebar --- */
        .sidebar {
            background: var(--sidebar-bg);
            transition: min-width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Navigation container that will scroll */
        .sidebar-nav {
            flex-grow: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* Custom Scrollbar Styling */
        .sidebar-nav::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar-nav::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-nav::-webkit-scrollbar-thumb {
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
        }
        .sidebar-nav::-webkit-scrollbar-thumb:hover {
            background-color: rgba(255, 255, 255, 0.4);
        }


        .sidebar-header .logo-text {
            transition: opacity 0.3s ease-in-out;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.85rem 1rem;
            color: var(--sidebar-link-color);
            font-weight: 500;
            border-radius: 0.5rem;
            transition: all 0.2s ease;
            margin: 0.25rem 0;
            white-space: nowrap;
        }

        .sidebar-link:hover {
            background-color: var(--sidebar-link-hover-bg);
            color: var(--text-light);
            transform: translateX(4px);
        }

        .sidebar-link.active {
            background: var(--sidebar-link-active-bg);
            color: var(--text-light);
            font-weight: 600;
            box-shadow: 0 4px 15px -3px rgba(79, 70, 229, 0.4);
        }
        
        .sidebar-link .link-text {
            transition: opacity 0.2s ease-in-out;
        }

        .sidebar-link i {
            width: 2.5rem;
            text-align: center;
            font-size: 1.1rem;
            transition: transform 0.3s ease;
        }

        .sidebar-link:hover i {
            transform: scale(1.1);
        }

        /* --- Collapsed Sidebar State --- */
        .sidebar.collapsed {
            min-width: 5.5rem;
            width: 5.5rem;
        }

        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .link-text,
        .sidebar.collapsed .sidebar-footer p {
            opacity: 0;
            pointer-events: none;
        }

        .sidebar.collapsed .sidebar-header {
            justify-content: center;
        }
        
        .sidebar.collapsed .sidebar-link {
            justify-content: center;
        }

        /* --- Main Content Area --- */
        #main-content-wrapper {
            height: 100vh;
            overflow-y: auto;
        }
        
        #content-frame {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 0.75rem;
            background-color: white;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
        }
        
        /* --- Header Buttons --- */
        .header-btn {
            background-color: #fff;
            color: #1f2937;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .header-btn:hover {
            transform: translateY(-2px) scale(1.05);
            background-color: var(--sidebar-link-active-bg);
            color: #fff;
            box-shadow: 0 7px 20px rgba(79, 70, 229, 0.3);
        }
    </style>
</head>
<body class="flex h-screen bg-gray-100">

    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar w-64 min-w-[16rem] flex-shrink-0 p-4">
        <!-- Sidebar Header -->
        <div class="sidebar-header flex items-center justify-start py-4 mb-4 flex-shrink-0">
            <a href="#" class="flex items-center text-2xl font-bold text-white">
                <i class="fas fa-rocket text-indigo-400 mr-3 text-3xl"></i>
                <span class="logo-text">CMS - Admin</span>
            </a>
        </div>

        <!-- Scrollable Navigation -->
        <nav class="sidebar-nav">
            <ul>
                <li>
                    <a href="Dashboard.php" class="sidebar-link active" target="content-frame">
                        <i class="fas fa-tachometer-alt"></i><span class="link-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="customer_add.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-user-plus"></i><span class="link-text">Add New Customer</span>
                    </a>
                </li>
                <li>
                    <a href="view_customers.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-users"></i><span class="link-text">Manage Customers</span>
                    </a>
                </li>
                <li>
                    <a href="cash_demo.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-money-bill-transfer"></i><span class="link-text">New Transaction</span>
                    </a>
                </li>
                <li>
                    <a href="received_payments.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-history"></i><span class="link-text">Transaction History</span>
                    </a>
                </li>
                <li>
                    <a href="company_commission.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-hand-holding-usd"></i><span class="link-text">Company commission</span>
                    </a>
                </li>
                <li>
                    <a href="create_branch.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-plus"></i><span class="link-text">Create Branch</span>
                    </a>
                </li>
                <li>
                    <a href="add_bank.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-university"></i><span class="link-text">Manage Banks</span>
                    </a>
                </li>
                <li>
                    <a href="bank_transactions.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-exchange-alt"></i><span class="link-text">Bank Transactions</span>
                    </a>
                </li>
                <li>
                    <a href="bank_deposits.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-piggy-bank"></i><span class="link-text">Bank Deposits</span>
                    </a>
                </li>
                <li>
                    <a href="manage_pages.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-file-alt"></i><span class="link-text">Manage Branch Pages</span>
                    </a>
                </li>
                <li>
                    <a href="customer_search.php" class="sidebar-link" target="content-frame">
                        <i class="fas fa-search"></i><span class="link-text">Customer Lookup</span>
                    </a>
                </li>
                 <li>
                    <a href="logout.php" class="sidebar-link bg-red-500 hover:bg-red-600 text-white font-bold">
                        <i class="fas fa-sign-out-alt"></i><span class="link-text">Log Out</span>
                    </a>
                </li>
            </ul>
        </nav>

        <!-- Sidebar Footer -->
        <div class="sidebar-footer mt-auto text-center text-gray-400 text-xs flex-shrink-0 pt-4">
            <p>&copy; <?php echo date("Y"); ?> Your Company</p>
        </div>
    </aside>

    <!-- Main Content -->
    <div id="main-content-wrapper" class="flex-1 flex flex-col">
        <header class="p-4 flex items-center space-x-3 bg-gray-100/80 backdrop-blur-sm sticky top-0 z-10">
             <button id="menu-toggle" class="header-btn flex items-center justify-center">
                <i class="fas fa-bars"></i>
            </button>
             <button id="fullscreen-toggle" class="header-btn flex items-center justify-center">
                <i id="fullscreen-icon" class="fas fa-expand"></i>
            </button>
        </header>

        <main class="flex-1 p-6 pt-2">
             <div class="h-full">
                <iframe id="content-frame" name="content-frame" src="Dashboard.php"></iframe>
            </div>
        </main>
    </div>

    <script>
        // --- DOM Elements ---
        const sidebar = document.getElementById('sidebar');
        const menuToggle = document.getElementById('menu-toggle');
        const fullscreenToggle = document.getElementById('fullscreen-toggle');
        const fullscreenIcon = document.getElementById('fullscreen-icon');
        const links = document.querySelectorAll('.sidebar-link');

        // --- Sidebar Toggle Handling ---
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });

        // --- Active Link Handling ---
        links.forEach(link => {
            link.addEventListener('click', function() {
                // Do not remove active class from the logout button
                if(this.href.includes('logout.php')) return;

                links.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // --- Fullscreen Mode Handling ---
        function toggleFullScreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch((err) => {
                    console.error(`Error attempting to enable full-screen mode: ${err.message} (${err.name})`);
                });
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        }

        function updateFullscreenIcon() {
            if (document.fullscreenElement) {
                fullscreenIcon.classList.remove('fa-expand');
                fullscreenIcon.classList.add('fa-compress');
            } else {
                fullscreenIcon.classList.remove('fa-compress');
                fullscreenIcon.classList.add('fa-expand');
            }
        }

        fullscreenToggle.addEventListener('click', toggleFullScreen);
        document.addEventListener('fullscreenchange', updateFullscreenIcon);

    </script>
</body>
</html>
