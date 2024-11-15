<?php
require_once 'get_player_stats.php';

$round_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($round_id === 0) {
    die("Invalid round ID");
}

// Fetch round details
$round_query = "SELECT * FROM Rounds WHERE Round_ID = $round_id";
$round_data = query_db($round_query)[0];

if (!$round_data) {
    die("Round not found");
}

// Fetch players for this round
$players_query = "
SELECT 
    rp.*,
    COALESCE(k.KillCount, 0) as Kills,
    d.Survival_Time,
    rp.HP
FROM Round_Player rp 
LEFT JOIN (
    SELECT Killer_ID, COUNT(*) as KillCount
    FROM Kills
    WHERE Round_ID = $round_id
    GROUP BY Killer_ID
) k ON rp.Player_ID = k.Killer_ID
LEFT JOIN (
    SELECT Killed_ID, Survival_Time
    FROM Kills
    WHERE Round_ID = $round_id
) d ON rp.Player_ID = d.Killed_ID
WHERE rp.Round_ID = $round_id
ORDER BY rp.Team, rp.Total_Damage DESC
";

$players_data = query_db($players_query);

$attackers = array_filter($players_data, function($player) { return $player['Team'] == 'Attacker'; });
$defenders = array_filter($players_data, function($player) { return $player['Team'] == 'Defender'; });

function determineWinningTeam($round_data) {
    return $round_data['WinnerTeam'];
}

$winning_team = determineWinningTeam($round_data);

// Calculate survival times for the losing team
$losing_team = $winning_team == 'Attacker' ? $defenders : $attackers;
$survival_times = array_filter(array_column($losing_team, 'Survival_Time'), function($time) {
    return $time !== null;
});
$lowest_survival_time = !empty($survival_times) ? min($survival_times) : null;
$highest_survival_time = !empty($survival_times) ? max($survival_times) : null;

// After fetching the player data
$max_survival_time = [
    'Attacker' => 0,
    'Defender' => 0
];

foreach ($players_data as $player) {
    if ($player['Survival_Time'] !== null) {
        $max_survival_time[$player['Team']] = max($max_survival_time[$player['Team']], $player['Survival_Time']);
    }
}

// Calculate maximum survival time for the round
$survival_times = array_filter(array_column($players_data, 'Survival_Time'), function($time) {
    return $time !== null;
});
$max_survival_time = !empty($survival_times) ? max($survival_times) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Round <?php echo $round_id; ?> Details</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32x32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/favicon-16x16.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="assets/img/apple-touch-icon.png" />
    <link rel="shortcut icon" href="assets/img/favicon.ico" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/main.css" />
    <style>
        .card-transparent {
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(10px);
        }
        .team-table {
            width: 100%;
            margin-bottom: 1rem;
        }
        .winner-header th {
            background-color: rgba(40, 167, 69, 0.3) !important;
            color: white !important;
        }
        .loser-header th {
            background-color: rgba(220, 53, 69, 0.3) !important;
            color: white !important;
        }
        .text-center {
            text-align: center !important;
        }
        .hp-bar {
            position: relative;
        }
        .hp-bar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 0;
            background: linear-gradient(to right, rgba(40, 167, 69, 0.2), rgba(40, 167, 69, 0.05));
        }
        .hp-bar td {
            position: relative;
            z-index: 1;
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
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card card-transparent text-light">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h2 class="mb-0">Round <?php echo $round_id; ?> Details</h2>
                            <a href="javascript:history.back()" class="btn btn-cezero">Back to Leaderboard</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <p>Date: <?php echo $round_data['Time']; ?></p>
                        <p>Duration: <?php echo isset($round_data['Time']) ? formatDuration($round_data['Time']) : 'N/A'; ?></p>
                        <p>Total Players: <?php echo count($players_data); ?></p>

                        <div class="row mt-4">
                            <div class="col-md-6">
                                <h3>Attackers</h3>
                                <table class="table table-dark team-table">
                                    <thead class="<?php echo $winning_team == 'Attacker' ? 'winner-header' : 'loser-header'; ?>">
                                        <tr>
                                            <th class="text-center">Name</th>
                                            <th class="text-center">Damage</th>
                                            <th class="text-center">Kills</th>
                                            <th class="text-center">Survival</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($attackers as $player): ?>
                                        <tr data-hp="<?php echo $player['HP']; ?>" data-survival-time="<?php echo $player['Survival_Time'] !== null ? $player['Survival_Time'] : ''; ?>">
                                            <td class="text-center"><a href="player.php?id=<?php echo $player['Player_ID']; ?>" class="text-light"><?php echo htmlspecialchars($player['Name']); ?></a></td>
                                            <td class="text-center"><?php echo $player['Total_Damage']; ?></td>
                                            <td class="text-center"><?php echo $player['Kills']; ?></td>
                                            <td class="text-center">
                                                <?php 
                                                if ($player['Survival_Time'] === null) {
                                                    echo "Alive";
                                                } else {
                                                    echo formatDuration($player['Survival_Time']);
                                                    if ($winning_team != 'Attacker') {
                                                        if ($player['Survival_Time'] == $lowest_survival_time) {
                                                            echo " 💀";
                                                        } elseif ($player['Survival_Time'] == $highest_survival_time) {
                                                            echo " 🛡️";
                                                        }
                                                    } else if ($winning_team == 'Attacker') {
                                                        if ($player['Survival_Time'] == $lowest_survival_time) {
                                                            echo " 💀";
                                                        }
                                                    }
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h3>Defenders</h3>
                                <table class="table table-dark team-table">
                                    <thead class="<?php echo $winning_team == 'Defender' ? 'winner-header' : 'loser-header'; ?>">
                                        <tr>
                                            <th class="text-center">Name</th>
                                            <th class="text-center">Damage</th>
                                            <th class="text-center">Kills</th>
                                            <th class="text-center">Survival</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($defenders as $player): ?>
                                        <tr data-hp="<?php echo $player['HP']; ?>" data-survival-time="<?php echo $player['Survival_Time'] !== null ? $player['Survival_Time'] : ''; ?>">
                                            <td class="text-center"><a href="player.php?id=<?php echo $player['Player_ID']; ?>" class="text-light"><?php echo htmlspecialchars($player['Name']); ?></a></td>
                                            <td class="text-center"><?php echo $player['Total_Damage']; ?></td>
                                            <td class="text-center"><?php echo $player['Kills']; ?></td>
                                            <td class="text-center">
                                                <?php 
                                                if ($player['Survival_Time'] === null) {
                                                    echo "Alive";
                                                } else {
                                                    echo formatDuration($player['Survival_Time']);
                                                    if ($winning_team != 'Defender') {
                                                        if ($player['Survival_Time'] == $lowest_survival_time) {
                                                            echo " 💀";
                                                        } elseif ($player['Survival_Time'] == $highest_survival_time) {
                                                            echo " 🛡️";
                                                        }
                                                    } else if ($winning_team == 'Defender') {
                                                        if ($player['Survival_Time'] == $lowest_survival_time) {
                                                            echo " 💀";
                                                        }
                                                    }
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tables = document.querySelectorAll('.team-table');
        const maxSurvivalTime = <?php echo $max_survival_time; ?>;

        tables.forEach(table => {
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const hp = parseInt(row.dataset.hp);
                const survivalTime = parseInt(row.dataset.survivalTime);
                
                if (survivalTime === 0 || isNaN(survivalTime)) {
                    // For alive players
                    row.classList.add('alive-player', 'hp-underline');
                    row.style.setProperty('--hp-width', `${hp}%`);
                } else {
                    // For players who didn't survive
                    const percentage = Math.min(100, (survivalTime / maxSurvivalTime) * 100);
                    row.style.background = `linear-gradient(to right, rgba(220, 53, 69, 0.2) ${percentage}%, transparent ${percentage}%)`;
                }
            });
        });
    });
    </script>
</body>
</html>
