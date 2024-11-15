<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'get_player_stats.php';

// Query to get player interaction data
$player_interaction_query = "
WITH LatestPlayerInfo AS (
    -- Get latest player info efficiently
    SELECT 
        rp.Player_ID,
        rp.Name,
        rp.MMR,
        rp.Round_ID
    FROM Round_Player rp
    INNER JOIN (
        SELECT Player_ID, MAX(Round_ID) as MaxRound
        FROM Round_Player
        GROUP BY Player_ID
    ) latest ON rp.Player_ID = latest.Player_ID 
    AND rp.Round_ID = latest.MaxRound
),
PlayerInteractions AS (
    -- Pre-calculate all interactions using CASE for player ordering
    SELECT 
        CASE WHEN rp1.Player_ID < rp2.Player_ID 
             THEN rp1.Player_ID 
             ELSE rp2.Player_ID 
        END as Player1_ID,
        CASE WHEN rp1.Player_ID < rp2.Player_ID 
             THEN rp2.Player_ID 
             ELSE rp1.Player_ID 
        END as Player2_ID,
        COUNT(*) as Interactions
    FROM Round_Player rp1
    INNER JOIN Round_Player rp2 
        ON rp1.Round_ID = rp2.Round_ID 
        AND rp1.Player_ID != rp2.Player_ID
        AND rp1.Team = rp2.Team  -- Optional: only count as interaction if on same team
    GROUP BY 
        CASE WHEN rp1.Player_ID < rp2.Player_ID 
             THEN rp1.Player_ID 
             ELSE rp2.Player_ID 
        END,
        CASE WHEN rp1.Player_ID < rp2.Player_ID 
             THEN rp2.Player_ID 
             ELSE rp1.Player_ID 
        END
    HAVING COUNT(*) > 2
)
SELECT 
    pi.Player1_ID,
    p1.Name as Player1_Name,
    pi.Player2_ID,
    p2.Name as Player2_Name,
    pi.Interactions,
    p1.MMR as Player1_MMR,
    p2.MMR as Player2_MMR
FROM PlayerInteractions pi
INNER JOIN LatestPlayerInfo p1 ON pi.Player1_ID = p1.Player_ID
INNER JOIN LatestPlayerInfo p2 ON pi.Player2_ID = p2.Player_ID
ORDER BY pi.Interactions DESC;
";

$player_interaction_data = query_db($player_interaction_query);

// Process the data for the network graph
$nodes = [];
$edges = [];
$edge_map = [];
$node_map = [];

// First, get the latest name for each player
$latest_names = [];
foreach ($player_interaction_data as $interaction) {
    if (!isset($latest_names[$interaction['Player1_ID']])) {
        $latest_names[$interaction['Player1_ID']] = $interaction['Player1_Name'];
    }
    if (!isset($latest_names[$interaction['Player2_ID']])) {
        $latest_names[$interaction['Player2_ID']] = $interaction['Player2_Name'];
    }
}

// Create nodes using Player_ID as the unique identifier
foreach ($player_interaction_data as $interaction) {
    if (!isset($node_map[$interaction['Player1_ID']])) {
        $node_map[$interaction['Player1_ID']] = count($nodes);
        $nodes[] = [
            'id' => count($nodes), 
            'label' => $latest_names[$interaction['Player1_ID']], 
            'value' => $interaction['Player1_MMR']
        ];
    }
    if (!isset($node_map[$interaction['Player2_ID']])) {
        $node_map[$interaction['Player2_ID']] = count($nodes);
        $nodes[] = [
            'id' => count($nodes), 
            'label' => $latest_names[$interaction['Player2_ID']], 
            'value' => $interaction['Player2_MMR']
        ];
    }
    $from = $node_map[$interaction['Player1_ID']];
    $to = $node_map[$interaction['Player2_ID']];
    $edge_key = $from < $to ? "{$from}-{$to}" : "{$to}-{$from}";
    
    if (!isset($edge_map[$edge_key])) {
        $edges[] = [
            'from' => $from,
            'to' => $to,
            'value' => $interaction['Interactions']
        ];
        $edge_map[$edge_key] = true;
    }
}

$graph_data = json_encode(['nodes' => $nodes, 'edges' => $edges]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Player Interaction Map</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/main.css" />
    <script type="text/javascript" src="https://unpkg.com/vis-network/standalone/umd/vis-network.min.js"></script>
    <style>
        #player-network {
            width: 100vw;
            height: 100vh;
            background-color: rgba(0, 0, 0, 0.8);
        }
        #back-button {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }
        #clan-legend {
            position: absolute;
            bottom: 20px;
            left: 20px;
            background-color: rgba(0, 0, 0, 0.7);
            padding: 15px;
            border-radius: 5px;
            z-index: 1000;
            color: white;
            font-family: Tahoma, sans-serif;
        }
        .legend-item {
            display: flex;
            align-items: center;
            margin: 5px 0;
        }
        .color-box {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        #search-container {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            gap: 8px;
        }

        #node-search {
            padding: 8px 12px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background-color: rgba(0, 0, 0, 0.7);
            color: white;
            font-family: Tahoma, sans-serif;
            width: 200px;
        }

        #node-search::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        #node-search:focus {
            outline: none;
            border-color: var(--green-light-color);
            box-shadow: 0 0 5px rgba(28, 142, 120, 0.5);
        }

        #reset-view {
            padding: 8px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background-color: rgba(0, 0, 0, 0.7);
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        #reset-view:hover {
            background-color: rgba(28, 142, 120, 0.5);
            border-color: var(--green-light-color);
        }

        @keyframes nodePulse {
            0% { opacity: 1; }
            50% { opacity: 0.4; }
            100% { opacity: 1; }
        }

        .search-container {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .player-count {
            color: var(--text-color);
            font-family: Tahoma, sans-serif;
            font-size: 14px;
            line-height: 38px;  /* Match the search bar height */
            height: 38px;       /* Match the search bar height */
            display: flex;
            align-items: center;
        }

        #searchInput {
            /* Existing styles... */
            height: 38px;
        }
    </style>
</head>
<body>
    <a href="index.html" id="back-button" class="btn btn-cezero">Back to Leaderboard</a>
    <div id="search-container">
        <span class="player-count">Players: <span id="playerCount">0</span></span>
        <input type="text" id="node-search" placeholder="Search player...">
        <button id="reset-view" title="Reset view">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M15 8a7 7 0 0 1-13.656 2.343A1 1 0 0 1 2.657 9.657L8 4.314l5.343 5.343a1 1 0 1 1-1.414 1.414L8.586 7.728 4.927 11.386A5 5 0 1 0 13 8a1 1 0 1 1 2 0z"/>
            </svg>
        </button>
    </div>
    <div id="player-network"></div>
    <div id="clan-legend"></div>

    <script>
        // Define clan colors
        const clanColors = {
            'VD': '#4CAF50',    // Green
            'VW': '#2196F3',    // Blue
            'EQUE': '#9C27B0',  // Purple
            'HINQ': '#FF9800',  // Orange
            'ALMO': '#F44336',  // Red
            'VALE': '#00BCD4',  // Cyan
            'VLND': '#FFEB3B',  // Yellow
            'VoV': '#E91E63'    // Pink
        };

        // Function to normalize text by replacing Cyrillic/special characters with Latin equivalents
        function normalizeText(text) {
            return text
                .replace(/\u0410/g, 'A')    // Cyrillic А -> Latin A
                .replace(/\u0412/g, 'B')    // Cyrillic В -> Latin B
                .replace(/\u0415/g, 'E')    // Cyrillic Е -> Latin E
                .replace(/\u041C/g, 'M')    // Cyrillic М -> Latin M
                .replace(/\u041D/g, 'H')    // Cyrillic Н -> Latin H
                .replace(/\u041E/g, 'O')    // Cyrillic О -> Latin O
                .replace(/\u0420/g, 'P')    // Cyrillic Р -> Latin P
                .replace(/\u0421/g, 'C')    // Cyrillic С -> Latin C
                .replace(/\u0422/g, 'T')    // Cyrillic Т -> Latin T
                .replace(/\u0425/g, 'X')    // Cyrillic Х -> Latin X
                .replace(/\u0435/g, 'e')    // Cyrillic е -> Latin e
                .replace(/\u043E/g, 'o')    // Cyrillic о -> Latin o
                .replace(/\u0440/g, 'p');   // Cyrillic р -> Latin p
        }

        // Function to get clan from player name - checks all tags
        function getClanTag(name) {
            const matches = name.match(/\[(.*?)\]/g);
            if (!matches) return null;
            
            // Check each tag found
            for (let match of matches) {
                const tag = normalizeText(match.replace(/[\[\]]/g, '')).toUpperCase();
                const normalizedTag = Object.keys(clanColors).find(key => key.toUpperCase() === tag);
                if (normalizedTag) {
                    return normalizedTag;
                }
            }
            return null;
        }

        // Function to get color based on clan
        function getNodeColor(name) {
            const clan = getClanTag(name);
            return clan ? clanColors[clan] : '#808080'; // Gray for no clan
        }

        // Process the graph data to add colors
        const graphData = <?php echo $graph_data; ?>;
        graphData.nodes = graphData.nodes.map(node => ({
            ...node,
            color: {
                background: getNodeColor(node.label),
                border: '#ffffff'
            }
        }));
        
        const container = document.getElementById('player-network');
        const options = {
            nodes: {
                shape: 'dot',
                size: 15,
                scaling: {
                    min: 10,
                    max: 30,
                    customScalingFunction: function (min, max, total, value) {
                        if (max === min) return 0.5;
                        return (value - min) / (max - min);
                    }
                },
                font: {
                    size: 12,
                    face: 'Tahoma',
                    color: '#ffffff',
                    strokeWidth: 1,
                    strokeColor: '#000000'
                }
            },
            edges: {
                width: function(edge) {
                    return Math.log(edge.value) * 0.3;
                },
                color: { 
                    color: 'rgba(255, 255, 255, 0)',  // Start completely transparent
                    highlight: '#ffffff' 
                },
                smooth: {
                    enabled: false,
                    type: 'straight'
                },
                hidden: true  // Start with hidden edges
            },
            physics: {
                enabled: true,
                stabilization: {
                    enabled: true,
                    iterations: 1500,          // Increased iterations
                    updateInterval: 50
                },
                repulsion: {
                    centralGravity: 0.02,      // Further reduced
                    springLength: 800,         // Increased more
                    springConstant: 0.05,
                    nodeDistance: 700,         // Increased more
                    damping: 0.09,
                    avoidOverlap: 2.0          // Increased significantly
                },
                solver: 'repulsion',
                timestep: 0.3,                 // Reduced for more stable simulation
                minVelocity: 0.75,
                maxVelocity: 30,
                adaptiveTimestep: true
            },
            layout: {
                improvedLayout: true,
                randomSeed: 2,
                clusterThreshold: 150
            }
        };
        
        const network = new vis.Network(container, graphData, options);

        // Instead, just log when stabilization is done
        network.once("stabilizationIterationsDone", function() {
            console.log('Stabilization finished');
            createLegend();
        });

        // Keep the original positions for the drag animation
        let originalPositions = {};
        network.once("stabilized", function() {
            originalPositions = network.getPositions();
        });

        // Modified dragEnd to work with continuous physics
        network.on("dragEnd", function(params) {
            if (params.nodes.length > 0) {
                const nodeId = params.nodes[0];
                const originalPos = originalPositions[nodeId];
                if (originalPos) {
                    // Add some force to the node to move it towards its original position
                    const position = network.getPositions([nodeId])[nodeId];
                    const dx = originalPos.x - position.x;
                    const dy = originalPos.y - position.y;
                    network.body.data.nodes.get(nodeId).vx = dx * 0.1;
                    network.body.data.nodes.get(nodeId).vy = dy * 0.1;
                }
            }
        });

        network.on("stabilizationProgress", function(params) {
            console.log(params.iterations + ' / ' + params.total);
        });

        network.on("hoverNode", function (params) {
            const node = graphData.nodes.find(n => n.id === params.node);
            if (node) {
                network.canvas.body.container.title = `${node.label}\nMMR: ${node.value}`;
            }
        });

        network.on("blurNode", function (params) {
            network.canvas.body.container.title = '';
        });

        function createLegend() {
            const legend = document.getElementById('clan-legend');
            const title = document.createElement('div');
            title.style.marginBottom = '10px';
            title.style.fontWeight = 'bold';
            title.textContent = 'Clan Colors';
            legend.appendChild(title);

            // Sort clan names alphabetically
            const sortedClans = Object.entries(clanColors).sort((a, b) => a[0].localeCompare(b[0]));

            for (const [clan, color] of sortedClans) {
                const item = document.createElement('div');
                item.className = 'legend-item';
                
                const colorBox = document.createElement('div');
                colorBox.className = 'color-box';
                colorBox.style.backgroundColor = color;
                
                const label = document.createElement('span');
                label.textContent = `[${clan}]`;
                
                item.appendChild(colorBox);
                item.appendChild(label);
                legend.appendChild(item);
            }

            // Add "No Clan" entry
            const noClanItem = document.createElement('div');
            noClanItem.className = 'legend-item';
            
            const noClanBox = document.createElement('div');
            noClanBox.className = 'color-box';
            noClanBox.style.backgroundColor = '#808080';
            
            const noClanLabel = document.createElement('span');
            noClanLabel.textContent = 'No Clan';
            
            noClanItem.appendChild(noClanBox);
            noClanItem.appendChild(noClanLabel);
            legend.appendChild(noClanItem);
        }

        let searchTimeout = null;
        let focusedNode = null;

        // Add this style for the border animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes borderPulse {
                0% { border-width: 2px; }
                50% { border-width: 8px; }
                100% { border-width: 2px; }
            }
        `;
        document.head.appendChild(style);

        document.getElementById('node-search').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            // Reset previous focused node
            if (focusedNode !== null) {
                network.body.data.nodes.update({
                    id: focusedNode,
                    borderWidth: 1,
                    border: undefined,
                    size: undefined
                });
                focusedNode = null;
            }

            searchTimeout = setTimeout(() => {
                if (searchTerm) {
                    const matchingNode = network.body.data.nodes.get().find(node => 
                        node.label.toLowerCase().includes(searchTerm)
                    );

                    if (matchingNode) {
                        focusedNode = matchingNode.id;

                        // Update node with highlight effects
                        network.body.data.nodes.update({
                            id: matchingNode.id,
                            borderWidth: 4,
                            border: {
                                color: '#000000',
                                width: 4
                            },
                            size: matchingNode.size * 1.3  // Slightly larger
                        });

                        // Focus the network on this node
                        network.focus(matchingNode.id, {
                            scale: 1.2,
                            animation: {
                                duration: 500,
                                easingFunction: 'easeInOutQuad'
                            }
                        });
                    }
                }
            }, 300);
        });

        // Reset focus when clicking outside or using reset button
        function resetNodeFocus() {
            if (focusedNode !== null) {
                network.body.data.nodes.update({
                    id: focusedNode,
                    borderWidth: 1,
                    border: undefined,
                    size: undefined
                });
                focusedNode = null;
            }
        }

        network.on("click", function(params) {
            if (params.nodes.length === 0) {
                document.getElementById('node-search').value = '';
                resetNodeFocus();
            }
        });

        // Update the reset-view button handler
        document.getElementById('reset-view').addEventListener('click', function() {
            document.getElementById('node-search').value = '';
            resetNodeFocus();
            network.fit({
                animation: {
                    duration: 1000,
                    easingFunction: 'easeInOutQuad'
                }
            });
        });

        // After creating the network and loading the data
        const totalPlayers = network.body.data.nodes.length;
        document.getElementById('playerCount').textContent = totalPlayers.toLocaleString();

        // Add performance improvements for zooming
        network.on("zoom", function(params) {
            // Only handle node scaling on zoom, don't touch edges
            const scale = network.getScale();
            if (scale > 1.5) {
                network.body.data.nodes.update(graphData.nodes.map(node => ({
                    id: node.id,
                    font: { size: 0 }
                })));
            } else {
                network.body.data.nodes.update(graphData.nodes.map(node => ({
                    id: node.id,
                    font: { size: 12 }
                })));
            }
        });

        // Add clustering for better performance with many nodes
        const clusterOptions = {
            processProperties: function(clusterOptions, childNodes) {
                clusterOptions.label = '[' + childNodes.length + ' nodes]';
                return clusterOptions;
            },
            clusterNodeProperties: {
                borderWidth: 3,
                shape: 'dot',
                font: { size: 14 }
            }
        };

        // Cluster nodes when zoomed out significantly
        network.on("zoom", function() {
            const scale = network.getScale();
            if (scale < 0.5) {
                network.setOptions({ physics: { enabled: false } });
            } else {
                network.setOptions({ physics: { enabled: true } });
            }
        });

        // Improve initial loading
        network.once("stabilizationIterationsDone", function() {
            // Disable physics after initial stabilization
            network.setOptions({ physics: { enabled: false } });
            
            // Re-enable on user interaction
            network.on("dragStart", function() {
                network.setOptions({ physics: { enabled: true } });
            });
            
            network.on("dragEnd", function() {
                setTimeout(function() {
                    network.setOptions({ physics: { enabled: false } });
                }, 1000);
            });
        });

        // Modified click handler
        let selectedNode = null;
        network.on("click", function(params) {
            if (params.nodes.length > 0) {
                const nodeId = params.nodes[0];
                if (selectedNode === nodeId) {
                    // Hide edges when clicking same node
                    network.body.data.edges.update(graphData.edges.map(edge => ({
                        id: edge.id,
                        hidden: true
                    })));
                    selectedNode = null;
                } else {
                    // Show only connected edges for new node
                    const connectedEdges = network.getConnectedEdges(nodeId);
                    network.body.data.edges.update(graphData.edges.map(edge => ({
                        id: edge.id,
                        hidden: !connectedEdges.includes(edge.id)
                    })));
                    selectedNode = nodeId;
                }
            } else {
                // Hide all edges when clicking background
                network.body.data.edges.update(graphData.edges.map(edge => ({
                    id: edge.id,
                    hidden: true
                })));
                selectedNode = null;
            }
        });

        // Add this to ensure edges stay hidden on initial load
        network.once("stabilizationIterationsDone", function() {
            network.body.data.edges.update(graphData.edges.map(edge => ({
                id: edge.id,
                hidden: true
            })));
        });

        // Add force-directed positioning
        network.on("stabilizationProgress", function(params) {
            // During stabilization, add extra repulsion between overlapping nodes
            const positions = network.getPositions();
            const nodes = network.body.nodes;
            
            for (let nodeId in nodes) {
                const node1 = nodes[nodeId];
                for (let otherId in nodes) {
                    if (nodeId !== otherId) {
                        const node2 = nodes[otherId];
                        const dx = node1.x - node2.x;
                        const dy = node1.y - node2.y;
                        const distance = Math.sqrt(dx * dx + dy * dy);
                        
                        if (distance < 100) {  // If nodes are too close
                            const angle = Math.atan2(dy, dx);
                            const force = (100 - distance) * 0.5;
                            node1.x += Math.cos(angle) * force;
                            node1.y += Math.sin(angle) * force;
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
