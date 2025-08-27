<?php
session_start();
// C:\xampp\htdocs\Cash\Branch\index.php
include '../config/db.php';
include '../config/pages.php';

// Check if user is logged in, otherwise redirect to login page
if (!isset($_SESSION['branch_user']) || !isset($_SESSION['branch_id'])) {
    header("Location: login.php");
    exit;
}

$branch_id = $_SESSION['branch_id'];
$allowed_page_files = [];

// Fetch the list of page files this branch is allowed to see
$stmt = $conn->prepare("SELECT page_file FROM branch_page_permissions WHERE branch_id = ?");
$stmt->bind_param("i", $branch_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $allowed_page_files[] = $row['page_file'];
}
$stmt->close();
$conn->close();

// Filter the master list of pages ($managed_pages) to get only the allowed ones
$sidebar_pages = array_filter($managed_pages, function($page) use ($allowed_page_files) {
    return in_array($page['file'], $allowed_page_files);
});

// Determine the default page to load in the iframe
$default_page = 'welcome.php'; // A safe default page
if (!empty($sidebar_pages)) {
    // Check if dashboard is allowed, if so make it default
    $dashboard_allowed = false;
    foreach($sidebar_pages as $page) {
        if ($page['file'] === 'dashboard.php') {
            $dashboard_allowed = true;
            break;
        }
    }
    $default_page = $dashboard_allowed ? 'dashboard.php' : reset($sidebar_pages)['file'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #f1f5f9; }
        .sidebar { background: #1e293b; }
        .sidebar-link {
            display: flex; align-items: center; padding: 0.75rem 1.25rem;
            color: #cbd5e1; font-weight: 500; border-radius: 0.5rem;
            transition: all 0.2s ease-in-out; margin: 0.25rem 0;
            white-space: nowrap;
        }
        .sidebar-link:hover { background-color: #334155; color: #ffffff; }
        .sidebar-link.active { background-color: #2563eb; color: #ffffff; font-weight: 600; }
        #content-frame { width: 100%; height: 100%; border: none; }
    </style>
</head>
<body class="flex h-screen">
    <aside id="sidebar" class="sidebar w-64 flex-shrink-0 p-4 flex flex-col text-white shadow-lg">
        <div class="sidebar-header flex items-center justify-start py-3 mb-4">
            <a href="#" class="flex items-center text-2xl font-bold"><i class="fas fa-university mr-3 text-3xl text-blue-400"></i><span>Branch Portal</span></a>
        </div>
        <div class="user-welcome p-3 mb-4 rounded-lg bg-slate-700/50">
            <p class="text-slate-400 text-sm">Welcome,</p>
            <p class="font-bold text-white truncate"><?php echo htmlspecialchars($_SESSION['branch_user']); ?></p>
        </div>
        <nav class="flex-grow overflow-y-auto">
            <ul>
                <?php if (empty($sidebar_pages)): ?>
                    <li class="p-4 text-center text-slate-400">
                        <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                        <p>No pages assigned.</p>
                    </li>
                <?php else: ?>
                    <?php foreach ($sidebar_pages as $page): ?>
                        <li>
                            <a href="<?php echo htmlspecialchars($page['file']); ?>" class="sidebar-link" target="content-frame">
                                <i class="<?php echo htmlspecialchars($page['icon']); ?> w-8 text-center text-lg"></i>
                                <span><?php echo htmlspecialchars($page['name']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </nav>
        <div class="mt-auto">
             <a href="logout.php" class="sidebar-link bg-red-600/80 hover:bg-red-600 !text-white font-bold">
                <i class="fas fa-sign-out-alt w-8 text-center text-lg"></i><span>Log Out</span>
            </a>
        </div>
    </aside>

    <main class="flex-1 p-4 sm:p-6">
        <iframe id="content-frame" name="content-frame" src="<?php echo htmlspecialchars($default_page); ?>" title="Main Content"></iframe>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const links = document.querySelectorAll('.sidebar-link');
            const contentFrame = document.getElementById('content-frame');

            // Function to set the active link
            function setActiveLink(href) {
                links.forEach(link => {
                    if (link.getAttribute('href') === href) {
                        link.classList.add('active');
                    } else {
                        link.classList.remove('active');
                    }
                });
            }

            // Set the initial active link based on the iframe's src
            const initialSrc = contentFrame.getAttribute('src');
            setActiveLink(initialSrc);

            // Add click listeners to update active state
            links.forEach(link => {
                link.addEventListener('click', function(e) {
                    // Check if it's not the logout link
                    if (!this.href.includes('logout.php')) {
                        const targetHref = this.getAttribute('href');
                        setActiveLink(targetHref);
                    }
                });
            });
        });
    </script>
</body>
</html>
