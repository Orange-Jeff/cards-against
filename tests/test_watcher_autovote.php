<?php
// Test script: watcher and auto-vote behavior
// Usage: php tests/test_watcher_autovote.php

$base = __DIR__ . '/..';
$dataDir = $base . '/data';
$apiUrl = 'http://localhost:8000/against/api.php';

function writeRoom($roomId, $data) {
    global $dataDir;
    $path = $dataDir . '/room_' . $roomId . '.json';
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

function readRoom($roomId) {
    global $dataDir;
    $path = $dataDir . '/room_' . $roomId . '.json';
    if (!file_exists($path)) return null;
    return json_decode(file_get_contents($path), true);
}

function callPoll($roomId, $spectate = false) {
    global $apiUrl;
    $url = $apiUrl . '?action=poll&room_id=' . urlencode($roomId) . ($spectate ? '&spectate=1' : '');
    $opts = [
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
        ]
    ];
    $context = stream_context_create($opts);
    $res = @file_get_contents($url, false, $context);
    if ($res === false) {
        $err = error_get_last();
        return ['error' => $err['message'] ?? 'Network error'];
    }
    return json_decode($res, true);
}

// 1) Create a test room file manually
$roomId = 'test_' . uniqid();
$now = time();

$room = [
    'id' => $roomId,
    'config' => ['timer' => 5, 'allow_watchers' => true, 'room_name' => 'AutoVote Test'],
    'state' => 'voting',
    'players' => [
        ['id' => 'p1', 'name' => 'Alice', 'is_bot' => false, 'afk' => false, 'is_waiting' => false, 'score' => 0],
        ['id' => 'p2', 'name' => 'Bob', 'is_bot' => false, 'afk' => false, 'is_waiting' => false, 'score' => 0],
    ],
    'chat' => [],
    'votes' => [],
    // table_cards: two options (Alice submitted option 0, Bob hasn't voted nor submitted)
    'table_cards' => [
        ['player_id' => 'p1', 'cards' => [['text' => 'Answer by Alice']]],
        ['player_id' => 'p2', 'cards' => [['text' => 'Answer by Bob']]]
    ],
    'current_black_card' => ['text' => 'Test blank ______'],
    'round' => 1,
    'round_start_time' => $now - 30, // force timer expiry
    'created_at' => $now,
    'updated_at' => $now
];

writeRoom($roomId, $room);
echo "Room $roomId created for voting test\n";

// Call poll multiple times to let server process auto-vote (some server timing requires multiple polls)
for ($i=0;$i<5;$i++) {
    echo "Poll attempt " . ($i+1) . "\n";
    $res = callPoll($roomId, false);
    if (isset($res['error'])) {
        echo "Poll error: " . $res['error'] . "\n";
        break;
    }
    $r2 = readRoom($roomId);
    $votes = $r2['votes'] ?? [];
    if (!empty($votes)) {
        echo "Votes recorded: " . json_encode($votes) . "\n";
        echo "Auto Alert: " . json_encode($r2['auto_alert'] ?? null) . "\n";
        break;
    } else {
        echo "No votes recorded yet on attempt " . ($i+1) . "\n";
    }
    sleep(1);
}

// 2) Round-end auto-advance test
$room2Id = 'test_re_' . uniqid();
$room2 = [
    'id' => $room2Id,
    'config' => ['timer' => 5, 'allow_watchers' => true, 'room_name' => 'Round End Test'],
    'state' => 'round_end',
    'players' => [
        ['id' => 'p1', 'name' => 'Host', 'is_bot' => false, 'afk' => false, 'is_waiting' => false],
        ['id' => 'p2', 'name' => 'Spectator', 'is_bot' => false, 'afk' => false, 'is_waiting' => false]
    ],
    'chat' => [],
    'votes' => [],
    'table_cards' => [],
    'current_black_card' => null,
    'round' => 2,
    'round_end_time' => $now - 20, // more than 15s ago
    'created_at' => $now,
    'updated_at' => $now
];
writeRoom($room2Id, $room2);
echo "Room $room2Id created for round-end auto-advance test\n";

// Call poll to trigger auto-continue
$res2 = callPoll($room2Id, false);
if (isset($res2['error'])) {
    echo "Poll error: " . $res2['error'] . "\n";
} else {
    $r3 = readRoom($room2Id);
    echo "Room state after poll: " . ($r3['state'] ?? 'missing') . "\n";
    echo "Auto Alert: " . json_encode($r3['auto_alert'] ?? null) . "\n";
}

// Do not remove test rooms so we can inspect results manually (left for debug)
// @unlink($dataDir . '/room_' . $roomId . '.json');
// @unlink($dataDir . '/room_' . $room2Id . '.json');

echo "Tests complete. Rooms left in data/ for inspection:\n  room_$roomId.json\n  room_$room2Id.json\n";
