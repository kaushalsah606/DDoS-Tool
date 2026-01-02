<?php
ini_set('memory_limit', '-1'); // Set memory limit to unlimited
ini_set('max_execution_time', 0); // Set max execution time to unlimited
// ==============================
// 🚀 PHP Website Traffic Sender
// ==============================

// === CONFIGURATION ===
$targetUrl        = ""; // 🔗 Target URL (leave empty, will be set by user input)
$requests         = 20;  // 🔁 Total number of requests
$parallelRequests = 5;   // 🔀 Number of parallel threads
$usePost          = false; // 📡 Use POST request instead of GET
$logFile          = "traffic_log.txt"; // 📄 Log file
$errFile          = "errors.txt"; // ❌ Error log
$email            = ""; // 📧 Email to send summary
$proxyFile        = ""; // 📂 File with proxies
$enableProxyCheck = true; // ✅ Validate proxies before use


// === POST DATA IF $usePost ===
function getPostData() {
    return [
        "name" => "John Doe",
        "email" => "john@example.com"
    ];
}

// === SAMPLE USER AGENTS ===
$userAgents = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)",
    "Mozilla/5.0 (Linux; Android 11)",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)"
];

// === VALIDATE PROXY ===
function validateProxy($proxy) {
    $ch = curl_init("http://example.com");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_PROXY => $proxy
    ]);
    curl_exec($ch);
    $ok = !curl_errno($ch);
    curl_close($ch);
    return $ok;
}

// === GENERATE RANDOM IP ===
function generateRandomIP() {
    return rand(1, 255) . "." . rand(0, 255) . "." . rand(0, 255) . "." . rand(1, 254);
}

// === HANDLE PROXY UPLOAD (OPTIONAL) ===
$proxyList = [];

if (isset($_FILES['proxyFile']) && $_FILES['proxyFile']['error'] === UPLOAD_ERR_OK) {
    $proxyFile = $_FILES['proxyFile']['tmp_name'];
    if (file_exists($proxyFile)) {
        $proxyList = file($proxyFile, FILE_IGNORE_NEW_LINES);
        if ($enableProxyCheck) {
            $proxyList = array_filter($proxyList, 'validateProxy');
        }
    } else {
        echo "❌ Error: Proxy file is not valid.";
    }
} else {
    // If no proxy is uploaded, set proxy list to an empty array
    $proxyList = [];
}

// === HANDLE FORM SUBMISSION ===
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $targetUrl = $_POST['url'];
    $requests = $_POST['requests'];
    $parallelRequests = $_POST['threads'];
    $usePost = isset($_POST['usePost']) ? true : false;
    $email = $_POST['email'];

    // Initialize data tracking arrays
    $responseTimes = [];
    $statusCodes = [];
    $successCount = 0;
    $failCount = 0;

    // === BEGIN PARALLEL REQUESTS ===
    $mh = curl_multi_init();
    $handles = [];
    $proxiesCount = count($proxyList);

    for ($i = 0; $i < $requests; $i++) {
        $ch = curl_init();
        $ip = generateRandomIP();
        $ua = $userAgents[array_rand($userAgents)];

        // If no proxy is uploaded, don't use a proxy
        $proxy = $proxiesCount > 0 ? $proxyList[$i % $proxiesCount] : null;

        $options = [
            CURLOPT_URL => $targetUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => ["X-Forwarded-For: $ip"]
        ];

        if ($proxy) {
            $options[CURLOPT_PROXY] = $proxy;
        }

        if ($usePost) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query(getPostData());
        }

        curl_setopt_array($ch, $options);
        $handles[$i] = $ch;
        curl_multi_add_handle($mh, $ch);

        if (($i + 1) % $parallelRequests === 0 || $i + 1 === $requests) {
            do {
                $status = curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            foreach ($handles as $key => $handle) {
                $response = curl_multi_getcontent($handle);
                $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $time = curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
                $err = curl_error($handle);

                // Store response data
                $responseTimes[] = round($time, 2);
                $statusCodes[] = $httpCode;

                $statusText = ($httpCode >= 200 && $httpCode < 400) ? "✅ Success" : "❌ Failed";
                
                if ($statusText === "✅ Success") {
                    $successCount++;
                } else {
                    $failCount++;
                }

                file_put_contents($logFile, "[$key] $statusText | Code: $httpCode | Time: " . round($time) . "ms\n", FILE_APPEND);

                if ($statusText === "❌ Failed") {
                    file_put_contents($errFile, "[$key] Proxy: $proxy | Error: $err\n", FILE_APPEND);
                }

                curl_multi_remove_handle($mh, $handle);
                curl_close($handle);
            }

            $handles = []; // reset handles
        }
    }

    curl_multi_close($mh);

    // Calculate actual statistics
    $totalRequests = count($responseTimes);
    $avgResponseTime = $totalRequests > 0 ? round(array_sum($responseTimes) / $totalRequests, 2) : 0;
    $successRate = $totalRequests > 0 ? round(($successCount / $totalRequests) * 100, 1) : 0;

    // Prepare chart data (group by time intervals)
    $chartLabels = [];
    $chartData = [];
    $interval = max(1, floor($totalRequests / 8)); // Divide into 8 intervals
    
    for ($i = 0; $i < $totalRequests; $i += $interval) {
        $chunk = array_slice($responseTimes, $i, $interval);
        if (count($chunk) > 0) {
            $chartLabels[] = ($i + $interval) . "req";
            $chartData[] = round(array_sum($chunk) / count($chunk), 2);
        }
    }

    // === SEND EMAIL SUMMARY IF EMAIL IS PROVIDED ===
    if (!empty($email)) {
        $subject = "Traffic Test Summary";
        $message = "Test completed. See the log file for details.";
        $headers = "From: no-reply@example.com";
        mail($email, $subject, $message, $headers);
    }

    $testCompleted = true;
} else {
    // Default values when no test has run
    $totalRequests = 0;
    $avgResponseTime = 0;
    $successRate = 0;
    $chartLabels = json_encode(['0s', '2s', '4s', '6s', '8s', '10s', '12s', '14s']);
    $chartData = json_encode([120, 145, 135, 150, 125, 140, 130, 138]);
    $testCompleted = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traffic Analysis Tool</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 10px;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 12px;
            padding: 5px 0;
        }

        .header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 3px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .header p {
            font-size: 0.85rem;
            opacity: 0.9;
            font-weight: 300;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .main-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 10px 20px;
            color: white;
        }

        .card-header h2 {
            font-size: 1rem;
            font-weight: 600;
        }

        .card-body {
            padding: 15px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
            margin-bottom: 10px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 500;
            margin-bottom: 3px;
            color: #4a5568;
            font-size: 0.85rem;
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="email"],
        .form-group input[type="file"] {
            padding: 6px 10px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="number"]:focus,
        .form-group input[type="email"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group input[type="file"] {
            padding: 10px;
            cursor: pointer;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            background: #f7fafc;
            border-radius: 6px;
            margin-bottom: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }

        .checkbox-group label {
            font-weight: 500;
            color: #4a5568;
            cursor: pointer;
            font-size: 0.85rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            width: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .progress-section {
            margin-top: 12px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
            font-weight: 500;
            color: #4a5568;
            font-size: 0.85rem;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 10px;
        }

        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }

        .chart-header {
            margin-bottom: 15px;
        }

        .chart-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: #2d3748;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 12px;
            border-radius: 10px;
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .stat-card h4 {
            font-size: 0.75rem;
            font-weight: 500;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .stat-card .value {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .footer {
            text-align: center;
            color: white;
            padding: 12px;
            margin-top: 15px;
            font-size: 0.8rem;
        }

        .footer a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: opacity 0.3s ease;
        }

        .footer a:hover {
            opacity: 0.8;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            animation: fadeIn 0.3s ease;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal-popup {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
            animation: slideUp 0.4s ease;
            text-align: center;
        }

        .modal-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            animation: scaleIn 0.5s ease 0.2s both;
        }

        .modal-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .modal-message {
            font-size: 1rem;
            color: #718096;
            margin-bottom: 25px;
        }

        .modal-stats {
            background: #f7fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .modal-stat-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .modal-stat-row:last-child {
            border-bottom: none;
        }

        .modal-stat-label {
            font-weight: 500;
            color: #4a5568;
        }

        .modal-stat-value {
            font-weight: 700;
            color: #667eea;
        }

        .modal-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 40px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .modal-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 1.3rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .card-body {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Traffic Analysis Tool</h1>
            <p>Professional website traffic testing and monitoring</p>
        </div>

        <div class="content-grid">
            <div class="main-card">
            <div class="card-header">
                <h2>Configure Test Parameters</h2>
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="url">Target URL</label>
                            <input type="text" id="url" name="url" placeholder="https://example.com" required>
                        </div>
                        <div class="form-group">
                            <label for="requests">Number of Requests</label>
                            <input type="number" id="requests" name="requests" value="20" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="threads">Parallel Threads</label>
                            <input type="number" id="threads" name="threads" value="5" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email for Summary (Optional)</label>
                            <input type="email" id="email" name="email" placeholder="your@email.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="proxyFile">Upload Proxy List (Optional)</label>
                        <input type="file" name="proxyFile" id="proxyFile" accept=".txt">
                    </div>

                    <div class="checkbox-group">
                        <input type="checkbox" id="usePost" name="usePost">
                        <label for="usePost">Use POST Request Method</label>
                    </div>

                    <button type="submit" class="btn-primary">Start Analysis</button>
                </form>

                <div class="progress-section">
                    <div class="progress-label">
                        <span>Progress</span>
                        <span id="progressText">0%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" id="progress"></div>
                    </div>
                </div>
            </div>
            </div>

            <div class="chart-container">
                <div class="card-header">
                    <h2>Response Time Analytics</h2>
                </div>
                <div class="card-body">
                    <canvas id="responseChart"></canvas>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h4>Total Requests</h4>
                <div class="value" id="totalRequests">0</div>
            </div>
            <div class="stat-card">
                <h4>Success Rate</h4>
                <div class="value" id="successRate">0%</div>
            </div>
            <div class="stat-card">
                <h4>Avg Response</h4>
                <div class="value" id="avgResponse">0ms</div>
            </div>
        </div>

        <div class="footer">
            <p>Developed by <a href="https://github.com/Kaushalsah606" target="_blank">@Kaushalsah606</a></p>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal-overlay" id="successModal">
        <div class="modal-popup">
            <div class="modal-icon">✓</div>
            <h2 class="modal-title">Test Completed Successfully!</h2>
            <p class="modal-message">Your traffic analysis has been completed. Here are the results:</p>
            <div class="modal-stats">
                <div class="modal-stat-row">
                    <span class="modal-stat-label">Total Requests</span>
                    <span class="modal-stat-value" id="modalTotalRequests">0</span>
                </div>
                <div class="modal-stat-row">
                    <span class="modal-stat-label">Success Rate</span>
                    <span class="modal-stat-value" id="modalSuccessRate">0%</span>
                </div>
                <div class="modal-stat-row">
                    <span class="modal-stat-label">Average Response</span>
                    <span class="modal-stat-value" id="modalAvgResponse">0ms</span>
                </div>
            </div>
            <button class="modal-btn" onclick="closeModal()">Close</button>
        </div>
    </div>

    <script>
        // Handle progress bar
        var progressBar = document.getElementById('progress');
        var progressText = document.getElementById('progressText');
        var totalRequests = <?php echo isset($totalRequests) ? $totalRequests : 0; ?>;

        function updateProgress(currentRequest) {
            var percentage = (currentRequest / totalRequests) * 100;
            progressBar.style.width = percentage + '%';
            progressText.textContent = Math.round(percentage) + '%';
        }

        // Update stats with real data
        function updateStats() {
            document.getElementById('totalRequests').textContent = <?php echo isset($totalRequests) ? $totalRequests : 0; ?>;
            document.getElementById('successRate').textContent = '<?php echo isset($successRate) ? $successRate : 0; ?>%';
            document.getElementById('avgResponse').textContent = '<?php echo isset($avgResponseTime) ? $avgResponseTime : 0; ?>ms';
        }

        // Modal functions
        function showModal() {
            document.getElementById('successModal').classList.add('show');
            // Update modal stats
            document.getElementById('modalTotalRequests').textContent = <?php echo isset($totalRequests) ? $totalRequests : 0; ?>;
            document.getElementById('modalSuccessRate').textContent = '<?php echo isset($successRate) ? $successRate : 0; ?>%';
            document.getElementById('modalAvgResponse').textContent = '<?php echo isset($avgResponseTime) ? $avgResponseTime : 0; ?>ms';
        }

        function closeModal() {
            document.getElementById('successModal').classList.remove('show');
        }

        // Close modal when clicking outside
        document.getElementById('successModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Initialize on page load
        updateStats();

        <?php if ($testCompleted): ?>
        // Update progress to 100% after test completion
        progressBar.style.width = '100%';
        progressText.textContent = '100%';
        
        // Show success modal
        setTimeout(function() {
            showModal();
        }, 500);
        <?php endif; ?>

        // Chart.js configuration with real data
        var ctx = document.getElementById('responseChart').getContext('2d');
        var gradient = ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(102, 126, 234, 0.4)');
        gradient.addColorStop(1, 'rgba(102, 126, 234, 0.0)');

        var chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo isset($chartLabels) && is_array($chartLabels) ? json_encode($chartLabels) : $chartLabels; ?>,
                datasets: [{
                    label: 'Response Time (ms)',
                    data: <?php echo isset($chartData) && is_array($chartData) ? json_encode($chartData) : $chartData; ?>,
                    borderColor: '#667eea',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#667eea',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: {
                                size: 13,
                                family: "'Inter', sans-serif"
                            },
                            color: '#4a5568',
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        cornerRadius: 8,
                        titleFont: {
                            size: 14,
                            family: "'Inter', sans-serif"
                        },
                        bodyFont: {
                            size: 13,
                            family: "'Inter', sans-serif"
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            font: {
                                size: 12,
                                family: "'Inter', sans-serif"
                            },
                            color: '#718096'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 12,
                                family: "'Inter', sans-serif"
                            },
                            color: '#718096'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
