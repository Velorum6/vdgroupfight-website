<?php
$start_time = microtime(true);
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'get_player_stats.php';

error_log("Initial setup time: " . (microtime(true) - $start_time));

// Query to get clan data with MMR sums - modified for SQLite syntax
$clan_query = "
WITH PlayerClans AS (
    SELECT 
        rp.player_id,
        rp.name,
        rp.mmr,
        CASE 
            WHEN rp.Name LIKE '%[%]%'
            THEN trim(substr(rp.Name, instr(rp.Name, '[') + 1, instr(rp.Name, ']') - instr(rp.Name, '[') - 1))
            ELSE 'No Clan'
        END as ClanTag
    FROM player_stats_projection rp
    INNER JOIN (
        SELECT Player_ID, MAX(Round_ID) as MaxRound
        FROM Round_Player
        GROUP BY Player_ID
    ) latest ON rp.Player_ID = latest.Player_ID 
)
SELECT 
    ClanTag,
    COUNT(DISTINCT Player_ID) as MemberCount,
    SUM(MMR) as TotalMMR,
    AVG(MMR) as AvgMMR,
    GROUP_CONCAT(Name) as Members
FROM PlayerClans
WHERE ClanTag != 'No Clan'
GROUP BY ClanTag
HAVING COUNT(*) >= 3
ORDER BY TotalMMR DESC;
";

// Before query
$query_start = microtime(true);
$clan_data = query_db($clan_query);
error_log("Query execution time: " . (microtime(true) - $query_start));

// Before JSON encoding
$json_start = microtime(true);
$clan_json = json_encode($clan_data);
error_log("JSON encoding time: " . (microtime(true) - $json_start));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clan MMR Map</title>
    <style>
        .map-container {
            width: 100%;
            min-height: 100vh;
            background-color: #1e222d;
            padding: 0;
        }
        #clan-map {
            width: 100%;
            height: 100vh;
            display: flex;
            flex-wrap: wrap;
            gap: 1px;
            background-color: #1a1a1a;
        }
        .clan-box {
            position: relative;
            color: white;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 4px;
            font-family: Arial, sans-serif;
            background-color: rgb(139, 0, 0);
        }
        .clan-tag {
            font-size: 12px;
            font-weight: bold;
        }
        .clan-mmr {
            font-size: 11px;
        }
        .clan-members {
            font-size: 10px;
            opacity: 0.7;
        }
        .members-list {
            display: none;
            position: absolute;
            background: rgba(20, 20, 20, 0.8);
            padding: 8px;
            border-radius: 4px;
            z-index: 1000;
            white-space: nowrap;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
            scrollbar-width: thin;
            scrollbar-color: rgba(255, 255, 255, 0.2) transparent;
        }
        
        .members-list::-webkit-scrollbar {
            width: 6px;
        }
        
        .members-list::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .members-list::-webkit-scrollbar-thumb {
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }
        
        .members-list div {
            font-size: 11px;
            padding: 3px 6px;
            color: rgba(255, 255, 255, 1);
            transition: background-color 0.2s;
        }
        
        .members-list div:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }
        
        .clan-box:hover .members-list {
            display: block;
        }
    </style>
</head>
<body>
    <!-- Debug output -->
    <div style="display:none">
        <?php
        echo "Debug Information:<br>";
        echo "Raw Query: " . htmlspecialchars($clan_query) . "<br>";
        echo "Raw Response: <pre>" . print_r($clan_data, true) . "</pre><br>";
        echo "JSON Data: <pre>" . htmlspecialchars($clan_json) . "</pre>";
        ?>
    </div>

    <div class="map-container">
        <div id="clan-map"></div>
    </div>

    <script>
        console.time('Total JavaScript Execution');
        console.time('Data Processing');
        
        const clanData = <?php echo $clan_json; ?>;
        console.timeEnd('Data Processing');
        
        console.time('DOM Creation');
        const mapContainer = document.getElementById('clan-map');
        
        console.log('Initial clan data:', clanData); // Debug data

        // Sort clans by MMR
        const sortedClans = [...clanData].sort((a, b) => b.TotalMMR - a.TotalMMR);
        
        // Find maximum MMR for scaling
        const maxMMR = Math.max(...sortedClans.map(clan => clan.TotalMMR));
        
        // Create boxes
        sortedClans.forEach(clan => {
            const box = document.createElement('div');
            box.className = 'clan-box';
            console.log(clan);
            // Calculate relative size (flex-grow) based on MMR
            const relativeSize = clan.TotalMMR / maxMMR;
            const baseSize = Math.max(100, relativeSize * 500); // Minimum 100px
            
            // Set flex properties for proportional sizing
            box.style.flex = `${relativeSize} 1 ${baseSize}px`;
            
            const performance = ((clan.AvgMMR - 1000) / 1000) * 100;
            
            // Color calculation based on performance
            let color;
            if (performance >= 5) {
                // Bright green for high positive
                const brightness = Math.min(255, 100 + performance * 3);
                color = `rgb(0, ${brightness}, 0)`;
            } else if (performance > 1) {
                // Normal green for moderate positive
                color = 'rgb(0, 100, 0)';
            } else if (performance > 0) {
                // Gray-green for slight positive
                color = 'rgb(45, 65, 45)';
            } else if (performance > -1) {
                // Gray-red for slight negative
                color = 'rgb(65, 45, 45)';
            } else if (performance > -5) {
                // Normal red for moderate negative
                color = 'rgb(100, 0, 0)';
            } else {
                // Bright red for high negative
                const brightness = Math.min(255, 100 + Math.abs(performance) * 3);
                color = `rgb(${brightness}, 0, 0)`;
            }
            
            box.style.backgroundColor = color;
            
            box.innerHTML = `
                <div class="clan-tag">[${clan.ClanTag}]</div>
                <div class="clan-mmr-total">${clan.TotalMMR} MMR</div>
                <div class="clan-mmr">${performance >= 0 ? '+' : ''}${performance.toFixed(1)}%</div>
                <div class="clan-members">${clan.MemberCount} Members</div>
                <div class="members-list">
                    ${clan.Members.split(',').map(member => `<div>${member}</div>`).join('')}
                </div>
            `;
            
            mapContainer.appendChild(box);
        });
        console.timeEnd('DOM Creation');
        console.timeEnd('Total JavaScript Execution');
    </script>
</body>
</html>
