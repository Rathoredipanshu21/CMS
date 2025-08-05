<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stylish Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f0f4f8; /* A very light, clean gray */
            overflow: hidden; /* Prevent scrolling on the body */
        }

        /* --- Stylish Sidebar --- */
        .sidebar {
            background: linear-gradient(180deg, rgba(35, 39, 65, 0.9), rgba(25, 28, 49, 0.95));
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .sidebar-header .logo-text {
            transition: opacity 0.3s ease-in-out;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.85rem 1rem;
            color: rgba(230, 230, 250, 0.7); /* Light lavender text */
            font-weight: 500;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
            margin: 0.5rem 0;
            white-space: nowrap; /* Prevent text wrapping */
        }

        .sidebar-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            transform: translateX(5px);
        }

        .sidebar-link.active {
            background: linear-gradient(90deg, #4f46e5, #818cf8);
            color: #ffffff;
            font-weight: 600;
            box-shadow: 0 10px 20px -5px rgba(79, 70, 229, 0.4);
        }
        
        .sidebar-link .link-text {
            transition: opacity 0.2s ease-in-out;
        }

        .sidebar-link i {
            width: 2.5rem; /* Fixed width for icons */
            text-align: center;
            font-size: 1.1rem;
            transition: transform 0.3s ease;
        }

        .sidebar-link:hover i {
            transform: scale(1.1);
        }

        /* --- Collapsed Sidebar State --- */
        .sidebar.collapsed {
            width: 5.5rem; /* Width for just icons */
        }

        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .link-text,
        .sidebar.collapsed .sidebar-footer p {
            opacity: 0;
            pointer-events: none; /* Make text un-interactable when hidden */
        }

        .sidebar.collapsed .sidebar-header {
            justify-content: center;
        }

        /* --- Main Content Area --- */
        main {
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        /* --- Iframe --- */
        #content-frame {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 1rem;
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
            transform: scale(1.1);
            background-color: #4f46e5;
            color: #fff;
        }
    </style>
</head>
<body class="flex h-screen">

    <aside id="sidebar" class="sidebar w-64 flex-shrink-0 p-4 flex flex-col">
        <div class="sidebar-header flex items-center justify-start py-4 mb-6" data-aos="fade-down">
            <a href="#" class="flex items-center text-2xl font-bold text-white">
                <i class="fas fa-rocket text-indigo-400 mr-3 text-3xl"></i>
                <span class="logo-text">AdminPro</span>
            </a>
        </div>

        <nav class="flex-grow">
           <ul>
    <li data-aos="fade-right" data-aos-delay="100">
        <a href="Dashboard.php" class="sidebar-link active" target="content-frame">
            <i class="fas fa-tachometer-alt"></i>
            <span class="link-text">Dashboard</span>
        </a>
    </li>
    <li data-aos="fade-right" data-aos-delay="200">
        <a href="cash_demo.php" class="sidebar-link" target="content-frame">
            <i class="fas fa-file-invoice-dollar"></i>
            <span class="link-text">New Transaction</span>
        </a>
    </li>
    <li data-aos="fade-right" data-aos-delay="300">
        <a href="customer_add.php" class="sidebar-link" target="content-frame">
            <i class="fas fa-user-plus"></i>
            <span class="link-text">Add Customer</span>
        </a>
    </li>
    <li data-aos="fade-right" data-aos-delay="400">
        <a href="view_customers.php" class="sidebar-link" target="content-frame">
            <i class="fas fa-users"></i>
            <span class="link-text">Manage Customers</span>
        </a>
    </li>
    <li data-aos="fade-right" data-aos-delay="500">
        <a href="received_payments.php" class="sidebar-link" target="content-frame">
            <i class="fas fa-history"></i>
            <span class="link-text">Transaction History</span>
        </a>
    </li>
    <li data-aos="fade-right" data-aos-delay="500">
        <a href="company_commission.php" class="sidebar-link" target="content-frame">
            <i class="fas fa-history"></i>
            <span class="link-text">Company Commission</span>
        </a>
    </li>
    <li data-aos="fade-right" data-aos-delay="600">
        <a href="admin_cash_demo.php" class="sidebar-link" target="content-frame">
            <i class="fas fa-sliders-h"></i>
            <span class="link-text">Commission Settings</span>
        </a>
    </li>
    <!-- <li data-aos="fade-right" data-aos-delay="600">
        <a href="companies.php" class="sidebar-link" target="content-frame">
            <i class="fas fa-sliders-h"></i>
            <span class="link-text">Companies</span>
        </a>
    </li> -->
</ul>

        </nav>

        <div class="sidebar-footer mt-auto text-center text-gray-400 text-xs" data-aos="fade-up" data-aos-delay="600">
            <p>&copy; <?php echo date("Y"); ?> Your Company</p>
        </div>
    </aside>

    <div id="main-content" class="flex-1 flex flex-col">
        <header class="p-4 flex items-center space-x-3">
             <button id="menu-toggle" class="header-btn flex items-center justify-center">
                <i class="fas fa-bars"></i>
            </button>
             <button id="fullscreen-toggle" class="header-btn flex items-center justify-center">
                <i id="fullscreen-icon" class="fas fa-expand"></i>
            </button>
        </header>

        <main class="flex-1 p-6 pt-0">
             <div class="h-full" data-aos="fade-up" data-aos-duration="800">
                <iframe id="content-frame" name="content-frame" src="Dashboard.php"></iframe>
            </div>
        </main>
    </div>


    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 600,
            once: true,
            easing: 'ease-in-out-quad',
        });

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