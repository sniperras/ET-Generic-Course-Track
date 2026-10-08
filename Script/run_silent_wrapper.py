"""
Ultimate Silent Wrapper - Forces ALL subprocesses to run with NO console windows
Run this with pythonw.exe for complete silence
"""

import subprocess
import sys
import os
from datetime import datetime

# Log file
LOG_DIR = "logs"
os.makedirs(LOG_DIR, exist_ok=True)
log_file = os.path.join(LOG_DIR, f"wrapper_{datetime.now().strftime('%Y%m%d_%H%M%S')}.log")

def log(msg):
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    with open(log_file, 'a', encoding='utf-8') as f:
        f.write(f"[{timestamp}] {msg}\n")

def run_hidden(script_name):
    """Run a script with absolutely no windows"""
    script_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), script_name)
    
    # Use CREATE_NO_WINDOW flag (0x08000000)
    CREATE_NO_WINDOW = 0x08000000
    
    try:
        process = subprocess.Popen(
            [sys.executable, script_path],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            creationflags=CREATE_NO_WINDOW,
            shell=False
        )
        
        stdout, stderr = process.communicate()
        
        if stdout:
            log(f"STDOUT from {script_name}: {stdout[:500]}")
        if stderr:
            log(f"STDERR from {script_name}: {stderr[:500]}")
        
        return process.returncode == 0
    except Exception as e:
        log(f"Error running {script_name}: {str(e)}")
        return False

if __name__ == "__main__":
    log("="*60)
    log("Starting silent automation wrapper")
    
    scripts = ["CSVScraper.py", "FTPUploader.py", "auto_import_to_db.py"]
    
    for script in scripts:
        log(f"Running: {script}")
        if run_hidden(script):
            log(f"SUCCESS: {script}")
        else:
            log(f"FAILED: {script}")
            sys.exit(1)
        
        import time
        time.sleep(3)
    
    log("ALL SCRIPTS COMPLETED SUCCESSFULLY")
    log("="*60)