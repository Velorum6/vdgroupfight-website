<?php
ob_start();  // Start output buffering
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Don't display errors directly

// Cache configuration
$cache_file = 'cache/leaderboard_cache.json';
$cache_time = 60; // Cache lifetime in seconds

// Create cache directory if it doesn't exist
if (!file_exists('cache')) {
    mkdir('cache', 0777, true);
}

// Check if cache exists and is still valid
if (isset($_GET['get_leaderboard']) && file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_time)) {
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=' . $cache_time);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cache_file)) . ' GMT');
    echo file_get_contents($cache_file);
    exit;
}

function query_db($query) {
    // Load environment variables from .env file
    $env = parse_ini_file('.env');
    $public_ip = $env['DB_SERVER_IP'];
    $server_port = $env['DB_SERVER_PORT'];
    $server_url = "http://{$public_ip}:{$server_port}/db_server.php";
    $url = $server_url . '?query=' . urlencode($query);
    
    $response = file_get_contents($url);
    
    if ($response === false) {
        return ['error' => 'Failed to connect to database server'];
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid response from database server'];
    }
    
    return $data;
}

function formatDuration($seconds) {
    $minutes = floor($seconds / 60);
    $remainingSeconds = $seconds % 60;
    return sprintf("%d:%02d", $minutes, $remainingSeconds);
}

$query = "WITH LastGame AS (
    SELECT Player_ID, MAX(Round_ID) as Last_Round
    FROM Round_Player
    GROUP BY Player_ID
)
SELECT 
    rp.Player_ID,
    rp.Name,
    COALESCE(MAX(CASE WHEN rp.Round_ID = lg.Last_Round THEN rp.MMR END), 1000) as MMR,
    ROUND(AVG(rp.Total_Damage), 2) as 'Avg DMG',
    ROUND(AVG(COALESCE(k.Kills, 0)), 2) as 'Avg Kills',
    ROUND(AVG(COALESCE(d.Deaths, 0)), 2) as 'Avg Deaths',
    ROUND(AVG(COALESCE(a.Assists, 0)), 2) as 'Avg Assists',
    ROUND(SUM(CASE WHEN r.WinnerTeam = rp.Team THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as Win_Rate
FROM Round_Player rp
JOIN Rounds r ON rp.Round_ID = r.Round_ID
LEFT JOIN (
    SELECT Killer_ID as Player_ID, Round_ID, COUNT(*) as Kills
    FROM Kills
    GROUP BY Killer_ID, Round_ID
) k ON rp.Player_ID = k.Player_ID AND rp.Round_ID = k.Round_ID
LEFT JOIN (
    SELECT Killed_ID as Player_ID, Round_ID, COUNT(*) as Deaths
    FROM Kills
    GROUP BY Killed_ID, Round_ID
) d ON rp.Player_ID = d.Player_ID AND rp.Round_ID = d.Round_ID
LEFT JOIN (
    SELECT Assist_ID as Player_ID, Round_ID, COUNT(*) as Assists
    FROM Kills
    WHERE Assist_ID IS NOT NULL
    GROUP BY Assist_ID, Round_ID
) a ON rp.Player_ID = a.Player_ID AND rp.Round_ID = a.Round_ID
JOIN LastGame lg ON rp.Player_ID = lg.Player_ID
GROUP BY rp.Player_ID
ORDER BY MMR DESC";

$leaderboard_data = query_db($query);

// Add this new query for Round History
$round_history_query = "
WITH PlayerStats AS (
    SELECT 
        rp.Round_ID,
        rp.Team,
        rp.Name,
        rp.Total_Damage,
        COUNT(*) OVER (PARTITION BY rp.Round_ID, rp.Team) as TeamPlayerCount,
        ROW_NUMBER() OVER (PARTITION BY rp.Round_ID, rp.Team ORDER BY rp.Total_Damage DESC) as DamageRank
    FROM Round_Player rp
)
SELECT 
    r.Round_ID,
    MAX(CASE WHEN ps.Team = 'Attacker' AND ps.DamageRank = 1 THEN ps.Name END) as Team1_TopPlayer,
    MAX(CASE WHEN ps.Team = 'Attacker' AND ps.DamageRank = 1 THEN ps.Total_Damage END) as Team1_TopDamage,
    MAX(CASE WHEN ps.Team = 'Attacker' THEN ps.TeamPlayerCount ELSE 0 END) as Team1_PlayerCount,
    MAX(CASE WHEN ps.Team = 'Defender' AND ps.DamageRank = 1 THEN ps.Name END) as Team2_TopPlayer,
    MAX(CASE WHEN ps.Team = 'Defender' AND ps.DamageRank = 1 THEN ps.Total_Damage END) as Team2_TopDamage,
    MAX(CASE WHEN ps.Team = 'Defender' THEN ps.TeamPlayerCount ELSE 0 END) as Team2_PlayerCount,
    r.Time as Duration,
    COUNT(DISTINCT ps.Name) as TotalPlayers,
    r.Date as Date,
    GROUP_CONCAT(DISTINCT ps.Name || '(' || ps.Team || '): ' || ps.Total_Damage) as DebugInfo
FROM Rounds r
JOIN PlayerStats ps ON r.Round_ID = ps.Round_ID
GROUP BY r.Round_ID
ORDER BY r.Round_ID DESC";

$round_history_data = query_db($round_history_query);

// Add this line for debugging
error_log(print_r($round_history_data, true));

// Clear the output buffer and turn off output buffering
ob_end_clean();

// Only return the data if it's explicitly requested
if (isset($_GET['get_leaderboard'])) {
    $json_response = json_encode([
        'leaderboard' => $leaderboard_data,
        'round_history' => $round_history_data
    ]);
    
    // Save to cache file
    file_put_contents($cache_file, $json_response);
    
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=' . $cache_time);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($cache_file)) . ' GMT');
    echo $json_response;
    exit;
}
