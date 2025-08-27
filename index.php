<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Next-Gen Cash Management</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;900&display=swap" rel="stylesheet">

    <style>
        /* Custom styles for the page */
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f7f8fa; /* A light, clean background color */
        }

        /* Animated gradient background canvas */
        #gradient-canvas {
            width: 100%;
            height: 100%;
            position: fixed;
            top: 0;
            left: 0;
            z-index: -1;
        }
        
        /* Custom text gradient for the main heading */
        .text-gradient {
            background: linear-gradient(90deg, #1e40af, #3b82f6, #6366f1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-fill-color: transparent;
        }

        /* Glassmorphism container for a modern feel */
        .glass-container {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            /* This will be controlled by GSAP for the 3D tilt effect */
            will-change: transform; 
        }

        /* Button styling for a clean and clickable look */
        .portal-btn {
            transition: all 0.3s ease-in-out;
        }
        .portal-btn:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="text-gray-800 antialiased">

    <canvas id="gradient-canvas"></canvas>

    <main class="relative min-h-screen flex items-center justify-center p-4 sm:p-6 text-center overflow-hidden">
        
        <div id="content-card" class="glass-container p-8 sm:p-12 rounded-3xl shadow-2xl max-w-4xl mx-auto" data-aos="zoom-in-up" data-aos-duration="1000">
            
            <h1 class="text-5xl md:text-7xl font-black uppercase tracking-tight text-gradient mb-5" data-aos="fade-up" data-aos-delay="200">
                Manage with Clarity
            </h1>
            
            <p class="max-w-2xl mx-auto text-lg md:text-xl text-gray-600 mb-12" data-aos="fade-up" data-aos-delay="400">
                The all-in-one platform for seamless financial control. Powerful, intuitive, and secure access for every user level.
            </p>

            <div class="flex flex-col md:flex-row gap-6 justify-center">
                
                <a href="Admin/index.php" class="portal-btn group flex items-center justify-center gap-3 px-8 py-4 text-lg font-bold text-white bg-blue-600 rounded-xl shadow-lg hover:bg-blue-700" data-aos="fade-up" data-aos-delay="600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    Admin Portal
                </a>
                
                <a href="Branch/index.php" class="portal-btn group flex items-center justify-center gap-3 px-8 py-4 text-lg font-bold text-white bg-indigo-500 rounded-xl shadow-lg hover:bg-indigo-600" data-aos="fade-up" data-aos-delay="700">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                    Branch Portal
                </a>
                
                <a href="Customers/index.php" class="portal-btn group flex items-center justify-center gap-3 px-8 py-4 text-lg font-bold text-gray-700 bg-white rounded-xl shadow-lg hover:bg-gray-100" data-aos="fade-up" data-aos-delay="800">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    Customer Portal
                </a>
            </div>
        </div>

    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- Initialize AOS ---
            AOS.init({
                once: true, // Whether animation should happen only once - while scrolling down
                mirror: false, // Whether elements should animate out while scrolling past them
            });

            // --- GSAP Animations ---

            // 1. Interactive 3D Tilt Effect for the Content Card
            const card = document.getElementById('content-card');
            
            document.addEventListener('mousemove', (e) => {
                const rect = card.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                const centerX = rect.width / 2;
                const centerY = rect.height / 2;
                
                const rotateX = (centerY - y) / 50; // Control intensity
                const rotateY = (x - centerX) / 50; // Control intensity

                gsap.to(card, {
                    rotationX: rotateX,
                    rotationY: rotateY,
                    scale: 1.05,
                    duration: 1,
                    ease: 'power3.out'
                });
            });

            document.addEventListener('mouseleave', () => {
                gsap.to(card, {
                    rotationX: 0,
                    rotationY: 0,
                    scale: 1,
                    duration: 1,
                    ease: 'elastic.out(1, 0.3)'
                });
            });


            // 2. GSAP Animated Gradient Background
            const canvas = document.getElementById('gradient-canvas');
            const ctx = canvas.getContext('2d');
            let width, height;

            const colors = [
                { r: 67, g: 13, b: 235 },   // Purple
                { r: 59, g: 130, b: 246 },  // Blue
                { r: 99, g: 102, b: 241 },  // Indigo
                { r: 30, g: 64, b: 175 }    // Darker Blue
            ];

            const blobs = colors.map(color => ({
                x: Math.random() * window.innerWidth,
                y: Math.random() * window.innerHeight,
                vx: (Math.random() - 0.5) * 0.5,
                vy: (Math.random() - 0.5) * 0.5,
                radius: Math.random() * 200 + 400, // Blob size
                color: `rgba(${color.r}, ${color.g}, ${color.b}, 0.7)`
            }));

            function resizeCanvas() {
                width = canvas.width = window.innerWidth;
                height = canvas.height = window.innerHeight;
            }
            window.addEventListener('resize', resizeCanvas);
            resizeCanvas();

            function animate() {
                ctx.clearRect(0, 0, width, height);

                blobs.forEach(blob => {
                    // Move
                    blob.x += blob.vx;
                    blob.y += blob.vy;

                    // Bounce off walls
                    if (blob.x < 0 || blob.x > width) blob.vx *= -1;
                    if (blob.y < 0 || blob.y > height) blob.vy *= -1;

                    // Draw
                    const gradient = ctx.createRadialGradient(blob.x, blob.y, 0, blob.x, blob.y, blob.radius);
                    gradient.addColorStop(0, blob.color);
                    gradient.addColorStop(1, 'rgba(0,0,0,0)');
                    ctx.fillStyle = gradient;
                    ctx.fillRect(0, 0, width, height);
                });
            }

            gsap.ticker.add(animate);
        });
    </script>
</body>
</html>