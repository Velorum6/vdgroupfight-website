<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'get_player_stats.php';

$player_id = isset($_GET['id']) ? $_GET['id'] : '';

if (empty($player_id) || !preg_match('/^[0-9.]+$/', $player_id)) {
    die("Invalid player ID");
}

$main_query = "SELECT 
    name as Name,
    player_id as Player_ID,
    current_mmr as Current_MMR,
    highest_mmr as Highest_MMR,
    lowest_mmr as Lowest_MMR,
    total_damage as Total_Damage,
    rounds_played as Rounds_Played,
    wins as Wins,
    total_kills as Total_Kills,
    total_deaths as Total_Deaths,
    total_assists as Total_Assists,
    avg_damage as Avg_Damage,
    avg_kills as Avg_Kills,
    avg_deaths as Avg_Deaths,
    avg_assists as Avg_Assists,
    CAST(win_rate as FLOAT) as Win_Rate
FROM player_detailed_stats_projection
WHERE player_id = '$player_id'";

$start_time = microtime(true);

$query_start = microtime(true);
$player_data = query_db($main_query);
$query_time = microtime(true) - $query_start;
error_log("Main query execution time: " . round($query_time * 1000, 2) . "ms");

if (empty($player_data)) {
    die("No data found for player ID: " . htmlspecialchars($player_id));
}
$player_data = $player_data[0];  // Assuming the first row is what we want

// Update the match history query to use the date directly
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
LIMIT 100;";

$query_start = microtime(true);
$match_history = query_db($match_history_query);
$query_time = microtime(true) - $query_start;
error_log("Match history query execution time: " . round($query_time * 1000, 2) . "ms");

// Prepare MMR history data
$mmr_history = [];
if (!empty($match_history)) {
    // Create MMR history array in chronological order (oldest to newest)
    $reversed_matches = array_reverse($match_history);
    foreach ($reversed_matches as $match) {
        if (isset($match['round_id']) && isset($match['mmr'])) {
            $mmr_history[] = [
                'Round_ID' => intval($match['round_id']),
                'MMR' => intval($match['mmr'])
            ];
        }
    }
}

// Prepare match data for session grouping with error checking
$matches_for_sessions = [];
if (!empty($match_history)) {
    $matches_for_sessions = array_map(function($match) {
        if (!is_array($match)) return null;
        
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
    
    // Remove any null values
    $matches_for_sessions = array_filter($matches_for_sessions);
}

// Group matches into sessions only if we have valid data
$sessions = !empty($matches_for_sessions) ? groupMatchesIntoSessions($matches_for_sessions) : [];

// Update the groupMatchesIntoSessions function to handle the date format
function groupMatchesIntoSessions($matches, $maxTimeDiff = 1800) {
    if (empty($matches)) {
        return [];
    }

    $sessions = [];
    $currentSession = [];
    $lastMatchTime = null;

    foreach ($matches as $match) {
        // Convert the date string to timestamp
        $currentTime = strtotime($match['date']);
        
        if (empty($currentSession) || 
            ($lastMatchTime && ($lastMatchTime - $currentTime) > $maxTimeDiff)) {  // Note: reversed time comparison
            
            if (!empty($currentSession)) {
                $sessions[] = [
                    'session_start' => $currentSession[0]['date'],  // Use date string directly
                    'matches_count' => count($currentSession),
                    'mmr_start' => end($currentSession)['mmr'],
                    'mmr_end' => $currentSession[0]['mmr'],
                    'mmr_change' => $currentSession[0]['mmr'] - end($currentSession)['mmr'],
                    'matches' => $currentSession
                ];
            }
            
            $currentSession = [$match];
        } else {
            $currentSession[] = $match;
        }
        
        $lastMatchTime = $currentTime;
    }
    
    // Add the last session
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

// Update getRelativeTime function to handle the date format
function getRelativeTime($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);  // This will work directly with the date string
    $diff = $now->diff($ago);

    if ($diff->d > 0) {
        return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    } elseif ($diff->h > 0) {
        return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    } elseif ($diff->i > 0) {
        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'just now';
    }
}

// At the end of PHP processing
$total_php_time = microtime(true) - $start_time;
error_log("Total PHP execution time: " . round($total_php_time * 1000, 2) . "ms");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($player_data['Name']); ?> - Player Stats</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicon-16x16.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png" />
    <link rel="shortcut icon" href="assets/img/favicon.ico" />
    <link rel="stylesheet" href="assets/css/bootstrap.min.css" />
    <link rel="stylesheet" href="assets/css/main.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <style>
        .card-transparent {
            background-color: rgba(0, 0, 0, 0.3); /* More transparent background */
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .list-group-item-transparent {
            background-color: rgba(0, 0, 0, 0.2); /* Even more transparent for list items */
            border-color: rgba(255, 255, 255, 0.1);
        }
        .card-header {
            background-color: rgba(0, 0, 0, 0.4); /* Slightly darker header */
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .card-body h4 {
            font-size: 1.1rem;
        }
        .list-group-flush {
            border-radius: 0.25rem;
            overflow: hidden;
        }
        .list-group-item-transparent {
            padding: 0.5rem 1rem;
        }
        .chart-container {
            background-color: rgba(255, 255, 255, 0.05);  /* Very slight white background */
            border-radius: 8px;  /* Rounded corners */
            padding: 10px;
            margin-bottom: 20px;
        }
        .table-responsive {
            background-color: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 10px;
        }
        .table {
            margin-bottom: 0;
        }
        .table th, .table td {
            text-align: center;
            vertical-align: middle;
        }
        .alive-player {
            background-color: rgba(40, 167, 69, 0.2) !important;
        }
        .hp-underline {
            position: relative;
        }
        .hp-underline::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: 0;
            height: 3px;
            width: var(--hp-width, 100%);
            background-color: rgba(40, 167, 69, 0.8);
        }
        .session-header {
            transition: background-color 0.2s;
        }

        .session-header .text-muted {
            font-size: 0.9em;
            margin-right: 15px;  /* Add some spacing */
            opacity: 0.8;        /* Make it slightly more visible */
        }

        .session-header:hover {
            background-color: rgba(33, 37, 41, 0.8) !important;
        }

        .session-matches {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 0.25rem;
            padding: 1rem;
        }
        .session-time {
            color: #9ba6b2;  /* A more visible gray color */
            font-size: 0.9em;
            margin-right: 15px;
        }

        .session-rounds {
            color: #6c757d;
            font-size: 0.9em;
        }

        .session-header:hover .session-time,
        .session-header:hover .session-rounds {
            color: #cbd3da;
        }
    </style>
</head>
<body>

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card card-transparent text-light">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0 d-inline-block">
                                    <?php echo htmlspecialchars($player_data['Name']); ?>
                                </h2>
                                <span> <?php echo number_format($player_data['Current_MMR']); ?></span>
                            </div>
                            <a href="index.html" class="btn btn-cezero">Back to Leaderboard</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Left Column -->
                            <div class="col-md-6">
                                <!-- Damage Stats -->
                                <h4 class="text-center mb-2">Total Stats</h4>
                                <ul class="list-group list-group-flush mb-4">
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Damage:</span>
                                        <span><?php echo number_format($player_data['Total_Damage']); ?></span>
                                    </li>
                                </ul>
                                
                                <!-- KDA Stats -->
                                <ul class="list-group list-group-flush mb-4">
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Kills:</span>
                                        <span><?php echo number_format($player_data['Total_Kills']); ?></span>
                                    </li>
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Deaths:</span>
                                        <span><?php echo number_format($player_data['Total_Deaths']); ?></span>
                                    </li>
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Assists:</span>
                                        <span><?php echo number_format($player_data['Total_Assists']); ?></span>
                                    </li>
                                </ul>
                                
                                <!-- Game Stats -->
                                <ul class="list-group list-group-flush mb-4">
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Rounds Played:</span>
                                        <span><?php echo number_format($player_data['Rounds_Played']); ?></span>
                                    </li>
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Wins:</span>
                                        <span><?php echo number_format($player_data['Wins']); ?></span>
                                    </li>
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Win Rate:</span>
                                        <span><?php echo number_format($player_data['Win_Rate'], 2); ?>%</span>
                                    </li>
                                </ul>
                            </div>
                            
                            <!-- Right Column -->
                            <div class="col-md-6">
                                <!-- Average Damage Stats -->
                                <h4 class="text-center mb-2">Stats per Round</h4>
                                <ul class="list-group list-group-flush mb-4">
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Damage:</span>
                                        <span><?php echo number_format($player_data['Avg_Damage'], 2); ?></span>
                                    </li>
                                </ul>
                                
                                <!-- Average KDA Stats -->
                                <ul class="list-group list-group-flush mb-4">
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Kills:</span>
                                        <span><?php echo number_format($player_data['Avg_Kills'], 2); ?></span>
                                    </li>
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Deaths:</span>
                                        <span><?php echo number_format($player_data['Avg_Deaths'], 2); ?></span>
                                    </li>
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Assists:</span>
                                        <span><?php echo number_format($player_data['Avg_Assists'], 2); ?></span>
                                    </li>
                                </ul>

                                <!-- MMR Stats -->
                                <ul class="list-group list-group-flush mb-4">
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Current MMR:</span>
                                        <span><?php echo number_format($player_data['Current_MMR']); ?></span>
                                    </li>
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Highest MMR:</span>
                                        <span><?php echo number_format($player_data['Highest_MMR']); ?></span>
                                    </li>
                                    <li class="list-group-item list-group-item-transparent text-light d-flex justify-content-between">
                                        <span>Lowest MMR:</span>
                                        <span><?php echo number_format($player_data['Lowest_MMR']); ?></span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <div class="chart-container" style="position: relative; height:400px; width:100%; background-color: rgba(13, 17, 23, 0.7); border-radius: 8px; padding: 20px;">
                                    <canvas id="mmrChart"></canvas>
                                </div>
                                <script>
                                    console.time('Total Page Load');
                                    console.time('MMR Chart Generation');

                                    var mmrCanvas = document.getElementById('mmrChart');
                                    var mmrData = <?php echo json_encode($mmr_history, JSON_NUMERIC_CHECK); ?>;
                                    
                                    if (typeof Chart !== 'undefined') {
                                        try {
                                            console.time('Chart Creation');
                                            var ctx = mmrCanvas.getContext('2d');
                                            var chart = new Chart(ctx, {
                                                type: 'line',
                                                data: {
                                                    labels: mmrData.map(item => 'Round ' + item.Round_ID),
                                                    datasets: [{
                                                        label: 'MMR',
                                                        data: mmrData.map(item => item.MMR),
                                                        borderColor: 'rgb(45, 208, 208)',
                                                        backgroundColor: 'rgba(45, 208, 208, 0.1)',
                                                        borderWidth: 2,
                                                        tension: 0.4,
                                                        fill: true,
                                                        pointRadius: 4,
                                                        pointBackgroundColor: 'rgb(45, 208, 208)',
                                                        pointBorderColor: 'rgb(45, 208, 208)',
                                                        pointHoverRadius: 6,
                                                        pointHoverBackgroundColor: '#fff',
                                                        pointHoverBorderColor: 'rgb(45, 208, 208)'
                                                    }]
                                                },
                                                options: {
                                                    responsive: true,
                                                    maintainAspectRatio: false,
                                                    plugins: {
                                                        title: {
                                                            display: true,
                                                            text: 'MMR History (Last 100 Matches)',
                                                            color: '#9ca3af',
                                                            font: {
                                                                size: 16
                                                            },
                                                            padding: {
                                                                bottom: 30
                                                            }
                                                        },
                                                        legend: {
                                                            display: false
                                                        },
                                                        tooltip: {
                                                            backgroundColor: 'rgba(13, 17, 23, 0.9)',
                                                            titleColor: '#6b7280',
                                                            bodyColor: '#fff',
                                                            bodyFont: {
                                                                size: 14,
                                                                weight: 'bold'
                                                            },
                                                            padding: 12,
                                                            displayColors: false,
                                                            callbacks: {
                                                                title: function(context) {
                                                                    return 'Round ' + mmrData[context[0].dataIndex].Round_ID;
                                                                },
                                                                beforeLabel: function(context) {
                                                                    return 'MMR: ' + context.raw;
                                                                },
                                                                label: function(context) {
                                                                    let currentMMR = context.raw;
                                                                    let prevMMR = context.dataIndex > 0 ? mmrData[context.dataIndex - 1].MMR : currentMMR;
                                                                    let change = currentMMR - prevMMR;
                                                                    return change >= 0 ? 
                                                                        `(+${change})` : 
                                                                        `(${change})`;
                                                                },
                                                                labelTextColor: function(context) {
                                                                    let currentMMR = context.raw;
                                                                    let prevMMR = context.dataIndex > 0 ? mmrData[context.dataIndex - 1].MMR : currentMMR;
                                                                    let change = currentMMR - prevMMR;
                                                                    return change >= 0 ? 'rgb(34, 197, 94)' : 'rgb(239, 68, 68)';
                                                                }
                                                            }
                                                        }
                                                    },
                                                    scales: {
                                                        x: {
                                                            grid: {
                                                                color: 'rgba(255, 255, 255, 0.05)',
                                                                drawBorder: false
                                                            },
                                                            ticks: {
                                                                color: '#6b7280',
                                                                font: {
                                                                    size: 11
                                                                },
                                                                maxRotation: 45,
                                                                minRotation: 45
                                                            }
                                                        },
                                                        y: {
                                                            grid: {
                                                                color: 'rgba(255, 255, 255, 0.05)',
                                                                drawBorder: false
                                                            },
                                                            ticks: {
                                                                color: '#6b7280',
                                                                font: {
                                                                    size: 12
                                                                }
                                                            }
                                                        }
                                                    },
                                                    interaction: {
                                                        intersect: false,
                                                        mode: 'index'
                                                    }
                                                }
                                            });
                                            console.timeEnd('Chart Creation');
                                        } catch (error) {
                                            console.error('Error creating chart:', error);
                                        }
                                    }
                                    console.timeEnd('MMR Chart Generation');

                                    // Add timer for session rendering
                                    console.time('Session Rendering');
                                    document.querySelectorAll('.hp-underline').forEach(row => {
                                        const hp = parseInt(row.dataset.hp);
                                        if (!isNaN(hp)) {
                                            row.style.setProperty('--hp-width', `${hp}%`);
                                        }
                                    });
                                    console.timeEnd('Session Rendering');

                                    // At the end of all scripts
                                    window.addEventListener('load', () => {
                                        console.timeEnd('Total Page Load');
                                        
                                        // Log memory usage if available
                                        if (window.performance && window.performance.memory) {
                                            console.log('Memory Usage:', {
                                                usedJSHeapSize: Math.round(performance.memory.usedJSHeapSize / 1048576) + 'MB',
                                                totalJSHeapSize: Math.round(performance.memory.totalJSHeapSize / 1048576) + 'MB'
                                            });
                                        }
                                    });
                                </script>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h3 class="text-center mb-3">Session History</h3>
                                <div id="sessions-container">
                                    <?php 
                                    foreach ($sessions as $index => $session): 
                                        $sessionId = "session-" . $index;
                                        $mmrChangeClass = $session['mmr_change'] >= 0 ? 'text-success' : 'text-danger';
                                        $mmrChangeSign = $session['mmr_change'] >= 0 ? '+' : '';
                                    ?>
                                        <div class="session-container mb-3" data-last-match-date="<?php echo $session['matches'][0]['date']; ?>">
                                            <div class="session-header d-flex justify-content-between align-items-center p-3 bg-dark rounded" 
                                                 data-bs-toggle="collapse" 
                                                 data-bs-target="#<?php echo $sessionId; ?>" 
                                                 style="cursor: pointer;">
                                                <div class="d-flex align-items-center">
                                                    <span class="session-time"><?php echo getRelativeTime($session['session_start']); ?></span>
                                                    <span class="session-rounds"><?php echo $session['matches_count']; ?> rounds</span>
                                                </div>
                                                <div>
                                                    <span>MMR: <?php echo $session['mmr_start']; ?> → <?php echo $session['mmr_end']; ?>
                                                        <span class="<?php echo $mmrChangeClass; ?>">
                                                            (<?php echo $mmrChangeSign . $session['mmr_change']; ?>)
                                                        </span>
                                                    </span>
                                                </div>
                                            </div>
                                            
                                            <div class="collapse" id="<?php echo $sessionId; ?>">
                                                <div class="session-matches mt-2">
                                                    <table class="table table-dark table-hover">
                                                        <thead>
                                                            <tr>
                                                                <th>Round</th>
                                                                <th>Result</th>
                                                                <th>Total Damage</th>
                                                                <th>Kills</th>
                                                                <th>Assists</th>
                                                                <th>Survival Time</th>
                                                                <th>Round Duration</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                        <?php 
                                                        if (!empty($session['matches']) && is_array($session['matches'])) {
                                                            foreach ($session['matches'] as $match): 
                                                                // Add debug output for each match
                                                                echo "<!-- Processing match: " . print_r($match, true) . " -->\n";
                                                                
                                                                $result = $match['winner_team'] == $match['player_team'] ? 'Win' : 'Loss';
                                                                $resultClass = $result == 'Win' ? 'text-success' : 'text-danger';
                                                                $is_alive = $match['survival_time'] === 'Alive' || $match['hp'] > 0;
                                                                $survival_time = is_numeric($match['survival_time']) ? $match['survival_time'] : 0;
                                                        ?>
                                                                <tr data-hp="<?php echo $match['hp']; ?>" 
                                                                    data-survival-time="<?php echo is_numeric($match['survival_time']) ? $match['survival_time'] : ''; ?>"
                                                                    data-round-duration="<?php echo $match['round_duration']; ?>">
                                                                    <td><a href="round.php?id=<?php echo $match['round_id']; ?>" class="text-light">#<?php echo $match['round_id']; ?></a></td>
                                                                    <td class="<?php echo $resultClass; ?>"><?php echo $result; ?></td>
                                                                    <td><?php echo number_format($match['total_damage']); ?></td>
                                                                    <td><?php echo $match['kills']; ?></td>
                                                                    <td><?php echo $match['assists']; ?></td>
                                                                    <td><?php echo $is_alive ? 'Alive' : gmdate("i:s", $survival_time); ?></td>
                                                                    <td><?php echo gmdate("i:s", $match['round_duration']); ?></td>
                                                                </tr>
                                                        <?php 
                                                            endforeach;
                                                        } else {
                                                            echo "<!-- No matches found in session or invalid matches data -->\n";
                                                        }
                                                        ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12 text-center">
                                <button id="loadMoreSessions" class="btn btn-outline-light mb-4" data-offset="100">
                                    More...
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    function getRelativeTime(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const difference = Math.floor((now - date) / 1000); // Convert to seconds

        const periods = {
            31536000: 'year',
            2592000: 'month',
            604800: 'week',
            86400: 'day',
            3600: 'hour',
            60: 'minute',
            1: 'second'
        };

        for (const [seconds, label] of Object.entries(periods)) {
            const interval = Math.floor(difference / seconds);
            if (interval >= 1) {
                return `${interval} ${label}${interval > 1 ? 's' : ''} ago`;
            }
        }

        return 'just now';
    }

    document.getElementById('loadMoreSessions').addEventListener('click', function() {
        const button = this;
        const offset = parseInt(button.dataset.offset);
        const playerId = '<?php echo $player_id; ?>';
        
        // Get the timestamp of the last match in the LAST session
        const allSessions = document.querySelectorAll('.session-container');
        const lastSession = allSessions[allSessions.length - 1];
        const lastMatchDate = lastSession.dataset.lastMatchDate;
        
        // Get MMR end from the last session's header
        const originalMmrEnd = lastSession.querySelector('.session-header div:last-child > span').textContent.split('→')[1].split('(')[0].trim();
        
        // Show loading state
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Loading...';
        
        fetch(`get_more_sessions.php?player_id=${playerId}&offset=${offset}&last_match_date=${lastMatchDate}&original_mmr_end=${originalMmrEnd}`)
            .then(response => response.json())
            .then(data => {
                console.log('Received data:', data);
                const sessionsContainer = document.getElementById('sessions-container');
                
                if (data.merge_with_last_session) {
                    // Handle merge case
                    const lastSessionElement = document.querySelector('.session-container:last-child');
                    if (lastSessionElement) {
                        const tableBody = lastSessionElement.querySelector('tbody');
                        if (tableBody) {
                            data.matches.forEach(match => {
                                const result = match.winner_team === match.player_team ? 'Win' : 'Loss';
                                const resultClass = result === 'Win' ? 'text-success' : 'text-danger';
                                const isAlive = match.survival_time === 'Alive' || match.hp > 0;
                                const survivalTime = isAlive ? 'Alive' : new Date(match.survival_time * 1000).toISOString().substr(14, 5);
                                const roundDuration = new Date(match.round_duration * 1000).toISOString().substr(14, 5);
                                
                                const rowHtml = `
                                    <tr class="${isAlive ? 'alive-player hp-underline' : ''}" 
                                        data-hp="${match.hp}" 
                                        data-survival-percentage="${(match.survival_time / match.round_duration) * 100}">
                                        <td><a href="round.php?id=${match.round_id}" class="text-light">#${match.round_id}</a></td>
                                        <td class="${resultClass}">${result}</td>
                                        <td>${Number(match.total_damage).toLocaleString()}</td>
                                        <td>${match.kills}</td>
                                        <td>${match.assists}</td>
                                        <td>${survivalTime}</td>
                                        <td>${roundDuration}</td>
                                    </tr>
                                `;
                                tableBody.insertAdjacentHTML('beforeend', rowHtml);
                            });
                            
                            // Update session stats
                            const sessionHeader = lastSessionElement.querySelector('.session-header');
                            const statsSpan = sessionHeader.querySelector('div:last-child > span');
                            statsSpan.innerHTML = `MMR: ${data.session_stats.mmr_start} → ${data.session_stats.mmr_end}
                                <span class="${data.session_stats.mmr_change >= 0 ? 'text-success' : 'text-danger'}">
                                    (${data.session_stats.mmr_change >= 0 ? '+' : ''}${data.session_stats.mmr_change})
                                </span>`;
                            
                            // Get current number of rows in the table
                            const currentRowCount = tableBody.querySelectorAll('tr').length;
                            
                            // Update rounds count with actual count from table
                            const roundsSpan = sessionHeader.querySelector('.session-rounds');
                            roundsSpan.textContent = `${currentRowCount} rounds`;
                        }
                    }
                }
                
                // Handle new sessions (check both data.new_sessions and data.sessions)
                const sessionsToAdd = data.new_sessions || data.sessions || [];
                if (sessionsToAdd.length > 0) {
                    sessionsToAdd.forEach(session => {
                        const mmrChangeClass = session.mmr_change >= 0 ? 'text-success' : 'text-danger';
                        const mmrChangeSign = session.mmr_change >= 0 ? '+' : '';
                        const sessionId = `session-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
                        
                        const sessionHtml = `
                            <div class="session-container mb-3" data-last-match-date="${session.matches[0].date}">
                                <div class="session-header d-flex justify-content-between align-items-center p-3 bg-dark rounded" 
                                     data-bs-toggle="collapse" 
                                     data-bs-target="#${sessionId}" 
                                     style="cursor: pointer;">
                                    <div class="d-flex align-items-center">
                                        <span class="session-time">${session.relative_time}</span>
                                        <span class="session-rounds ms-3">${session.matches_count} rounds</span>
                                    </div>
                                    <div>
                                        <span>MMR: ${session.mmr_start} → ${session.mmr_end}
                                            <span class="${mmrChangeClass}">
                                                (${mmrChangeSign}${session.mmr_change})
                                            </span>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="collapse" id="${sessionId}">
                                    <div class="session-matches mt-2">
                                        <table class="table table-dark table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Round</th>
                                                    <th>Result</th>
                                                    <th>Total Damage</th>
                                                    <th>Kills</th>
                                                    <th>Assists</th>
                                                    <th>Survival Time</th>
                                                    <th>Round Duration</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${session.matches.map(match => {
                                                    const result = match.winner_team === match.player_team ? 'Win' : 'Loss';
                                                    const resultClass = result === 'Win' ? 'text-success' : 'text-danger';
                                                    const isAlive = match.survival_time === 'Alive' || match.hp > 0;
                                                    const survivalTime = isAlive ? 'Alive' : new Date(match.survival_time * 1000).toISOString().substr(14, 5);
                                                    const roundDuration = new Date(match.round_duration * 1000).toISOString().substr(14, 5);
                                                    
                                                    return `
                                                        <tr class="${isAlive ? 'alive-player hp-underline' : ''}" 
                                                            data-hp="${match.hp}" 
                                                            data-survival-percentage="${(match.survival_time / match.round_duration) * 100}">
                                                            <td><a href="round.php?id=${match.round_id}" class="text-light">#${match.round_id}</a></td>
                                                            <td class="${resultClass}">${result}</td>
                                                            <td>${Number(match.total_damage).toLocaleString()}</td>
                                                            <td>${match.kills}</td>
                                                            <td>${match.assists}</td>
                                                            <td>${survivalTime}</td>
                                                            <td>${roundDuration}</td>
                                                        </tr>
                                                    `;
                                                }).join('')}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        `;
                        sessionsContainer.insertAdjacentHTML('beforeend', sessionHtml);
                    });
                }
                
                // Update offset for next request
                button.dataset.offset = data.new_offset;
                
                // Hide button if no more data
                if (!data.has_more) {
                    button.style.display = 'none';
                }
                
                // Reset button state
                button.disabled = false;
                button.innerHTML = 'More...';
            })
            .catch(error => {
                console.error('Error:', error);
                button.disabled = false;
                button.innerHTML = 'Error loading more sessions. Try again.';
            });
    });
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tables = document.querySelectorAll('.table');

        tables.forEach(table => {
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const hp = parseInt(row.dataset.hp);
                const survivalTime = parseInt(row.dataset.survivalTime);
                const roundDuration = parseInt(row.dataset.roundDuration);
                
                if (survivalTime === 0 || isNaN(survivalTime)) {
                    // For alive players
                    row.classList.add('alive-player', 'hp-underline');
                    row.style.setProperty('--hp-width', `${hp}%`);
                } else {
                    // For players who didn't survive
                    const percentage = Math.min(100, (survivalTime / roundDuration) * 100);
                    row.style.background = `linear-gradient(to right, rgba(220, 53, 69, 0.2) ${percentage}%, transparent ${percentage}%)`;
                }
            });
        });
    });
    </script>
</body>
</html>
