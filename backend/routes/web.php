<?php

use Illuminate\Support\Facades\Route;

// Laravel Welcome Page
Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-mail', function () {
    try {
        \Illuminate\Support\Facades\Mail::raw('Test email content', function ($message) {
            $message->to('gameacc9204@gmail.com')
                ->subject('Test Email from Laravel');
        });
        
        return 'Email sent successfully! Check your inbox.';
    } catch (\Exception $e) {
        return 'Error: ' . $e->getMessage() . '<br><br>Trace: ' . $e->getTraceAsString();
    }
});

// Diagnostic route - accessible via Laravel routing
Route::get('/diagnose', function () {
    // #region agent log
    $logPath = base_path('.cursor/debug.log');
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'location' => 'routes/web.php:diagnose-route',
        'message' => 'Diagnostic route accessed via Laravel',
        'data' => [
            'request_uri' => request()->getRequestUri(),
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
            'base_path' => base_path(),
            'public_path' => public_path(),
        ],
        'sessionId' => 'debug-session',
        'runId' => 'laravel-route',
        'hypothesisId' => 'A'
    ];
    @file_put_contents($logPath, json_encode($logData) . "\n", FILE_APPEND);
    // #endregion
    
    $issues = [];
    $warnings = [];
    
    // Test if we're in public directory
    $publicPath = public_path();
    $isInPublic = strpos($publicPath, 'public') !== false;
    
    // Test vendor
    $vendorPath = base_path('vendor/autoload.php');
    $vendorExists = file_exists($vendorPath);
    
    // Test bootstrap
    $bootstrapPath = base_path('bootstrap/app.php');
    $bootstrapExists = file_exists($bootstrapPath);
    
    // Test .htaccess
    $htaccessPath = public_path('.htaccess');
    $htaccessExists = file_exists($htaccessPath);
    
    // Test index.php
    $indexPath = public_path('index.php');
    $indexExists = file_exists($indexPath);
    
    // Test directory structure
    $hasApp = is_dir(base_path('app'));
    $hasConfig = is_dir(base_path('config'));
    
    $html = '<!DOCTYPE html><html><head><title>Laravel Diagnostic</title>';
    $html .= '<style>body{font-family:monospace;padding:20px;background:#f5f5f5}.section{background:white;padding:15px;margin:10px 0;border-left:4px solid #007bff}.success{border-left-color:#28a745}.error{border-left-color:#dc3545}.warning{border-left-color:#ffc107}h2{margin-top:0}pre{background:#f8f9fa;padding:10px;overflow-x:auto}</style></head><body>';
    $html .= '<h1>🔍 Laravel Diagnostic (via Route)</h1>';
    
    $html .= '<div class="section ' . ($isInPublic ? 'success' : 'warning') . '">';
    $html .= '<h2>Test 1: Public Path</h2>';
    $html .= '<p><strong>Public Path:</strong> ' . htmlspecialchars($publicPath) . '</p>';
    $html .= '<p>' . ($isInPublic ? '✅' : '⚠️') . ' Public path detected</p>';
    $html .= '</div>';
    
    $html .= '<div class="section ' . ($vendorExists ? 'success' : 'error') . '">';
    $html .= '<h2>Test 2: Vendor Autoload</h2>';
    $html .= '<p>' . ($vendorExists ? '✅' : '❌') . ' vendor/autoload.php: ' . ($vendorExists ? 'Found' : 'Missing') . '</p>';
    if (!$vendorExists) $issues[] = 'vendor/autoload.php missing';
    $html .= '</div>';
    
    $html .= '<div class="section ' . ($bootstrapExists ? 'success' : 'error') . '">';
    $html .= '<h2>Test 3: Bootstrap</h2>';
    $html .= '<p>' . ($bootstrapExists ? '✅' : '❌') . ' bootstrap/app.php: ' . ($bootstrapExists ? 'Found' : 'Missing') . '</p>';
    if (!$bootstrapExists) $issues[] = 'bootstrap/app.php missing';
    $html .= '</div>';
    
    $html .= '<div class="section ' . ($indexExists ? 'success' : 'error') . '">';
    $html .= '<h2>Test 4: index.php</h2>';
    $html .= '<p>' . ($indexExists ? '✅' : '❌') . ' public/index.php: ' . ($indexExists ? 'Found' : 'Missing') . '</p>';
    if (!$indexExists) $issues[] = 'index.php missing';
    $html .= '</div>';
    
    $html .= '<div class="section ' . ($htaccessExists ? 'success' : 'warning') . '">';
    $html .= '<h2>Test 5: .htaccess</h2>';
    $html .= '<p>' . ($htaccessExists ? '✅' : '⚠️') . ' public/.htaccess: ' . ($htaccessExists ? 'Found' : 'Missing') . '</p>';
    if (!$htaccessExists) $warnings[] = '.htaccess missing';
    $html .= '</div>';
    
    $html .= '<div class="section ' . ($hasApp && $hasConfig ? 'success' : 'error') . '">';
    $html .= '<h2>Test 6: Directory Structure</h2>';
    $html .= '<p>app/: ' . ($hasApp ? '✅' : '❌') . '</p>';
    $html .= '<p>config/: ' . ($hasConfig ? '✅' : '❌') . '</p>';
    if (!$hasApp || !$hasConfig) $issues[] = 'Directory structure incomplete';
    $html .= '</div>';
    
    $html .= '<div class="section ' . (empty($issues) ? 'success' : 'error') . '">';
    $html .= '<h2>📋 Summary</h2>';
    if (empty($issues)) {
        $html .= '<p>✅ Laravel structure looks good!</p>';
        $html .= '<p><strong>Most likely issue:</strong> Document root not pointing to public folder.</p>';
        $html .= '<p>Check Hostinger hPanel → Domains → Document Root should point to: <code>public_html/public</code></p>';
    } else {
        $html .= '<p>❌ <strong>Issues Found:</strong></p><ul>';
        foreach ($issues as $issue) {
            $html .= '<li>' . htmlspecialchars($issue) . '</li>';
        }
        $html .= '</ul>';
    }
    if (!empty($warnings)) {
        $html .= '<p>⚠️ <strong>Warnings:</strong></p><ul>';
        foreach ($warnings as $warning) {
            $html .= '<li>' . htmlspecialchars($warning) . '</li>';
        }
        $html .= '</ul>';
    }
    $html .= '</div>';
    
    $html .= '<div class="section">';
    $html .= '<h2>🔧 Next Steps</h2>';
    $html .= '<ol>';
    $html .= '<li>Try accessing root: <a href="/">https://gymbooking.muccs.site/</a></li>';
    $html .= '<li>Check Hostinger hPanel → Domains → Document Root</li>';
    $html .= '<li>Document root should be: <code>public_html/public</code> (or wherever your public folder is)</li>';
    $html .= '<li>If you can\'t change document root, move public/* contents to root</li>';
    $html .= '</ol>';
    $html .= '</div>';
    
    $html .= '</body></html>';
    
    return $html;
});
