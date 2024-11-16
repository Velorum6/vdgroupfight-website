<?php
// Load environment variables from .env file
$env = parse_ini_file('.env');

ob_start();  // Start output buffering
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Don't display errors directly

function query_db($query) {
    global $env;
    $server_url = "http://{$env['DB_SERVER_IP']}:{$env['DB_SERVER_PORT']}/db_server.php";
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

if (isset($_GET['check_update'])) {
    $check_query = "SELECT last_updated FROM player_stats_projection ORDER BY last_updated DESC LIMIT 1";
    $result = query_db($check_query);
    echo json_encode(['last_updated' => $result[0]['last_updated']]);
    exit;
}

$query = "SELECT 
    player_id as Player_ID,
    name as Name,
    mmr as MMR,
    avg_damage as 'Avg DMG',
    avg_kills as 'Avg Kills',
    avg_deaths as 'Avg Deaths',
    avg_assists as 'Avg Assists',
    win_rate as Win_Rate,
    matches_played as Matches_Played,
    last_updated as Last_Updated
FROM player_stats_projection";

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
ORDER BY r.Round_ID DESC
LIMIT 10";

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
    
    // Prevent caching
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Content-Type: application/json');
    echo $json_response;
    exit;
}
