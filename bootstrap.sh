#!/bin/bash

set -e

# Determine if sudo is available and usable
if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
    SUDO="sudo"
else
    SUDO=""
fi

# Install sudo if not available
if ! command -v sudo >/dev/null 2>&1; then
    echo "Installing sudo..."
    $SUDO apt-get -qq update
    $SUDO apt-get -qq install -y sudo
    SUDO="sudo"
fi

# Add current user to sudo group
CURRENT_USER=$(whoami)
$SUDO usermod -aG sudo "$CURRENT_USER"

# Configure passwordless sudo
echo "$CURRENT_USER ALL=(ALL) NOPASSWD:ALL" | $SUDO tee /etc/sudoers.d/$CURRENT_USER
$SUDO chmod 440 /etc/sudoers.d/$CURRENT_USER

# Install Ansible
if ! command -v ansible >/dev/null 2>&1; then
	$SUDO apt-get -qq update && $SUDO apt-get -qq install -y ansible
fi

# Create Ansible playbook
cat <<EOF > bootstrap.yaml
---
- name: Bootstrap Nomad-Hotspot
  hosts: localhost

  vars:
    # Base directory for the nomad-hotspot application within the user's home
    app_base_dir: "{{ ansible_env.HOME }}/nomad-hotspot"
    # Directory where original config backups will be stored
    backup_dir: "{{ app_base_dir }}/orig"
    # Directory containing template files on the remote server
    templates_source_dir: "{{ app_base_dir }}/templates"
    # Directory where configuration files will be deployed
    config_destination_dir: "{{ app_base_dir }}/conf"
      
    config_files_to_backup:
      - /etc/dnsmasq.conf
      - /etc/wpa_supplicant/wpa_supplicant.conf
      - /etc/NetworkManager/system-connections/prefconfigured.nmconnection

  tasks:
    - name: Install required packages
      become: yes
      apt:
        name:
          - git
          - dnsmasq
          - arping
          - php-cli
          - openvpn
          - screen
          - php-curl
          - iptables-persistent
          - stunnel4
          - lldpd
          - whois
          - dnsdiag
          - dnsmasq
        state: present
        update_cache: yes

    - name: Clone nomad-hotspot repository
      git:
        repo: https://github.com/smos/nomad-hotspot.git
        dest: "{{ ansible_env.HOME }}/nomad-hotspot"
      environment:
        - GIT_SSL_NO_VERIFY: "true"
    
    - name: Ensure backup directory exists
      ansible.builtin.file:
        path: "{{ backup_dir }}"
        state: directory
        mode: '0755'
        owner: "{{ ansible_user_id }}"
        group: "{{ ansible_user_gid }}"

    - name: Check if configuration files exist before backing up
      become: yes
      ansible.builtin.stat:
        path: "{{ item }}"
      loop: "{{ config_files_to_backup }}"
      register: config_files_status

    - name: Backup configuration files if they exist
      ansible.builtin.copy:
        src: "{{ item.stat.path }}"
        dest: "{{ backup_dir }}/"
        remote_src: yes # Source is on the remote host
        owner: "{{ ansible_user_id }}"
        group: "{{ ansible_user_gid }}"
        mode: '0644'
      loop: "{{ config_files_status.results }}"
      when: item.stat.exists
      notify: Backup completed # Changed to a static handler name
      
    - name: Ensure configuration destination directory exists
      ansible.builtin.file:
        path: "{{ config_destination_dir }}"
        state: directory
        mode: '0755'
        owner: "{{ ansible_user_id }}"
        group: "{{ ansible_user_gid }}"

    - name: Copy template files to config directory (without overwriting)
      ansible.builtin.copy:
        src: "{{ templates_source_dir }}/" # The trailing slash copies contents of the source directory
        dest: "{{ config_destination_dir }}/"
        owner: "{{ ansible_user_id }}"
        group: "{{ ansible_user_gid }}"
        mode: '0644' # Set permissions for the copied files
        force: no # This is key: prevents overwriting existing files
      notify: Template deployment completed

    # --- New tasks for WLAN channel detection and hostapd.conf modification ---
    - name: Get highest available WLAN channel on wlan0
      ansible.builtin.shell: "iwlist wlan0 freq | awk '/Channel [0-9]+ :/ { print $2}' | sort -ru | head -n1"
      # Removed 'args: warn: no' to avoid unsupported parameter error
      register: highest_channel_output
      changed_when: false # This task only gathers information, doesn't change state
      ignore_errors: true # Do not fail the playbook if iwlist command fails (e.g., no wlan0)

    - name: Set fact if channel needs adjustment
      ansible.builtin.set_fact:
        should_adjust_channel: "{{ highest_channel_output.stdout is defined and highest_channel_output.stdout | length > 0 and highest_channel_output.stdout | int < 32 }}"
      when: highest_channel_output is defined and highest_channel_output.stdout is defined # Ensure highest_channel_output was registered and has stdout

    - name: Conditionally update hostapd.conf channel to 11 if highest channel is < 32
      ansible.builtin.replace:
        path: "{{ config_destination_dir }}/hostapd.conf"
        regexp: '^(channel=)36$' # Matches 'channel=36' at the start of the line
        replace: '\111' # Replaces with 'channel=11'
      when: should_adjust_channel | default(false) # Only run if channel adjustment is needed
      notify: hostapd.conf updated

    - name: Conditionally update hostapd.conf hw_mode to 'g' if highest channel is < 32
      ansible.builtin.replace:
        path: "{{ config_destination_dir }}/hostapd.conf"
        regexp: '^(hw_mode=)a$' # Matches 'hw_mode=a' at the start of the line
        replace: '\1g' # Replaces with 'hw_mode=g'
      when: should_adjust_channel | default(false) # Only run if channel adjustment is needed
      notify: hostapd.conf updated

    # --- Tasks to unmask and enable common network services ---
    - name: Unmask and enable hostapd service
      become: yes
      ansible.builtin.systemd:
        name: hostapd
        enabled: yes
        masked: no
      notify: Services managed

    - name: Unmask and enable dnsmasq service
      become: yes
      ansible.builtin.systemd:
        name: dnsmasq
        enabled: yes
        masked: no
      notify: Services managed

    - name: Unmask and enable dhcpcd service
      become: yes
      ansible.builtin.systemd:
        name: dhcpcd
        enabled: yes
        masked: no
      notify: Services managed

    # --- Tasks for nomad-hotspot.service ---
    - name: Replace 'pi' user with current Ansible user in nomad-hotspot.service file
      ansible.builtin.replace:
        path: "{{ app_base_dir }}/install/nomad-hotspot.service"
        regexp: '^User=pi$'
        replace: 'User={{ ansible_user_id }}'
        # Important: Assumes 'User=pi' exists in the service file.
        # If the line might not exist or be different, a more complex regex
        # or template module might be needed.

    - name: Install nomad-hotspot.service file
      become: yes
      ansible.builtin.copy:
        src: "{{ app_base_dir }}/install/nomad-hotspot.service"
        dest: "/etc/systemd/system/nomad-hotspot.service"
        owner: root
        group: root
        mode: '0644'
        remote_src: yes # Source file is on the remote host
      notify: Reload systemd daemon

    - name: Unmask, enable, and start nomad-hotspot service
      become: yes
      ansible.builtin.systemd:
        name: nomad-hotspot.service
        enabled: yes
        masked: no
        state: started # Ensures the service is started after enabling
      notify: Services managed

    # --- New tasks for dhcpcd.conf and IP forwarding ---
    - name: Replace 'pi' user with current Ansible user in dhcpcd.conf
      ansible.builtin.replace:
        path: "{{ config_destination_dir }}/dhcpcd.conf"
        regexp: '(^option user_id )\bpi\b' # Matches 'option user_id pi' ensuring 'pi' is a whole word
        replace: '\1{{ ansible_user_id }}'
        # This regex attempts to be more robust by looking for 'pi' as a word
        # after 'option user_id '. Adjust if your dhcpcd.conf line is different.

    - name: Enable IP forwarding in sysctl.conf
      become: yes    
      ansible.builtin.sysctl:
        name: net.ipv4.ip_forward
        value: '1'
        state: present
        reload: yes # Apply the change immediately
      notify: IP forwarding enabled

    # --- NetworkManager Configuration ---
    - name: Stop NetworkManager before making changes
      become: yes
      ansible.builtin.systemd:
        name: NetworkManager
        state: stopped

    - name: Remove pre-existing NetworkManager connection
      become: yes
      ansible.builtin.file:
        path: /etc/NetworkManager/system-connections/prefconfigured.nmconnection
        state: absent

    - name: Copy new NetworkManager connection profiles
      become: yes
      ansible.builtin.copy:
        src: "{{ item }}"
        dest: "/etc/NetworkManager/system-connections/"
        owner: root
        group: root
        mode: '0600'
      loop: "{{ query('fileglob', config_destination_dir + '/*.nmconnection') }}"
      notify: NetworkManager reconfigured

    - name: Restart NetworkManager to apply new connections
      become: yes
      ansible.builtin.systemd:
        name: NetworkManager
        state: restarted
        enabled: yes
        masked: no

    # --- IPTables Configuration ---
    - name: Create iptables configuration directory
      become: yes
      ansible.builtin.file:
        path: /etc/iptables
        state: directory
        owner: root
        group: root
        mode: '0755'

    - name: Copy iptables v4 rules
      become: yes
      ansible.builtin.copy:
        src: "{{ config_destination_dir }}/iptables.v4"
        dest: /etc/iptables/rules.v4
        owner: root
        group: root
        mode: '0644'
      notify: Reload persistent firewall rules

    - name: Copy iptables v6 rules
      become: yes
      ansible.builtin.copy:
        src: "{{ config_destination_dir }}/iptables.v6"
        dest: /etc/iptables/rules.v6
        owner: root
        group: root
        mode: '0644'
      notify: Reload persistent firewall rules

  handlers:
    - name: Backup successful for {{ item.item.path }}
      ansible.builtin.debug:
        msg: "Successfully backed up {{ item.item.path }} to {{ backup_dir }}"

    - name: Template deployment completed
      ansible.builtin.debug:
        msg: "Template files copied to {{ config_destination_dir }} without overwriting existing files."

    - name: hostapd.conf updated
      ansible.builtin.debug:
        msg: "hostapd.conf updated to channel 11 and hw_mode g due to low available 5GHz channels."

    - name: Reload systemd daemon
      ansible.builtin.systemd:
        daemon_reload: yes # Crucial after adding/modifying service files
      listen: "Reload systemd daemon"

    - name: Services managed
      ansible.builtin.debug:
        msg: "Required services have been unmasked, enabled, and started."

    - name: IP forwarding enabled
      ansible.builtin.debug:
        msg: "IP forwarding has been enabled and applied."

    - name: NetworkManager reconfigured
      ansible.builtin.debug:
        msg: "Copied new NetworkManager connection profiles."

    - name: Reload persistent firewall rules
      become: yes
      ansible.builtin.systemd:
        name: netfilter-persistent
        state: restarted

EOF

# Run Ansible playbook
ansible-playbook -v bootstrap.yaml

echo "Enable PCIe tune, thnx Jeff Geerling"
sudo sed -i 's/fsck.repair=yes rootwait/fsck.repair=yes pci=pcie_bus_perf rootwait/g' /boot/cmdline.txt

echo "Load some basic IPtables rules for forwarding"
#sudo iptables-restore conf/iptables.v4
#sudo ip6tables-restore conf/iptables.v6
#sudo service netfilter-persistent save

echo "Disable Openvpn per default"
#sudo systemctl stop openvpn.service
#sudo systemctl disable openvpn.service

echo "System Services enabled, the agent should take care of the rest"
#echo "Rebooting now, should come up with wireless network "Nomad-Hotspot""
#sudo reboot