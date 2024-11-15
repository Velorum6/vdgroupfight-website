# VD Groupfight Website

A real-time statistics tracking website for the Vestmar Dominion [VD] Ranked Groupfight server in Mount & Blade II: Bannerlord.

This is a live project that you can check at https://vdgroupfight.com

## Code structure

### 🏆 Main Leaderboard
- index.html
- get_player_stats.php

### 👤 Player Profiles
- player.php

### 🎮 Round Details
- round.php

### 🌐 Player Network Map
- player_map.php

## Technical Stack

### Frontend
- HTML5
- CSS3 with Bootstrap 5
- Vanilla JavaScript
- Chart.js for statistics visualization
- vis.js for network visualization

### Backend
- PHP
- SQLite Database
- JSON-based caching system

## Setup

1. Clone the repository

```bash
git clone https://github.com/yourusername/vdgroupfight-website.git
```

2. Create and configure `.env` file:

```env
DB_SERVER_IP=your_ip_here
DB_SERVER_PORT=your_port_here
```

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For support, please:
1. Check existing GitHub issues
2. Create a new issue with detailed information
3. Join our [Discord](https://discord.gg/R5cK34QFRM)
