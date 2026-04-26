# Nomad Hotspot Project Context

This project is a Raspberry Pi-based Travel Router with a web interface. It is designed to run on Raspberry Pi OS (Lite) and uses PHP for both its background logic and web server.

## Architectural Overview

### 1. Persistence & SD Card Protection
To minimize wear on the Raspberry Pi's SD card, the project uses a "disk-less" state management approach:
- **Shared Memory:** The active system state is stored in a serialized array at `/dev/shm/state.serialize`.
- **TMPFS:** `/dev/shm/` is a RAM-backed filesystem, ensuring no physical disk writes for frequent state updates.
- **State Exchange:** The background agent (`agent.php`) writes to this file, and the web server (`web/index.php`) reads from it to display data.

### 2. Core Components
- **Background Agent (`agent.php`):** The primary daemon that runs in a loop. It monitors network interfaces, verifies internet connectivity (DNS and captive portals), manages system services, and ensures configuration files in `conf/` are synchronized with their system counterparts (e.g., in `/etc/`).
- **Web Interface (`web/`):** 
    - `index.php`: Acts as the router/controller for the web UI.
    - `web.php`: Contains the UI component generation functions (HTML/CSS).
    - `web.css`: Provides the styling.
    - Uses a built-in PHP web server started by the agent.
- **Library (`functions.php`):** The core collection of utility functions used by both the agent and the web server for system interaction, parsing, and state management.

### 3. Configuration & Services
- **Configuration Storage:** Managed in the `conf/` directory. Templates are found in `templates/`.
- **System Integration:** Interacts with `NetworkManager` (`nmcli`), `dnsmasq`, `openvpn`, and `iptables`.
- **Service Management:** The agent monitors and restarts these services based on configuration changes or system state.

### 4. Hardware Support
- **PWM Control:** Located in `rpi-hardware-pwm/`, used for hardware-level control such as dimming the HyperPixel4 screen.
- **Kiosk Mode:** Scripts in `install/kiosk/` and `setupkiosk.sh` for setting up a local touchscreen UI.

### 5. Development & Testing
- **`test.php`:** A root-level script that loads core includes for debugging and testing functions in an isolated environment.
- **`portaltest.php`:** Specifically for testing captive portal detection logic.
- **`procmon.sh`:** A utility for monitoring the agent and web server processes.

## Engineering Guidelines

### State Management
- **Never** add logic that frequently writes to the SD card. 
- Always use `read_tmpfs()` and `write_tmpfs()` for volatile state.
- If adding new state keys, ensure they are initialized in `agent.php` and handled gracefully in the web UI.

### System Interactions
- Use the existing wrappers in `functions.php` for shell commands.
- Adhere to the `msglog()` convention for logging, which redirects to syslog and the internal state for web display.

### Web UI
- The UI is designed to be lightweight and fast-refreshing.
- Follow the component-based approach in `web/web.php` for any new UI elements.
- Ensure any new routes are added to the `switch` block in `web/index.php`.
