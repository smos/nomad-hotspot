# Nomad Hotspot Reference & Architecture Inventory

This document serves as an architectural index and function inventory for Nomad Hotspot. It is designed to allow agents and developers to quickly locate functions, understand data flows, and navigate the `$state` schema.

---

## 1. System Architecture & Data Flow

```
                      +-------------------+
                      |   System & OS     |
                      | (nmcli, iptables, |
                      | dnsmasq, openvpn) |
                      +---------+---------+
                                |
                   (poll / inspect / reconfigure)
                                v
+---------------------------------------------------------------+
|                      agent.php (Daemon)                       |
|  - Loops continuously every $looptimer seconds                |
|  - Polls interface state, traffic counters, processes         |
|  - Tests connectivity (msft connect test, DNS ping, GW ping)  |
|  - Syncs conf/ templates to system /etc/ when changed         |
+-------------------------------+-------------------------------+
                                |
               write_tmpfs()    | Serialized PHP array
                                v
             [/dev/shm/state.serialize (RAM/TMPFS)]
                                ^
               read_tmpfs()     | Zero SD card wear
                                |
+-------------------------------+-------------------------------+
|                    Web Server (web/index.php)                 |
|  - Built-in PHP server started on port (default 80)           |
|  - Routes requests via URI switch                             |
|  - Reads state snapshot from /dev/shm/state.serialize         |
|  - Renders UI / AJAX fragments via web/web.php                |
+---------------------------------------------------------------+
```

---

## 2. `$state` Data Dictionary (`/dev/shm/state.serialize`)

The `$state` array is the shared memory bus across the background agent and the web interface:

| Top-Level Key | Type | Description | Producer |
| :--- | :--- | :--- | :--- |
| `self` | `array` | Daemon heartbeat metadata (`start`, `time`, `itteration`) | `agent.php` |
| `config` | `array` | Active JSON configuration from `conf/config.json` (`port`, `openvpn`, `screensaver`) | `agent.php` / `web.php` |
| `cfgfile` | `string` | Absolute path to active configuration file | `agent.php` |
| `internet` | `array` | Internet connectivity status, latency, public WAN IP, and ASN/ISP info | `agent.php` / `functions.php` |
| `captive` | `array` | History array mapping timestamp => status (`OK`, `PORTAL`, `DNSERR`, `TIMEOUT`) | `functions.php:working_captive()` |
| `dns` | `array` | Upstream DNS servers (`dns4`, `dns6`) from DHCP leases | `functions.php:parse_dhcp_nameservers()` |
| `if` | `array` | Interface map keyed by interface name (`eth0`, `wlan0`, `wlan1`), containing IP, link flags, wireless quality, traffic counters | `functions.php:process_if_changes()` |
| `traffic` | `array` | Historical rolling traffic counters (rx/tx bandwidth) per interface | `functions.php:calculate_traffic()` |
| `clients` | `array` | List of connected DHCP clients (`time`, `mac`, `ip4`, `hostname`) | `functions.php:parse_dnsmasq_leases()` |
| `leases` | `array` | DHCP leases keyed by interface | `agent.php` |
| `proc` | `array` | Monitored processes status/counts (`dnsmasq`, `openvpn`, `php`) | `functions.php:check_procs()` |
| `devices` | `array` | Physical hardware info per interface (`driver`, `firmware`, `bus_info`) | `functions.php:fetch_device_info()` |
| `lldp` | `array` | LLDP neighbor discovery data | `functions.php:fetch_lldp_neighbors()` |
| `log` | `array` | Memory-buffered log messages keyed by emitting component | `functions.php:msglog()` |

### Detailed `$state['internet']` Schema
```php
$state['internet'] = [
    'dns' => 'OK',            // 'OK' or 'NOK' (result of working_dns)
    'captive' => 'OK',        // 'OK', 'PORTAL', 'DNSERR', 'TIMEOUT' (msft connect test)
    'wanip' => '80.113.60.x', // Public IPv4 fetched from ipecho.net
    'isp' => [                // ASN & ISP info from whois/cymru
        'descr' => 'VODAFONE_ZIGGO',
        'route' => '80.113.0.0/17',
        'asnum' => 'AS33915',
    ],
    'latency' => [
        'ping' => [
            '10.63.0.1' => 999, // Gateway IP => latency ms (999 = timeout)
        ],
        'dnsping' => [
            '212.54.40.25' => 35, // DNS server IP => dnsping latency ms
            '212.54.44.54' => 22,
        ]
    ]
];
```

---

## 3. Function Inventory: `functions.php`

### State & Memory Management
- `create_shm($shm_size)` - Creates a SysV shared memory segment.
- `write_shm($shm_id, $state)` / `read_shm($shm_id, $shm_size)` - SysV SHM read/write operations.
- `write_tmpfs($tmpfsurl, $state)` - Serializes `$state` and writes to RAM disk (`/dev/shm/state.serialize`).
- `read_tmpfs($tmpfsurl)` - Unserializes `$state` from RAM disk.
- `msglog($process, $msg)` - Writes log messages to syslog and persists in `$state['log']`.

### Network & Routing
- `fetch_default_route_gw()` - Uses `ip -j route show default` (IPv4 & IPv6) to fetch gateway addresses.
- `find_wan_interface($state)` - Identifies the active WAN interface from the default route.
- `route_add($ip, $gwip)` - Adds an explicit kernel route.
- `lookup_neighbor($address)` - Parses `ip -j neigh` to resolve MAC/device for an IP address.
- `lookup_mac_address($ip)` - Looks up ARP neighbor entry for a target IP.
- `lookup_oui($mac)` - Resolves hardware vendor from IEEE OUI database.
- `if_state($iflist, $name)` / `if_address($iflist, $ifname)` / `if_prefix($iflist, $ifname)` - Interface helpers for IP and link status.
- `process_if_changes($ifstate, $iflist, $ifname)` - Detects interface transitions and updates interface telemetry.
- `calculate_traffic($ifstate, $iflist, $ifname)` / `process_traffic_hist($old, $stats)` - Computes bandwidth delta and sliding history.

### Wireless & NetworkManager (NMCLI)
- `fetch_wlan_interfaces()` - Returns all detected wireless interfaces.
- `fetch_ap_if($state)` / `fetch_wi_client_if($state)` - Distinguishes between AP interface and client uplink interface.
- `iw_info($ifstate, $ifname)` / `iwconfig_info($ifstate, $ifname)` - Extracts frequency, SSID, signal level, bit rate.
- `list_nmcli_networks($state, $ifname)` - Scans available Wi-Fi networks using `nmcli -t -f ... device wifi list`.
- `clean_nmcli_list($nmcli_networks)` - Deduplicates and formats Wi-Fi scan results.
- `list_wifi_cons()` - Lists configured NetworkManager Wi-Fi connection profiles.
- `delete_wifi_con($con)` - Deletes a NetworkManager connection profile.
- `fetch_freq_list($iface, $band)` - Returns supported frequencies/channels for an interface.

### Connectivity, Latency & Captive Portal
- `check_gw_latency($state)` - Pings default IPv4/IPv6 gateways.
- `check_dns_latency($state)` - Performs `dnsping` against upstream nameservers.
- `ping($address)` - Executes `ping -U -W1 -c1`, returns ms latency (`999` on timeout).
- `dnsping($state, $server)` - Queries `dnsping` for `www.microsoftconnecttest.com`, returns ms latency (`999` on timeout).
- `working_dns($dns)` - Validates DNS resolution for `dns.google`.
- `working_captive($captive)` - Performs captive portal check against Microsoft NCSI endpoint.
- `check_msft_connect($url)` - Tests HTTP connect to `http://www.msftconnecttest.com/connecttest.txt`.
- `parse_portal_page($url)` - Attempts automated HTML form parsing and submission for captive portals.
- `simple_web_request($url, $method, $vars)` - Curl wrapper for portal authentication.
- `fetch_as_info($state, $ip)` - Performs DNS/whois lookups against Team Cymru for public ASN metadata.

### Configuration & Service Management
- `read_config($cfgfile)` / `save_config($cfgfile, $config)` - Reads/writes `conf/config.json`.
- `update_cfgmap($cfgmap)` / `update_procmap($procmap)` - Maps template configuration files to `/etc/` destination paths and monitored services.
- `compare_cfg_files($dir)` / `process_cfg_changes($chglist)` - Detects local changes in `conf/` and synchronizes to system paths (`/etc/`).
- `start_service($file)` / `stop_service($file)` / `restart_service($file)` - Manages systemd services mapped to config files.
- `check_procs($procmap)` / `check_proc($file)` - Checks process counts for core daemons (`dnsmasq`, `openvpn`, `php`).
- `parse_dhcp_nameservers($state)` - Extracts active nameservers from interface DHCP configurations.
- `parse_dnsmasq_leases()` - Reads `/var/lib/misc/dnsmasq.leases` to populate client table.
- `is_vpn_enabled($state)` - Checks if VPN is active in configuration (`vpn_enable` or `openvpn`).
- `get_vpn_type($state)` - Returns configured VPN provider (`openvpn` or `tailscale`).
- `is_tailscale_installed()` - Checks if `tailscale` binary is available.
- `tailscale_status()` - Queries `tailscale status --json`.
- `tailscale_login_url()` - Retrieves interactive web authentication link when device needs login.
- `tailscale_up($state)` - Brings up Tailscale with optional `--exit-node` and `--exit-node-allow-lan-access`.
- `tailscale_down($state)` - Disconnects Tailscale tunnel.
- `start_vpn($state)` / `stop_vpn($state)` - Unified VPN start/stop dispatching to OpenVPN or Tailscale based on active configuration and captive portal state.

---

## 4. Web Component Inventory: `web/web.php` & `web/index.php`

### Route Controller (`web/index.php`)
- `/` or `/status` - Main dashboard (renders status cards, traffic, clients, refresh scripts).
- `/interfaces` - Interface card listing (`html_interfaces`).
- `/wilist` / `/wilistjson` - Wi-Fi client network scanner and connect UI.
- `/clients` - DHCP connected clients table (`html_clients`).
- `/connectivity` / `/connectivityscreensaver` / `/connectivityextra` - Network connectivity status icons and details.
- `/cfgif`, `/cfgwiap`, `/cfgwiclient`, `/cfgvpn` (`/cfgovpn`) - Configuration editors.
- `/screensaver` - Screensaver view (switches between `status`, `wopr`, and `time` based on config).
- `/screensavermenu` - Interactive menu for touchscreen kiosk.
- `/json` - Outputs raw `$state` as JSON.

### UI Rendering Functions (`web/web.php`)
- `html_status_internet($state, $defif, $icon)` - Renders Internet status:
  - When `$icon === true`: Displays clickable globe icon (`globeokc.png`, `globenokc.png`, `globeunkc.png`).
  - When `$icon === false`: Renders WAN IP, gateway ping latency (with fallback to DNS latency if gateway ping times out), and ASN information.
- `html_status_dns($state, $icon)` - Renders DNS status icon or detailed nameserver latency table (`html_list_dns`).
- `html_status_uplink($state, $defif, $icon)` - Renders Wi-Fi link quality bar or Ethernet link status.
- `html_status_vpn($state, $icon)` - Renders VPN status icon and interface state for both OpenVPN (`tun0`) and Tailscale (`tailscale0`), including exit node status.
- `config_vpn($state)` - Unified VPN management interface: toggles VPN on/off, switches provider between OpenVPN and Tailscale, displays clickable Tailscale login/activation link, and configures exit node routing with LAN access.
- `html_connectivity_screensaver($state)` - Screensaver view displaying VPN, Internet, DNS, and Uplink icons in sequence.
- `html_connectivity_extra($state)` - Detailed connectivity view used in status dashboard.
- `html_wopr($show_menu)` / `wopr_json($state)` - WarGames WOPR-style retro matrix screensaver.
- `html_time_screensaver($state, $show_menu)` - Large digital clock screensaver.
- `latency_to_color($num)` - Converts latency (ms) to CSS class / icon suffix:
  - `0` => `nac` (blue / N/A)
  - `999` => `unkc` (light grey / timeout)
  - `< 100` => `okc` (green)
  - `< 200` => `noticec` (yellow)
  - `< 300` => `warnc` (orange)
  - `> 500` => `nokc` (red)
- `scale_to_color($num)` - Converts percentage (0-100) to `nokc`, `warnc`, `noticec`, `okc`.

---

## 5. Development & Testing Tools

- **`test.php`:** Root debugging script that includes `functions.php`, `web/web.php`, and loads `$state` via `read_tmpfs($tmpfsurl)`. Use this script to test functions without modifying running daemons.
- **`portaltest.php`:** Standalone test harness for captive portal probing and authentication.
- **`procmon.sh`:** Process monitor script checking the agent daemon and built-in web server.
