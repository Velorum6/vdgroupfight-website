<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'get_player_stats.php';

$player_id = isset($_GET['id']) ? $_GET['id'] : '';

if (empty($player_id) || !preg_match('/^[0-9.]+$/', $player_id)) {
    die("Invalid player ID");
}

$main_query = "WITH PlayerRounds AS (
    SELECT 
        rp.Name,
        rp.Player_ID,
        rp.Round_ID,
        rp.MMR,
        r.WinnerTeam,
        rp.Team,
        rp.Total_Damage,
        rp.Melee_Damage,
        rp.Ranged_Damage,
        rp.Throwing_Damage,
        rp.Mounted_Melee_Damage,
        rp.Mounted_Ranged_Damage,
        rp.Mounted_Throwing_Damage,
        rp.HP,
        r.Date as Datee,
        r.Time as Round_Duration,
        CASE 
            WHEN k.Survival_Time IS NULL AND r.WinnerTeam != rp.Team THEN 'Abandoned'
            WHEN k.Survival_Time IS NULL THEN 'Alive'
            ELSE k.Survival_Time
        END as Survival_Time,
        (SELECT COUNT(*) FROM Kills WHERE Killer_ID = rp.Player_ID AND Round_ID = rp.Round_ID) as Kills,
        (SELECT COUNT(*) FROM Kills WHERE Assist_ID = rp.Player_ID AND Round_ID = rp.Round_ID) as Assists
    FROM Round_Player rp
    JOIN Rounds r ON rp.Round_ID = r.Round_ID
    LEFT JOIN Kills k ON rp.Round_ID = k.Round_ID AND rp.Player_ID = k.Killed_ID
    WHERE rp.Player_ID = '$player_id'
)
SELECT 
    Name,
    Player_ID,
    SUM(Total_Damage) as Total_Damage,
    SUM(Melee_Damage) as Melee_Damage,
    SUM(Ranged_Damage) as Ranged_Damage,
    SUM(Throwing_Damage) as Throwing_Damage,
    SUM(Mounted_Melee_Damage) as Mounted_Melee_Damage,
    SUM(Mounted_Ranged_Damage) as Mounted_Ranged_Damage,
    SUM(Mounted_Throwing_Damage) as Mounted_Throwing_Damage,
    COUNT(*) as Rounds_Played,
    SUM(CASE WHEN WinnerTeam = Team THEN 1 ELSE 0 END) as Wins,
    SUM(Kills) as Total_Kills,
    SUM(CASE WHEN Survival_Time != 'Alive' AND Survival_Time != 'Abandoned' THEN 1 ELSE 0 END) as Total_Deaths,
    SUM(Assists) as Total_Assists,
    MAX(MMR) as Highest_MMR,
    MIN(MMR) as Lowest_MMR,
    (SELECT MMR FROM PlayerRounds ORDER BY Round_ID DESC LIMIT 1) as Current_MMR,
    GROUP_CONCAT(Round_ID || ',' || 
                 WinnerTeam || ',' || 
                 Team || ',' || 
                 Round_Duration || ',' || 
                 Survival_Time || ',' || 
                 Total_Damage || ',' ||
                 Kills || ',' ||
                 Assists || ',' ||
                 Round_ID || ',' ||
                 HP || ',' ||
                 MMR || ',' || 
                 Datee) as Match_History
FROM PlayerRounds
GROUP BY Player_ID, Name";

$player_data = query_db($main_query);

if (empty($player_data)) {
    die("No data found for player ID: " . htmlspecialchars($player_id));
}
$player_data = $player_data[0];  // Assuming the first row is what we want

// Parse the Match_History
$match_history_raw = explode(',', $player_data['Match_History']);
$match_history = [];
for ($i = 0; $i < count($match_history_raw); $i += 12) {
    $match_history[] = [
        'round_id' => $match_history_raw[$i],
        'winner_team' => $match_history_raw[$i + 1],
        'player_team' => $match_history_raw[$i + 2],
        'round_duration' => $match_history_raw[$i + 3],
        'survival_time' => $match_history_raw[$i + 4],
        'total_damage' => $match_history_raw[$i + 5],
        'kills' => $match_history_raw[$i + 6],
        'assists' => $match_history_raw[$i + 7],
        'sort_round_id' => $match_history_raw[$i + 8],
        'hp' => $match_history_raw[$i + 9],
        'mmr' => $match_history_raw[$i + 10],
        'date' => $match_history_raw[$i + 11]
    ];
}


// Sort the match history by Round_ID in descending order
usort($match_history, function($a, $b) {
    return $b['sort_round_id'] - $a['sort_round_id'];
});

// Separate query for MMR history
$mmr_query = "SELECT MMR, Round_ID
              FROM Round_Player
              WHERE Player_ID = '$player_id'
              ORDER BY Round_ID DESC
              LIMIT 100";

$mmr_history = query_db($mmr_query);
$mmr_history = array_reverse($mmr_history);

// Prepare MMR data for the chart
$mmr_data = [];
foreach ($mmr_history as $entry) {
    $mmr_data[] = [
        'round' => $entry['Round_ID'],
        'mmr' => $entry['MMR']
    ];
}

// Calculate win rate
$win_rate = ($player_data['Rounds_Played'] > 0) ? round(($player_data['Wins'] / $player_data['Rounds_Played']) * 100, 2) : 0;

// Calculate averages
$player_data['Avg_Damage'] = $player_data['Rounds_Played'] > 0 ? round($player_data['Total_Damage'] / $player_data['Rounds_Played'], 2) : 0;
$player_data['Avg_Kills'] = $player_data['Rounds_Played'] > 0 ? round($player_data['Total_Kills'] / $player_data['Rounds_Played'], 2) : 0;
$player_data['Avg_Deaths'] = $player_data['Rounds_Played'] > 0 ? round($player_data['Total_Deaths'] / $player_data['Rounds_Played'], 2) : 0;
$player_data['Avg_Assists'] = $player_data['Rounds_Played'] > 0 ? round($player_data['Total_Assists'] / $player_data['Rounds_Played'], 2) : 0;
$player_data['Avg_Mounted_Melee_Damage'] = $player_data['Rounds_Played'] > 0 ? round($player_data['Mounted_Melee_Damage'] / $player_data['Rounds_Played'], 2) : 0;
$player_data['Avg_Mounted_Ranged_Damage'] = $player_data['Rounds_Played'] > 0 ? round($player_data['Mounted_Ranged_Damage'] / $player_data['Rounds_Played'], 2) : 0;
$player_data['Avg_Mounted_Throwing_Damage'] = $player_data['Rounds_Played'] > 0 ? round($player_data['Mounted_Throwing_Damage'] / $player_data['Rounds_Played'], 2) : 0;

// Calculate damage percentages
$total_damage = $player_data['Total_Damage'];
$damage_types = [
    'Melee' => $player_data['Melee_Damage'],
    'Ranged' => $player_data['Ranged_Damage'],
    'Throwing' => $player_data['Throwing_Damage'],
    'Mounted Melee' => $player_data['Mounted_Melee_Damage'],
    'Mounted Ranged' => $player_data['Mounted_Ranged_Damage'],
    'Mounted Throwing' => $player_data['Mounted_Throwing_Damage']
];


$damage_percentages = array_map(function($damage) use ($total_damage) {
    return $total_damage > 0 ? round(($damage / $total_damage) * 100, 2) : 0;
}, $damage_types);

// Function to group matches into sessions
function groupMatchesIntoSessions($matches, $maxTimeDiff = 1800) {
    if (empty($matches)) {
        return [];
    }

    // Sort matches by round_id ascending (oldest first)
    usort($matches, function($a, $b) {
        return $a['round_id'] - $b['round_id'];
    });

    $sessions = [];
    $currentSession = [];
    $lastMatchTime = null;
    $lastSessionMMR = null;
    
    foreach ($matches as $match) {
        $currentMatchTime = strtotime($match['date']);
        
        if ($lastMatchTime === null || 
            ($currentMatchTime - $lastMatchTime) > $maxTimeDiff) {
            
            if (!empty($currentSession)) {
                // Sort the current session matches in descending order
                usort($currentSession, function($a, $b) {
                    return $b['round_id'] - $a['round_id'];
                });
                
                $lastMatch = end($currentSession);
                $startingMMR = $lastSessionMMR ?? $currentSession[count($currentSession)-1]['mmr'];
                
                $sessions[] = [
                    'matches' => $currentSession,
                    'matches_count' => count($currentSession),
                    'mmr_start' => $startingMMR,
                    'mmr_end' => $currentSession[0]['mmr'],  // Changed to first match since order is reversed
                    'mmr_change' => $currentSession[0]['mmr'] - $startingMMR,
                    'session_start' => date('Y-m-d H:i:s', strtotime($currentSession[count($currentSession)-1]['date'])),
                    'session_end' => date('Y-m-d H:i:s', strtotime($currentSession[0]['date']))
                ];
                
                $lastSessionMMR = $currentSession[0]['mmr'];
            }
            
            $currentSession = [$match];
        } else {
            $currentSession[] = $match;
        }
        
        $lastMatchTime = $currentMatchTime;
    }
    
    // Handle the last session
    if (!empty($currentSession)) {
        // Sort the last session matches in descending order
        usort($currentSession, function($a, $b) {
            return $b['round_id'] - $a['round_id'];
        });
        
        $startingMMR = $lastSessionMMR ?? $currentSession[count($currentSession)-1]['mmr'];
        
        $sessions[] = [
            'matches' => $currentSession,
            'matches_count' => count($currentSession),
            'mmr_start' => $startingMMR,
            'mmr_end' => $currentSession[0]['mmr'],
            'mmr_change' => $currentSession[0]['mmr'] - $startingMMR,
            'session_start' => date('Y-m-d H:i:s', strtotime($currentSession[count($currentSession)-1]['date'])),
            'session_end' => date('Y-m-d H:i:s', strtotime($currentSession[0]['date']))
        ];
    }
    
    return array_reverse($sessions);
}

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
        if ($difference >= $seconds) {
            $time = floor($difference / $seconds);
            return $time . ' ' . $label . ($time > 1 ? 's' : '') . ' ago';
        }
    }
    
    return 'just now';
}

// In your player.php, replace the match history section with:

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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                                        <span><?php echo number_format($win_rate, 2); ?>%</span>
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
                                <div class="chart-container" style="position: relative; height:400px; width:100%;">
                                    <canvas id="mmrChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h3 class="text-center mb-3">Session History</h3>
                                <?php 
                                $sessions = groupMatchesIntoSessions($match_history);
                                foreach ($sessions as $index => $session): 
                                    $sessionId = "session-" . $index;
                                    $mmrChangeClass = $session['mmr_change'] >= 0 ? 'text-success' : 'text-danger';
                                    $mmrChangeSign = $session['mmr_change'] >= 0 ? '+' : '';
                                ?>
                                    <div class="session-container mb-3">
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
                                                    <?php foreach ($session['matches'] as $match): 
                                                        $result = $match['winner_team'] == $match['player_team'] ? 'Win' : 'Loss';
                                                        $resultClass = $result == 'Win' ? 'text-success' : 'text-danger';
                                                        $is_alive = $match['hp'] > 0;
                                                    ?>
                                                        <tr class="<?php echo $is_alive ? 'alive-player hp-underline' : ''; ?>" 
                                                            data-hp="<?php echo $match['hp']; ?>" 
                                                            data-survival-percentage="<?php echo ($match['survival_time'] / $match['round_duration']) * 100; ?>">
                                                            <td><a href="round.php?id=<?php echo $match['round_id']; ?>">#<?php echo $match['round_id']; ?></a></td>
                                                            <td class="<?php echo $resultClass; ?>"><?php echo $result; ?></td>
                                                            <td><?php echo number_format($match['total_damage']); ?></td>
                                                            <td><?php echo $match['kills']; ?></td>
                                                            <td><?php echo $match['assists']; ?></td>
                                                            <td><?php echo $is_alive ? 'Alive' : gmdate("i:s", $match['survival_time']); ?></td>
                                                            <td><?php echo gmdate("i:s", $match['round_duration']); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var mmrCtx = document.getElementById('mmrChart').getContext('2d');
        var mmrData = <?php echo json_encode($mmr_history); ?>;

        // Calculate MMR differences
        var mmrDifferences = mmrData.map((item, index) => {
            if (index === 0) return 0;
            return item.MMR - mmrData[index - 1].MMR;
        });

        var labels = mmrData.map(function(item, index) { return 'Round ' + (index + 1); });
        var data = mmrData.map(function(item) { return item.MMR; });
        
        var mmrChart = new Chart(mmrCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'MMR',
                    data: data,
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1,
                    pointRadius: 4,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            autoSkip: true,
                            maxTicksLimit: 20
                        }
                    },
                    y: {
                        beginAtZero: false
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'MMR History (Last 100 Matches)',
                        font: {
                            size: 16
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var index = context.dataIndex;
                                var mmr = context.raw;
                                var difference = mmrDifferences[index];
                                var differenceText = index === 0 ? '' : (difference >= 0 ? ' (+' + difference + ')' : ' (' + difference + ')');
                                return [
                                    'MMR: ' + mmr,
                                    differenceText
                                ];
                            },
                            labelTextColor: function(context) {
                                var index = context.dataIndex;
                                if (index === 0) return 'white';
                                var difference = mmrDifferences[index];
                                return difference >= 0 ? 'rgb(75, 192, 75)' : 'rgb(255, 99, 132)';
                            }
                        }
                    }
                }
            }
        });
    });
    </script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const rows = document.querySelectorAll('.table tbody tr');
        rows.forEach(row => {
            const survivalPercentage = parseFloat(row.dataset.survivalPercentage);
            const hp = parseInt(row.dataset.hp);
            
            if (row.classList.contains('alive-player')) {
                row.style.setProperty('--hp-width', `${hp}%`);
            } else {
                row.style.background = `linear-gradient(to right, rgba(220, 53, 69, 0.2) ${survivalPercentage}%, transparent ${survivalPercentage}%)`;
            }
        });
    });
    </script>
</body>
</html>
