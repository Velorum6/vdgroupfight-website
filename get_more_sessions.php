<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'get_player_stats.php';

// Add this function before the try block
function groupMatchesIntoSessions($matches, $session_gap_minutes = 30) {
    $sessions = [];
    $currentSession = [];
    
    foreach ($matches as $match) {
        if (empty($currentSession)) {
            $currentSession[] = $match;
            continue;
        }
        
        $lastMatchTime = strtotime($currentSession[count($currentSession) - 1]['date']);
        $currentMatchTime = strtotime($match['date']);
        $timeDifference = abs($lastMatchTime - $currentMatchTime) / 60; // Convert to minutes
        
        if ($timeDifference <= $session_gap_minutes) {
            $currentSession[] = $match;
        } else {
            // Create new session with previous matches
            $sessions[] = [
                'session_start' => $currentSession[0]['date'],
                'matches_count' => count($currentSession),
                'mmr_start' => end($currentSession)['mmr'],
                'mmr_end' => $currentSession[0]['mmr'],
                'mmr_change' => $currentSession[0]['mmr'] - end($currentSession)['mmr'],
                'matches' => $currentSession
            ];
            
            // Start new session with current match
            $currentSession = [$match];
        }
    }
    
    // Don't forget to add the last session if there are matches in it
    if (!empty($currentSession)) {
        $sessions[] = [
            'session_start' => $currentSession[0]['date'],
            'matches_count' => count($currentSession),
            'mmr_start' => end($currentSession)['mmr'],
            'mmr_end' => $currentSession[0]['mmr'],
            'mmr_change' => $currentSession[0]['mmr'] - end($currentSession)['mmr'],
            'matches' => $currentSession
        ];
    }
    
    return $sessions;
}

// Also add the getRelativeTime function if it's not already included
function getRelativeTime($date) {
    $timestamp = strtotime($date);
    $difference = time() - $timestamp;
    
    $periods = [
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    ];
    
    foreach ($periods as $seconds => $label) {
        $interval = floor($difference / $seconds);
        if ($interval >= 1) {
            return $interval . ' ' . $label . ($interval > 1 ? 's' : '') . ' ago';
        }
    }
    
    return 'just now';
}

try {
    $player_id = $_GET['player_id'] ?? '';
    $offset = intval($_GET['offset'] ?? 0);
    $last_match_date = $_GET['last_match_date'] ?? '';
    $original_mmr_end = intval($_GET['original_mmr_end'] ?? 0);

    if (empty($player_id)) {
        throw new Exception('Player ID is required');
    }

    // Query to get match history
    $match_history_query = "WITH PlayerKillsAssists AS (
        SELECT 
            Round_ID, 
            Killer_ID, 
            Assist_ID,
            COUNT(CASE WHEN Killer_ID = '$player_id' THEN 1 END) AS Total_Kills,
            COUNT(CASE WHEN Assist_ID = '$player_id' THEN 1 END) AS Total_Assists
        FROM 
            Kills
        WHERE 
            Killer_ID = '$player_id' 
            OR Assist_ID = '$player_id'
        GROUP BY 
            Round_ID
    )
    SELECT 
        rp.Round_ID AS round_id,
        r.WinnerTeam AS winner_team,
        rp.Team AS player_team,
        r.Time AS round_duration,
        CASE 
            WHEN k.Survival_Time IS NULL AND r.WinnerTeam != rp.Team THEN 'Abandoned'
            WHEN k.Survival_Time IS NULL THEN 'Alive'
            ELSE k.Survival_Time
        END AS survival_time,
        rp.Total_Damage AS total_damage,
        COALESCE(pk.Total_Kills, 0) AS kills,
        COALESCE(pk.Total_Assists, 0) AS assists,
        rp.HP AS hp,
        rp.MMR AS mmr,
        r.Date AS date
    FROM 
        Round_Player rp
    JOIN 
        Rounds r ON rp.Round_ID = r.Round_ID
    LEFT JOIN 
        Kills k ON rp.Round_ID = k.Round_ID AND rp.Player_ID = k.Killed_ID
    LEFT JOIN 
        PlayerKillsAssists pk ON rp.Round_ID = pk.Round_ID
    WHERE 
        rp.Player_ID = '$player_id'
    ORDER BY 
        rp.Round_ID DESC
    LIMIT 100 OFFSET $offset";

    $match_history = query_db($match_history_query);

    if ($match_history === false) {
        throw new Exception('Database query failed');
    }

    // Process matches and create sessions
    $matches_for_sessions = array_map(function($match) {
        return [
            'round_id' => $match['round_id'] ?? 0,
            'winner_team' => $match['winner_team'] ?? '',
            'player_team' => $match['player_team'] ?? '',
            'total_damage' => $match['total_damage'] ?? 0,
            'kills' => $match['kills'] ?? 0,
            'assists' => $match['assists'] ?? 0,
            'hp' => $match['hp'] ?? 0,
            'mmr' => $match['mmr'] ?? 0,
            'date' => $match['date'] ?? '',
            'survival_time' => $match['survival_time'] ?? 0,
            'round_duration' => $match['round_duration'] ?? 0
        ];
    }, $match_history);

    $matches_for_sessions = array_filter($matches_for_sessions);
    
    if (empty($matches_for_sessions)) {
        echo json_encode(['sessions' => []]);
        exit;
    }

    // Check if the first matches should be part of the last session
    if (!empty($last_match_date)) {
        $last_session_time = strtotime($last_match_date);
        $matches_to_merge = [];
        $remaining_matches = [];
        
        // Separate matches that belong to the last session
        foreach ($matches_for_sessions as $match) {
            $match_time = strtotime($match['date']);
            $time_difference = abs($last_session_time - $match_time) / 60;
            
            if ($time_difference <= 30) {
                $matches_to_merge[] = $match;
                $last_session_time = $match_time; // Update last session time for next comparison
            } else {
                $remaining_matches[] = $match;
            }
        }
        
        if (!empty($matches_to_merge)) {
            // Use the original MMR end value passed from the frontend
            // Calculate new session stats for merged matches
            $session_stats = [
                'mmr_start' => $matches_to_merge[count($matches_to_merge) - 1]['mmr'], // Earliest match MMR
                'mmr_end' => $original_mmr_end,  // Use the MMR end from the existing session
                'matches_count' => count($matches_to_merge),
                'mmr_change' => $original_mmr_end - $matches_to_merge[count($matches_to_merge) - 1]['mmr'] // Calculate change from start to original end
            ];

            // Create new sessions from remaining matches if any
            $new_sessions = !empty($remaining_matches) ? groupMatchesIntoSessions($remaining_matches) : [];
            
            // Add relative time to new sessions
            foreach ($new_sessions as &$session) {
                $session['relative_time'] = getRelativeTime($session['session_start']);
            }

            echo json_encode([
                'merge_with_last_session' => true,
                'matches' => $matches_to_merge,
                'session_stats' => $session_stats,
                'new_sessions' => $new_sessions,
                'new_offset' => $offset + count($matches_for_sessions),
                'has_more' => count($matches_for_sessions) >= 100,
                'remaining_count' => count($remaining_matches)
            ]);
            exit;
        }
    }

    // If we reach here, create new sessions
    $sessions = groupMatchesIntoSessions($matches_for_sessions);

    // Add relative time to each session
    foreach ($sessions as &$session) {
        $session['relative_time'] = getRelativeTime($session['session_start']);
    }

    header('Content-Type: application/json');
    echo json_encode([
        'merge_with_last_session' => false,
        'sessions' => $sessions,
        'new_offset' => $offset + count($matches_for_sessions),
        'has_more' => count($match_history) == 100,
        'debug_info' => [
            'offset' => $offset,
            'matches_count' => count($match_history),
            'sessions_count' => count($sessions)
        ]
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Error in get_more_sessions.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} 