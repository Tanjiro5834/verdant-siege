🌿 Verdant Siege
A real-time Plants vs Zombies inspired strategy game.

🎮 Overview
Competitive multiplayer strategy game where one player defends as Plants and the other attacks as Zombies.

Quick Features:

⚡ Real-time multiplayer

🏆 Ranked & Casual modes

👥 Social features (friends, chat)

🤖 AI assistance for resource collection

📊 Match replay system

🔒 Server-authoritative anti-cheat

🛠️ Tech Stack
Backend: PHP, MySQL, Redis, WebSocket
Frontend: HTML, CSS, JavaScript (ES6+), Tailwind
Infrastructure: Docker, Git, GitHub/GitHub Actions

🚀 Quick Start
Docker (Recommended)
bash
git clone https://github.com/yourusername/verdant-siege.git
cd verdant-siege
docker-compose up -d
# Open http://localhost:3000
Manual Setup
Backend:

bash
cd backend
./mvnw spring-boot:run
Frontend:

bash
cd frontend
npm install
npm start
🎯 Core Gameplay
Modes
Ranked: Competitive ELO-based, no AI help

Casual: Practice mode with AI assistance

Resource System
Plants: ☀️ Sun (passive + manual collection)

Zombies: ⚡ Energy (passive generation)

Win Conditions
Plants: Survive all zombie waves

Zombies: Reach the plant base

Unit Counter System
text
Ranged beats Melee → Melee beats Tank → Tank beats Ranged
Special units have unique counter interactions
📡 API Endpoints
Auth
text
POST /api/auth/register  - Create account
POST /api/auth/login     - Login
User
text
GET  /api/users/{id}     - Get profile
PUT  /api/users/{id}     - Update profile
GET  /api/users/{id}/stats - Get stats
Matchmaking
text
POST /api/matchmaking/queue - Join queue
GET  /api/matchmaking/status - Check status
Social
text
GET  /api/friends        - Friends list
POST /api/friends/request - Send request
WebSocket
text
/ws/game         - Game connection
/ws/game/chat    - Chat messages
/ws/game/actions - Player actions
🔒 Security
JWT authentication

BCrypt password hashing

Rate limiting

Server-authoritative game logic

Anti-cheat detection system

🤝 Contributing
Fork the repo

Create feature branch (git checkout -b feature/amazing)

Commit changes (git commit -m 'Add amazing')

Push (git push origin feature/amazing)

Open Pull Request

📄 License
MIT License - see LICENSE file

Made by Nathaniel Coronacion

