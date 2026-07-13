#!/usr/bin/env python3
import socket
import sys
import re
import pymysql
from datetime import datetime
import logging
import os

# Configuration Constants
DB_HOST = os.environ.get('DB_HOST', 'localhost')
DB_USER = os.environ.get('DB_USER', 'janus_user')
DB_PASS = os.environ.get('DB_PASS', 'Janus@DB@2026')
DB_NAME = os.environ.get('DB_NAME', 'alogin')
LOG_DIR = '/var/log/janus_siem'
SYSLOG_HOST = '0.0.0.0'
SYSLOG_PORT = 514

# FIX: Create the directory BEFORE configuring logging handlers
if not os.path.exists(LOG_DIR):
    os.makedirs(LOG_DIR, mode=0o755)

# Setup Global Logging Config
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler(os.path.join(LOG_DIR, 'receiver.log')),
        logging.StreamHandler(sys.stdout)
    ]
)

class SyslogReceiver:
    def __init__(self):
        self.db = None
        self.sock_udp = None

    def connect_db(self):
        try:
            self.db = pymysql.connect(
                host=DB_HOST,
                user=DB_USER,
                password=DB_PASS,
                database=DB_NAME,
                charset='utf8mb4',
                cursorclass=pymysql.cursors.DictCursor,
                autocommit=True
            )
            logging.info("Connected to database")
            return True
        except Exception as e:
            logging.error(f"Database error: {e}")
            return False

    def check_source(self, ip):
        try:
            if not self.db or not self.db.open:
                self.connect_db()
            cursor = self.db.cursor()
            cursor.execute("SELECT id, appliance_type, is_active FROM syslog_sources WHERE source_ip = %s", (ip,))
            result = cursor.fetchone()
            cursor.close()
            if result and result['is_active'] == 1:
                return result
            return None
        except Exception as e:
            logging.error(f"Error checking source: {e}")
            return None

    def write_to_db(self, source_id, appliance_type, source_ip, message):
        try:
            if not self.db or not self.db.open:
                self.connect_db()
            cursor = self.db.cursor()
            cursor.execute(
                "INSERT INTO syslog_entries (source_id, appliance_type, source_ip, message) VALUES (%s, %s, %s, %s)",
                (source_id, appliance_type, source_ip, message)
            )
            cursor.close()
            return True
        except Exception as e:
            logging.error(f"DB write error: {e}")
            return False

    def write_to_file(self, ip, appliance_type, message):
        try:
            ip_formatted = ip.replace('.', '-')
            date_str = datetime.now().strftime('%Y-%m-%d')
            filepath = os.path.join(LOG_DIR, f"{ip_formatted}-{date_str}.log")
            timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            log_entry = f"[{timestamp}] [{appliance_type.upper()}] [{ip}] {message}\n"
            
            with open(filepath, 'a') as f:
                f.write(log_entry)
            os.chmod(filepath, 0o644)
            return True
        except Exception as e:
            logging.error(f"File write error: {e}")
            return False

    def parse_syslog(self, data):
        try:
            message = data.decode('utf-8', errors='ignore').strip()
            match = re.match(r'<(\d+)>(.+)', message)
            if match:
                return match.group(2).strip()
            return message
        except Exception:
            return data.decode('utf-8', errors='ignore')

    def process_message(self, data, source_ip):
        try:
            message = self.parse_syslog(data)
            if not message:
                return

            source = self.check_source(source_ip)
            if source:
                self.write_to_db(source['id'], source['appliance_type'], source_ip, message)
                self.write_to_file(source_ip, source['appliance_type'], message)
                logging.info(f"✔ {source_ip} ({source['appliance_type']}): {message[:50]}")
            else:
                logging.warning(f"✗ Rejected: {source_ip}")
        except Exception as e:
            logging.error(f"Process error: {e}")

    def start(self):
        logging.info("=== SIEM Starting ===")
        if not self.connect_db():
            sys.exit(1)
        try:
            self.sock_udp = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            self.sock_udp.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            self.sock_udp.bind((SYSLOG_HOST, SYSLOG_PORT))
            logging.info(f"Listening on {SYSLOG_HOST}:{SYSLOG_PORT}")
            
            while True:
                data, addr = self.sock_udp.recvfrom(65535)
                self.process_message(data, addr[0])
        except KeyboardInterrupt:
            logging.info("Shutting down...")
        finally:
            if self.sock_udp:
                self.sock_udp.close()
            if self.db and self.db.open:
                self.db.close()

if __name__ == '__main__':
    receiver = SyslogReceiver()
    receiver.start()
