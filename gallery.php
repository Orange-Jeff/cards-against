<?php
session_start();

$galleryDir = __DIR__ . '/gallery';
$ratingsFile = __DIR__ . '/data/ratings.json';

// Get ratings
$ratings = file_exists($ratingsFile) ? (json_decode(file_get_contents($ratingsFile), true) ?: []) : [];

// Scan for html files
$games = [];
if (is_dir($galleryDir)) {
    $files = glob($galleryDir . '/game_*.html');
    if ($files) {
        foreach ($files as $f) {
            $base = basename($f);
            preg_match('/game_(.+?)\.html$/', $base, $m);
            $roomId = $m[1] ?? '';
            
            if ($roomId) {
                // Get file creation time as date played
                $time = filemtime($f);
                $dateStr = date('Y-m-d H:i', $time);
                
                // Read file to parse title and winner
                $htmlContent = file_get_contents($f);
                $title = 'Game Report';
                $winner = 'Unknown';
                
                if (preg_match('/<h1>(.+?)<\/h1>/', $htmlContent, $tm)) {
                    $title = $tm[1];
                }
                if (preg_match('/🏆 Game Winner<\/h2>\s*<p>(.+?)\s*🎉<\/p>/s', $htmlContent, $wm)) {
                    $winner = trim($wm[1]);
                }
                
                // Calculate rating
                $gameRatings = $ratings[$roomId] ?? [];
                $avg = 0;
                $count = count($gameRatings);
                if ($count > 0) {
                    $avg = array_sum($gameRatings) / $count;
                }
                
                $games[] = [
                    'room_id' => $roomId,
                    'file' => 'gallery/' . $base,
                    'title' => $title,
                    'winner' => $winner,
                    'date' => $dateStr,
                    'time' => $time,
                    'avg_rating' => round($avg, 1),
                    'rating_count' => $count
                ];
            }
        }
    }
}

// Sort games by date (newest first)
usort($games, function($a, $b) {
    return $b['time'] - $a['time'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Game Gallery - Cards Against</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background-color: #1a1b1e;
            color: #cbd5e1;
        }
    </style>
</head>
<body class="flex flex-col min-h-screen">
    <!-- Header -->
    <header class="bg-[#141517] border-b border-gray-800 py-3 sticky top-0 z-50 shadow-md">
        <div class="max-w-4xl mx-auto px-4 flex justify-between items-center">
            <div class="flex items-center gap-2">
                <i class="fas fa-trophy text-orange-500 text-xl"></i>
                <h1 class="text-sm font-bold text-gray-200 uppercase tracking-wider">Game Gallery</h1>
            </div>
            <div class="flex items-center gap-4">
                <a href="index.php" class="text-gray-400 hover:text-white transition-colors" title="Lobby">
                    <i class="fas fa-home text-lg"></i>
                </a>
                <a href="index.php" class="text-gray-400 hover:text-red-500 transition-colors" title="Close Gallery">
                    <i class="fas fa-times text-xl"></i>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-1 p-4 max-w-4xl mx-auto w-full space-y-6">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-gray-800 pb-4">
            <div>
                <h2 class="text-xl font-black text-white uppercase tracking-wider">Completed Games</h2>
                <p class="text-xs text-gray-400">View turn history, leaderboards, and rate completed game sessions.</p>
            </div>
        </div>

        <?php if (empty($games)): ?>
            <div class="text-center py-20 bg-[#25262b] rounded-xl border border-gray-800 shadow-xl">
                <i class="fas fa-ghost text-4xl text-gray-600 mb-3"></i>
                <p class="text-gray-400">No completed games found in the gallery.</p>
                <p class="text-[10px] text-gray-500 mt-1">Finish a game to automatically save its report here.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($games as $g): ?>
                    <div class="bg-[#25262b] border border-gray-800 rounded-xl p-4 flex flex-col justify-between hover:border-gray-700 transition-all shadow-lg group">
                        <div class="space-y-2">
                            <div class="flex justify-between items-start">
                                <span class="text-[10px] text-gray-400 font-mono"><?php echo $g['date']; ?></span>
                                <div class="flex items-center gap-1 text-xs text-yellow-500">
                                    <i class="fas fa-star"></i>
                                    <span class="font-bold text-gray-200"><?php echo $g['avg_rating'] > 0 ? $g['avg_rating'] : 'N/A'; ?></span>
                                    <span class="text-[9px] text-gray-500">(<?php echo $g['rating_count']; ?>)</span>
                                </div>
                            </div>
                            <h3 class="text-md font-extrabold text-white group-hover:text-orange-400 transition-colors uppercase tracking-wide leading-snug">
                                <?php echo htmlspecialchars($g['title']); ?>
                            </h3>
                            <div class="flex items-center gap-1.5 text-xs text-gray-300">
                                <span class="text-gray-400">Winner:</span>
                                <span class="font-bold text-orange-400"><i class="fas fa-crown mr-1"></i><?php echo htmlspecialchars($g['winner']); ?></span>
                            </div>
                        </div>
                        <div class="mt-4 pt-3 border-t border-gray-800 flex justify-end">
                            <a href="<?php echo $g['file']; ?>" class="bg-gray-800 hover:bg-gray-700 text-white text-xs font-bold py-2 px-4 rounded border border-gray-700 transition-colors">
                                View Report <i class="fas fa-chevron-right ml-1"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="py-6 border-t border-gray-800 bg-[#141517] text-center text-xs text-gray-500 mt-10">
        <p>Cards Against &copy; <?php echo date('Y'); ?>. Standalone Game Engine.</p>
    </footer>
</body>
</html>
