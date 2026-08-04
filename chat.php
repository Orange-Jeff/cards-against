<?php
/**
 * Filename: chat.php
 * Date: December 11, 2025
 * Version: 1.0 - Placeholder for future chat and voice setup configuration
 */
session_start();

$configFile = __DIR__ . '/data/global_config.json';
$globalConfig = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
$themesFile = __DIR__ . '/data/themes.json';
$themes = file_exists($themesFile) ? (json_decode(file_get_contents($themesFile), true) ?: []) : [];
$currentThemeKey = $globalConfig['default_theme'] ?? 'default';
$currentTheme = $themes[$currentThemeKey] ?? ($themes['default'] ?? []);
$themeKeyword = trim((string)($currentTheme['game_name_suffix'] ?? 'Everyone'));
if ($themeKeyword === '') {
    $themeKeyword = 'Everyone';
}
$themeLabel = trim((string)($currentTheme['label'] ?? ucfirst($currentThemeKey)));
if ($themeLabel === '') {
    $themeLabel = ucfirst($currentThemeKey);
}
$appVersion = '4.9';
$displayUserName = trim((string)($_SESSION['user_name'] ?? 'Guest'));
if ($displayUserName === '') {
    $displayUserName = 'Guest';
}
$nameParts = preg_split('/\s+/', $displayUserName) ?: [];
$userInitials = '';
foreach ($nameParts as $part) {
    if ($part !== '') {
        $userInitials .= strtoupper(substr($part, 0, 1));
        if (strlen($userInitials) >= 2) break;
    }
}
if ($userInitials === '') {
    $userInitials = strtoupper(substr($displayUserName, 0, 2));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat & Voice Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    <style>
        body { background-color: #1a1b1e; color: white; font-family: sans-serif; }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-gradient-to-b from-[#1a1b1e] to-[#141517]">

    <!-- BRANDING HEADER -->
    <div class="bg-[#141517] border-b border-gray-800 py-2">
        <div class="max-w-4xl mx-auto px-4 text-center">
            <h1 class="text-lg sm:text-2xl font-black uppercase tracking-[0.22em]">
                <span class="text-orange-500">CARDS AGAINST</span>
                <span class="text-gray-200"><?php echo htmlspecialchars($themeKeyword); ?></span>
                <span class="ml-2 align-middle text-[10px] font-bold tracking-wide text-gray-400">v<?php echo htmlspecialchars($appVersion); ?></span>
            </h1>
            <div class="text-[10px] text-gray-400 font-bold uppercase tracking-[0.18em] mt-1">Theme: <?php echo htmlspecialchars($themeLabel); ?></div>
        </div>
    </div>

    <!-- NAV -->
    <nav class="bg-[#25262b] shadow-lg p-3 flex justify-between items-center sticky top-0 z-50 border-b border-gray-800">
        <div class="flex items-center gap-4">
            <i class="fas fa-tshirt text-orange-500 text-2xl drop-shadow-md"></i>
            <div class="flex gap-2">
                <a href="index.php" class="px-3 py-2 text-sm font-bold text-gray-400 uppercase hover:text-white transition-colors">Lobby</a>
                <a href="setup.php" class="px-3 py-2 text-sm font-bold text-gray-400 uppercase hover:text-white transition-colors">New Game</a>
                <a href="settings.php" class="px-3 py-2 text-sm font-bold text-gray-400 uppercase hover:text-white transition-colors">Settings</a>
                <a href="chat.php" class="px-3 py-2 text-sm font-bold text-orange-500 border-b-2 border-orange-500 uppercase tracking-wide">Chat Setup</a>
            </div>
        </div>
        <a href="index.php?edit_name=1" class="flex items-center gap-2 bg-gray-800/90 border border-gray-700 rounded-lg px-2 py-1.5 hover:border-orange-500/70 transition-colors" title="Change name">
            <span class="w-6 h-6 rounded-full bg-orange-500 text-white text-[10px] font-black flex items-center justify-center tracking-wide"><?php echo htmlspecialchars($userInitials); ?></span>
            <span class="max-w-[96px] truncate text-[11px] sm:text-xs font-bold text-gray-200"><?php echo htmlspecialchars($displayUserName); ?></span>
        </a>
    </nav>

    <!-- MAIN CONTENT -->
    <main class="flex-1 p-6 max-w-4xl mx-auto w-full">
        <div class="flex items-center justify-between border-b border-gray-700 pb-4 mb-8">
            <h1 class="text-3xl font-bold uppercase tracking-widest text-gray-300">
                <i class="fas fa-comments text-orange-500 mr-3"></i>Chat & Voice Setup
            </h1>
        </div>

        <div class="bg-[#25262b] p-8 rounded-xl shadow-xl border border-gray-800 text-center">
            <i class="fas fa-construction text-6xl text-orange-500 mb-4"></i>
            <h2 class="text-2xl font-bold text-white mb-3">Coming Soon</h2>
            <p class="text-gray-400 mb-6">Chat and voice configuration tools will be available in a future update.</p>

            <div class="bg-gray-800/50 p-6 rounded-lg border border-gray-700 text-left">
                <h3 class="text-lg font-bold text-orange-400 mb-3"><i class="fas fa-list-check mr-2"></i>Planned Features</h3>
                <ul class="space-y-2 text-sm text-gray-300">
                    <li><i class="fas fa-microphone text-orange-500 mr-2"></i> Voice chat configuration</li>
                    <li><i class="fas fa-video text-orange-500 mr-2"></i> Video settings for host</li>
                    <li><i class="fas fa-sliders-h text-orange-500 mr-2"></i> Audio/video quality controls</li>
                    <li><i class="fas fa-users text-orange-500 mr-2"></i> Per-player voice permissions</li>
                    <li><i class="fas fa-message text-orange-500 mr-2"></i> Text chat interface</li>
                </ul>
            </div>

            <div class="mt-6">
                <a href="index.php" class="inline-block bg-orange-600 hover:bg-orange-500 text-white font-bold px-6 py-3 rounded-lg uppercase tracking-wider transition-all">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Lobby
                </a>
            </div>
        </div>
    </main>

    <footer class="p-4 text-center text-xs text-gray-600 border-t border-gray-800">
        <p>Cards Against Everyone &copy; 2025</p>
    </footer>

</body>
</html>
