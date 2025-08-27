<?php
session_start();

// 1. Check if the user is logged in. If not, redirect to the Login page.
if (!isset($_SESSION['customer_id'])) {
    header("Location: Login.php");
    exit();
}

include '../config/db.php'; // Make sure this path is correct

// 2. Fetch all data for the logged-in customer
$customerId = $_SESSION['customer_id'];
$customer = null;
$documents = [];
$transactions = [];
$total_paid = 0;
$dues_amount = 0;
$advance_amount = 0;

// Fetch customer details from 'customers' table
$stmt_customer = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt_customer->bind_param("i", $customerId);
$stmt_customer->execute();
$result_customer = $stmt_customer->get_result();

if ($result_customer->num_rows == 1) {
    $customer = $result_customer->fetch_assoc();
} else {
    // If customer not found, destroy session and redirect
    session_destroy();
    header("Location: Login.php");
    exit();
}
$stmt_customer->close();

// Fetch financial details from 'customer_finances' table
$stmt_finances = $conn->prepare("SELECT dues_amount, advance_amount FROM customer_finances WHERE customer_id = ?");
$stmt_finances->bind_param("i", $customerId);
$stmt_finances->execute();
$result_finances = $stmt_finances->get_result();
if ($result_finances->num_rows == 1) {
    $finances = $result_finances->fetch_assoc();
    $dues_amount = $finances['dues_amount'];
    $advance_amount = $finances['advance_amount'];
}
$stmt_finances->close();


// Fetch all associated documents from 'customer_documents' table
$stmt_docs = $conn->prepare("SELECT document_type, document_number, document_image_path FROM customer_documents WHERE customer_id = ? ORDER BY id ASC");
$stmt_docs->bind_param("i", $customerId);
$stmt_docs->execute();
$result_docs = $stmt_docs->get_result();
while ($doc = $result_docs->fetch_assoc()) {
    $documents[] = $doc;
}
$stmt_docs->close();

// Fetch all transaction data
$sql_trans = "
    SELECT 
        t.id as transaction_id, t.grand_total, t.transaction_date, t.payment_mode,
        td.detail_type, td.denomination_or_platform, td.quantity_or_utr, td.amount as detail_amount
    FROM transactions t
    LEFT JOIN transaction_details td ON t.id = td.transaction_id
    WHERE t.customer_id = ?
    ORDER BY t.transaction_date DESC, t.id DESC
";
$stmt_trans = $conn->prepare($sql_trans);
$stmt_trans->bind_param("i", $customerId);
$stmt_trans->execute();
$result_trans = $stmt_trans->get_result();

if ($result_trans) {
    while ($row = $result_trans->fetch_assoc()) {
        $tid = $row['transaction_id'];
        if (!isset($transactions[$tid])) {
            $transactions[$tid] = [
                'details' => ['transaction_date' => $row['transaction_date'], 'grand_total' => $row['grand_total'], 'payment_mode' => $row['payment_mode']],
                'cash_breakdown' => [], 'online_breakdown' => []
            ];
            $total_paid += $row['grand_total'];
        }
        if ($row['detail_type'] === 'cash') $transactions[$tid]['cash_breakdown'][] = $row;
        elseif ($row['detail_type'] === 'online') $transactions[$tid]['online_breakdown'][] = $row;
    }
}
$stmt_trans->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <style>
        
        body { 
            font-family: 'Poppins', sans-serif; 
            background-color: #0a101f;
            color: #e2e8f0;
            overflow-x: hidden;
        }
        #particle-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.5;
        }
        .nav-link.active { color: #2dd4bf; font-weight: 700; }
        .card {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(56, 189, 248, 0.2);
            box-shadow: 0 0 20px rgba(0, 200, 255, 0.1), 0 0 40px rgba(0, 200, 255, 0.05);
            transition: all 0.3s ease;
        }
        .card:hover {
            border-color: rgba(56, 189, 248, 0.4);
            transform: translateY(-5px);
        }
        .gradient-text {
            background: linear-gradient(90deg, #2dd4bf, #38bdf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .section-title {
            text-shadow: 0 0 15px rgba(45, 212, 191, 0.3);
        }
        /* Fix for GSAP animation flash */
        .hero-img, .hero-title, .hero-subtitle, .stat-item {
            opacity: 0;
            visibility: hidden;
        }
    </style>
</head>
<body class="text-slate-300">
    <canvas id="particle-canvas"></canvas>

    <!-- Header & Navbar -->
    <header class="bg-slate-900/60 backdrop-blur-lg shadow-lg shadow-cyan-500/5 sticky top-4 z-40 mx-4 rounded-2xl border border-cyan-500/20">
        <nav class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex-shrink-0">
                    <a href="#hero" class="text-2xl font-bold gradient-text flex items-center gap-2"><i class="fas fa-bolt"></i> CMS</a>
                </div>
                <!-- Desktop Menu -->
                <div class="hidden md:block">
                    <div class="ml-10 flex items-baseline space-x-2">
                        <a href="#profile" class="nav-link text-slate-300 hover:text-cyan-400 px-3 py-2 rounded-md text-sm font-medium">Profile</a>
                        <a href="#documents" class="nav-link text-slate-300 hover:text-cyan-400 px-3 py-2 rounded-md text-sm font-medium">Documents</a>
                        <a href="#transactions" class="nav-link text-slate-300 hover:text-cyan-400 px-3 py-2 rounded-md text-sm font-medium">Transactions</a>
                        <a href="Logout.php" class="bg-cyan-500 text-slate-900 hover:bg-cyan-400 px-4 py-2 rounded-full text-sm font-bold transition-transform hover:scale-105 shadow-md shadow-cyan-500/20">
                            <i class="fas fa-sign-out-alt mr-2"></i>Logout
                        </a>
                    </div>
                </div>
                <!-- Mobile Menu Button -->
                <div class="-mr-2 flex md:hidden">
                    <button id="mobile-menu-button" type="button" class="bg-slate-800 inline-flex items-center justify-center p-2 rounded-md text-slate-400 hover:text-white hover:bg-slate-700">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
        </nav>
        <!-- Mobile Menu -->
        <div class="md:hidden hidden" id="mobile-menu">
            <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3">
                <a href="#profile" class="nav-link text-slate-300 hover:bg-slate-700 block px-3 py-2 rounded-md text-base font-medium">Profile</a>
                <a href="#documents" class="nav-link text-slate-300 hover:bg-slate-700 block px-3 py-2 rounded-md text-base font-medium">Documents</a>
                <a href="#transactions" class="nav-link text-slate-300 hover:bg-slate-700 block px-3 py-2 rounded-md text-base font-medium">Transactions</a>
                <a href="Logout.php" class="bg-cyan-500/20 text-cyan-300 block px-3 py-2 rounded-md text-base font-medium">Logout</a>
            </div>
        </div>
    </header>

    <main class="container mx-auto p-4 sm:p-6 lg:p-8">
        
        <!-- Hero Section -->
        <section id="hero" class="text-center py-20">
            <img class="w-32 h-32 rounded-full mx-auto object-cover border-4 border-cyan-500/30 shadow-2xl shadow-cyan-500/20 hero-img" 
                 src="<?php echo !empty($customer['photo_path']) ? '../Admin/' . htmlspecialchars($customer['photo_path']) : 'https://placehold.co/128x128/0f172a/99f6e4?text=' . strtoupper(substr($customer['name'], 0, 1)); ?>" 
                 alt="Profile Photo"
                 onerror="this.onerror=null; this.src='https://placehold.co/128x128/0f172a/99f6e4?text=??';">
            <h1 class="mt-6 text-4xl md:text-6xl font-extrabold text-slate-100 hero-title">Welcome Back, <span class="gradient-text"><?php echo htmlspecialchars(explode(' ', $customer['name'])[0]); ?>!</span></h1>
            <p class="text-slate-400 mt-4 text-lg max-w-2xl mx-auto hero-subtitle">Your entire financial world, beautifully organized and ready for you.</p>
            
            <div class="mt-12 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 max-w-6xl mx-auto">
                <div class="card p-6 rounded-2xl stat-item">
                    <div class="text-cyan-400 text-4xl"><i class="fas fa-wallet"></i></div>
                    <p class="text-4xl font-bold mt-2 text-white">₹<?php echo number_format($total_paid, 2); ?></p>
                    <p class="text-slate-400 text-sm">Total Paid</p>
                </div>
                <div class="card p-6 rounded-2xl stat-item">
                    <div class="text-red-400 text-4xl"><i class="fas fa-exclamation-circle"></i></div>
                    <p class="text-4xl font-bold mt-2 text-white">₹<?php echo number_format($dues_amount, 2); ?></p>
                    <p class="text-slate-400 text-sm">Dues Amount</p>
                </div>
                <div class="card p-6 rounded-2xl stat-item">
                    <div class="text-green-400 text-4xl"><i class="fas fa-check-circle"></i></div>
                    <p class="text-4xl font-bold mt-2 text-white">₹<?php echo number_format($advance_amount, 2); ?></p>
                    <p class="text-slate-400 text-sm">Advance Amount</p>
                </div>
                <div class="card p-6 rounded-2xl stat-item">
                    <div class="text-cyan-400 text-4xl"><i class="fas fa-receipt"></i></div>
                    <p class="text-4xl font-bold mt-2 text-white"><?php echo count($transactions); ?></p>
                    <p class="text-slate-400 text-sm">Transactions</p>
                </div>
            </div>
        </section>

        <!-- Profile Section -->
        <section id="profile" class="mt-16 pt-16">
            <div class="text-center mb-12" data-aos="fade-up">
                <h2 class="text-4xl font-bold text-white section-title">My Profile</h2>
                <p class="text-slate-400 mt-2">Your personal and professional information.</p>
            </div>
            <div class="card max-w-4xl mx-auto p-8 rounded-2xl shadow-2xl" data-aos="fade-up" data-aos-delay="100">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="flex items-center gap-4"><i class="fas fa-user-tie text-2xl text-cyan-400"></i><div><span class="text-sm text-slate-400">Father's Name</span><p class="font-semibold text-lg text-white"><?php echo htmlspecialchars($customer['father_name'] ?: 'N/A'); ?></p></div></div>
                    <div class="flex items-center gap-4"><i class="fas fa-envelope text-2xl text-cyan-400"></i><div><span class="text-sm text-slate-400">Email</span><p class="font-semibold text-lg text-white"><?php echo htmlspecialchars($customer['email'] ?: 'N/A'); ?></p></div></div>
                    <div class="flex items-center gap-4"><i class="fas fa-mobile-alt text-2xl text-cyan-400"></i><div><span class="text-sm text-slate-400">Mobile</span><p class="font-semibold text-lg text-white"><?php echo htmlspecialchars($customer['mobile_no']); ?></p></div></div>
                    <div class="flex items-center gap-4"><i class="fas fa-building text-2xl text-cyan-400"></i><div><span class="text-sm text-slate-400">Company</span><p class="font-semibold text-lg text-white"><?php echo htmlspecialchars($customer['company_name'] ?: 'N/A'); ?></p></div></div>
                    <div class="flex items-center gap-4 md:col-span-2"><i class="fas fa-id-badge text-2xl text-cyan-400"></i><div><span class="text-sm text-slate-400">Employee ID</span><p class="font-semibold text-lg text-white"><?php echo htmlspecialchars($customer['employee_id'] ?: 'N/A'); ?></p></div></div>
                </div>
            </div>
        </section>
        
        <!-- Documents Section -->
        <section id="documents" class="mt-16 pt-16">
            <div class="text-center mb-12" data-aos="fade-up">
                <h2 class="text-4xl font-bold text-white section-title">My Documents</h2>
                <p class="text-slate-400 mt-2">All your uploaded documents in one place.</p>
            </div>
            <?php if (!empty($documents)): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-8">
                    <?php foreach ($documents as $i => $doc): ?>
                        <div class="card rounded-xl overflow-hidden transition-transform hover:-translate-y-2" data-aos="fade-up" data-aos-delay="<?php echo ($i + 1) * 100; ?>">
                            <a href="<?php echo '../Admin/' . htmlspecialchars($doc['document_image_path']); ?>" target="_blank">
                                <img class="h-48 w-full object-cover" src="<?php echo '../Admin/' . htmlspecialchars($doc['document_image_path']); ?>" alt="<?php echo htmlspecialchars($doc['document_type']); ?>" onerror="this.onerror=null; this.src='https://placehold.co/400x300/0f172a/99f6e4?text=No+Image';">
                            </a>
                            <div class="p-4">
                                <h3 class="font-bold text-lg text-white"><?php echo htmlspecialchars($doc['document_type']); ?></h3>
                                <p class="text-slate-400 text-sm font-mono"><?php echo htmlspecialchars($doc['document_number']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-12 card rounded-xl shadow-lg" data-aos="fade-up"><p class="text-slate-400">No documents found.</p></div>
            <?php endif; ?>
        </section>

        <!-- Transactions Section -->
        <section id="transactions" class="mt-16 pt-16">
            <div class="text-center mb-12" data-aos="fade-up">
                <h2 class="text-4xl font-bold text-white section-title">My Transactions</h2>
                <p class="text-slate-400 mt-2">A detailed history of all your payments.</p>
            </div>
            <div class="max-w-4xl mx-auto space-y-8">
                <?php if (empty($transactions)): ?>
                    <div class="text-center py-12 card rounded-xl shadow-lg" data-aos="fade-up"><p class="text-slate-400">No transactions found.</p></div>
                <?php else: ?>
                    <?php foreach ($transactions as $i => $t): ?>
                        <div class="card rounded-xl p-6 border-l-4 border-cyan-500" data-aos="fade-up" data-aos-delay="<?php echo ($i + 1) * 100; ?>">
                            <div class="flex justify-between items-center mb-4">
                                <p class="font-bold text-3xl text-white">₹<?php echo number_format($t['details']['grand_total'], 2); ?></p>
                                <p class="text-sm text-slate-400 font-medium"><i class="far fa-calendar-alt mr-2"></i><?php echo date('d M Y, h:i A', strtotime($t['details']['transaction_date'])); ?></p>
                            </div>
                            <div class="grid grid-cols-1 <?php if (!empty($t['cash_breakdown']) && !empty($t['online_breakdown'])) echo 'md:grid-cols-2'; ?> gap-4">
                                <?php if (!empty($t['cash_breakdown'])): ?>
                                    <div class="bg-emerald-500/10 p-4 rounded-lg"><h4 class="font-semibold text-emerald-300 mb-2"><i class="fas fa-money-bill-wave mr-2"></i>Cash</h4><table class="w-full text-sm text-slate-300"><tbody><?php foreach ($t['cash_breakdown'] as $cash): ?><tr><td>₹ <?php echo number_format($cash['denomination_or_platform']); ?> x <?php echo $cash['quantity_or_utr']; ?></td><td class="text-right font-bold text-white">₹<?php echo number_format($cash['detail_amount'], 2); ?></td></tr><?php endforeach; ?></tbody></table></div>
                                <?php endif; ?>
                                <?php if (!empty($t['online_breakdown'])): ?>
                                    <div class="bg-sky-500/10 p-4 rounded-lg"><h4 class="font-semibold text-sky-300 mb-2"><i class="fas fa-satellite-dish mr-2"></i>Online</h4><div class="space-y-2"><?php foreach ($t['online_breakdown'] as $online): ?><div class="text-sm"><div class="flex justify-between"><span><?php echo htmlspecialchars($online['denomination_or_platform']); ?></span><span class="font-bold text-white">₹<?php echo number_format($online['detail_amount'], 2); ?></span></div><p class="text-slate-400 font-mono text-xs">UTR: <?php echo htmlspecialchars($online['quantity_or_utr']); ?></p></div><?php endforeach; ?></div></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // --- Particle Background Animation ---
        const canvas = document.getElementById('particle-canvas');
        const ctx = canvas.getContext('2d');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        let particles = [];

        class Particle {
            constructor(x, y, size, speedX, speedY, color) {
                this.x = x; this.y = y; this.size = size; this.speedX = speedX; this.speedY = speedY; this.color = color;
            }
            update() {
                if (this.x > canvas.width || this.x < 0) this.speedX = -this.speedX;
                if (this.y > canvas.height || this.y < 0) this.speedY = -this.speedY;
                this.x += this.speedX; this.y += this.speedY;
            }
            draw() {
                ctx.fillStyle = this.color;
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        function initParticles() {
            particles = [];
            let numberOfParticles = (canvas.width * canvas.height) / 9000;
            for (let i = 0; i < numberOfParticles; i++) {
                let size = Math.random() * 1.5 + 0.5;
                let x = Math.random() * canvas.width;
                let y = Math.random() * canvas.height;
                let speedX = (Math.random() * 0.4) - 0.2;
                let speedY = (Math.random() * 0.4) - 0.2;
                let color = 'rgba(45, 212, 191, 0.7)';
                particles.push(new Particle(x, y, size, speedX, speedY, color));
            }
        }

        function animateParticles() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            for (let i = 0; i < particles.length; i++) {
                particles[i].update();
                particles[i].draw();
            }
            requestAnimationFrame(animateParticles);
        }
        
        initParticles();
        animateParticles();
        window.addEventListener('resize', () => { canvas.width = window.innerWidth; canvas.height = window.innerHeight; initParticles(); });

        // --- GSAP & AOS Animations ---
        AOS.init({ once: true, duration: 1000, offset: 50 });

        // Use a timeline to manage animations
        const tl = gsap.timeline();

        tl.to("header", { duration: 1, y: 0, opacity: 1, ease: "power4.out", autoAlpha: 1 })
          .to(".hero-img", { duration: 1.5, scale: 1, opacity: 1, ease: "elastic.out(1, 0.5)", autoAlpha: 1 }, "-=0.8")
          .to(".hero-title", { duration: 1, y: 0, opacity: 1, ease: "power4.out", autoAlpha: 1 }, "-=1.2")
          .to(".hero-subtitle", { duration: 1, y: 0, opacity: 1, ease: "power4.out", autoAlpha: 1 }, "-=0.8")
          .to(".stat-item", { duration: 0.8, y: 0, opacity: 1, stagger: 0.2, ease: "back.out(1.7)", autoAlpha: 1 }, "-=0.5");


        // --- Mobile Menu Toggle ---
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        mobileMenuButton.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });

        // --- Active Nav Link on Scroll ---
        const sections = document.querySelectorAll('section');
        const navLinks = document.querySelectorAll('.nav-link');
        window.addEventListener('scroll', () => {
            let current = 'hero';
            sections.forEach(section => {
                if (pageYOffset >= section.offsetTop - 80) current = section.getAttribute('id');
            });
            navLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href').includes(current)) link.classList.add('active');
            });
        });
    </script>
</body>
</html>
