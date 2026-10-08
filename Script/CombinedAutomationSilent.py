"""
Combined Automation Script with Silent Mode for Task Scheduler
Runs CSVScraper.py first, then FTPUploader.py, then auto_import_to_db.py
Logs all output to file without showing console window
FORCES all subprocesses to run silently
"""

import subprocess
import sys
import os
from datetime import datetime

# Silent mode detection - check if running from Task Scheduler
def is_running_from_task_scheduler():
    """Check if script is running from Task Scheduler (no console)"""
    try:
        if sys.platform == 'win32':
            import ctypes
            kernel32 = ctypes.windll.kernel32
            console_window = kernel32.GetConsoleWindow()
            return console_window == 0
    except:
        pass
    return False

# Determine if we're in silent mode (no console output)
SILENT_MODE = is_running_from_task_scheduler()

# Force silent mode from command line
if '--silent' in sys.argv:
    SILENT_MODE = True

# Fix Windows console encoding issues (only if not silent)
if sys.platform == 'win32' and not SILENT_MODE:
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

# Create logs directory if it doesn't exist
LOG_DIR = "logs"
os.makedirs(LOG_DIR, exist_ok=True)

def log_message(message, log_file, print_to_console=True):
    """Print and log message - respects silent mode"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    formatted_message = f"[{timestamp}] {message}"
    
    # Only print to console if not in silent mode
    if print_to_console and not SILENT_MODE:
        print(formatted_message, flush=True)
    
    # Always write to log file
    with open(log_file, 'a', encoding='utf-8') as f:
        f.write(formatted_message + '\n')

def run_script_completely_silent(script_name, log_file):
    """
    Run a Python script with ABSOLUTELY NO console windows
    Uses CREATE_NO_WINDOW flag to prevent ANY console windows
    """
    log_message(f"Starting: {script_name}", log_file, print_to_console=False)
    log_message("="*60, log_file, print_to_console=False)
    
    try:
        # Get the full path to the script
        script_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), script_name)
        
        # Prepare startup info to hide windows
        startupinfo = subprocess.STARTUPINFO()
        startupinfo.dwFlags |= subprocess.STARTF_USESHOWWINDOW
        startupinfo.wShowWindow = subprocess.SW_HIDE
        
        # Set creation flags to prevent any console window
        creation_flags = 0
        if sys.platform == 'win32':
            creation_flags = subprocess.CREATE_NO_WINDOW | subprocess.SW_HIDE
        
        # Execute script with NO window
        result = subprocess.run(
            [sys.executable, script_path],
            capture_output=True,
            text=True,
            encoding='utf-8',
            errors='replace',
            cwd=os.path.dirname(os.path.abspath(__file__)),
            startupinfo=startupinfo,
            creationflags=creation_flags,
            shell=False  # Important: Don't use shell which can create windows
        )
        
        # Log stdout
        if result.stdout:
            for line in result.stdout.split('\n'):
                if line.strip():
                    log_message(f"  {line.strip()}", log_file, print_to_console=False)
        
        # Log stderr
        if result.stderr:
            for line in result.stderr.split('\n'):
                if line.strip():
                    log_message(f"  ERROR: {line.strip()}", log_file, print_to_console=False)
        
        # Check if successful
        if result.returncode == 0:
            log_message(f"[OK] {script_name} completed successfully!", log_file, print_to_console=False)
            return True
        else:
            log_message(f"[FAILED] {script_name} failed with return code: {result.returncode}", log_file, print_to_console=False)
            return False
            
    except FileNotFoundError:
        log_message(f"[ERROR] {script_name} not found!", log_file, print_to_console=False)
        log_message(f"  Looking for: {script_path}", log_file, print_to_console=False)
        return False
    except Exception as e:
        log_message(f"[ERROR] Error running {script_name}: {str(e)}", log_file, print_to_console=False)
        return False

def run_script_with_pythonw(script_name, log_file):
    """
    Run script using pythonw.exe (no console window at all!)
    This is the most effective method for true silence
    """
    log_message(f"Starting: {script_name} (using pythonw)", log_file, print_to_console=False)
    log_message("="*60, log_file, print_to_console=False)
    
    try:
        # Find pythonw.exe (same directory as python.exe)
        python_dir = os.path.dirname(sys.executable)
        pythonw_exe = os.path.join(python_dir, "pythonw.exe")
        
        # If pythonw.exe doesn't exist, use python.exe with flags
        if not os.path.exists(pythonw_exe):
            pythonw_exe = sys.executable
            use_flags = True
        else:
            use_flags = False
        
        script_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), script_name)
        
        # Prepare command
        if use_flags:
            cmd = [pythonw_exe, script_path]
        else:
            cmd = [pythonw_exe, script_path]
        
        # Run with no window
        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            encoding='utf-8',
            errors='replace',
            cwd=os.path.dirname(os.path.abspath(__file__)),
            creationflags=subprocess.CREATE_NO_WINDOW if sys.platform == 'win32' else 0,
            shell=False
        )
        
        # Log output
        if result.stdout:
            for line in result.stdout.split('\n'):
                if line.strip():
                    log_message(f"  {line.strip()}", log_file, print_to_console=False)
        
        if result.stderr:
            for line in result.stderr.split('\n'):
                if line.strip():
                    log_message(f"  ERROR: {line.strip()}", log_file, print_to_console=False)
        
        if result.returncode == 0:
            log_message(f"[OK] {script_name} completed successfully!", log_file, print_to_console=False)
            return True
        else:
            log_message(f"[FAILED] {script_name} failed with return code: {result.returncode}", log_file, print_to_console=False)
            return False
            
    except Exception as e:
        log_message(f"[ERROR] Error running {script_name}: {str(e)}", log_file, print_to_console=False)
        return False

def main():
    """
    Main execution: Run CSVScraper.py, then FTPUploader.py, then auto_import_to_db.py
    ALL subprocesses run completely silent
    """
    # Create log file with timestamp
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    log_file = os.path.join(LOG_DIR, f"automation_{timestamp}.log")
    
    # Log startup information
    log_message("="*70, log_file, print_to_console=not SILENT_MODE)
    log_message("COMBINED AUTOMATION - ET LEARNING", log_file, print_to_console=not SILENT_MODE)
    log_message(f"Mode: {'SILENT (Task Scheduler)' if SILENT_MODE else 'INTERACTIVE'}", log_file, print_to_console=not SILENT_MODE)
    log_message("="*70, log_file, print_to_console=not SILENT_MODE)
    
    # Import time for delays
    import time
    
    # Choose method - use pythonw for true silence in Task Scheduler
    if SILENT_MODE:
        # Use pythonw method for complete silence
        run_method = run_script_with_pythonw
        log_message("Using pythonw.exe for complete silent execution", log_file, print_to_console=False)
    else:
        # Use interactive method when running manually
        run_method = run_script_completely_silent
    
    # Step 1: Run CSV Scraper
    log_message("[STEP 1 OF 3] Starting CSV scraper...", log_file, print_to_console=not SILENT_MODE)
    if not run_method("CSVScraper.py", log_file):
        log_message("\n[AUTOMATION FAILED] CSV scraper encountered an error!", log_file, print_to_console=not SILENT_MODE)
        log_message(f"Check log file for details: {log_file}", log_file, print_to_console=not SILENT_MODE)
        sys.exit(1)
    
    # Brief pause between scripts
    time.sleep(3)
    
    # Step 2: Run FTP Uploader
    log_message("\n[STEP 2 OF 3] Starting FTP uploader...", log_file, print_to_console=not SILENT_MODE)
    if not run_method("FTPUploader.py", log_file):
        log_message("\n[AUTOMATION FAILED] FTP uploader encountered an error!", log_file, print_to_console=not SILENT_MODE)
        log_message(f"Check log file for details: {log_file}", log_file, print_to_console=not SILENT_MODE)
        sys.exit(1)
    
    # Brief pause before database import
    time.sleep(3)
    
    # Step 3: Run Database Importer
    log_message("\n[STEP 3 OF 3] Starting database import...", log_file, print_to_console=not SILENT_MODE)
    if not run_method("auto_import_to_db.py", log_file):
        log_message("\n[AUTOMATION FAILED] Database import encountered an error!", log_file, print_to_console=not SILENT_MODE)
        log_message(f"Check log file for details: {log_file}", log_file, print_to_console=not SILENT_MODE)
        sys.exit(1)
    
    # Success!
    log_message("\n" + "="*70, log_file, print_to_console=not SILENT_MODE)
    log_message("[SUCCESS] AUTOMATION COMPLETED SUCCESSFULLY!", log_file, print_to_console=not SILENT_MODE)
    log_message("="*70, log_file, print_to_console=not SILENT_MODE)
    log_message("[OK] CSV file scraped from ET Learning website", log_file, print_to_console=not SILENT_MODE)
    log_message("[OK] CSV file uploaded to FTP server", log_file, print_to_console=not SILENT_MODE)
    log_message("[OK] CSV file imported to database", log_file, print_to_console=not SILENT_MODE)
    log_message(f"[OK] Log file saved to: {log_file}", log_file, print_to_console=not SILENT_MODE)
    log_message("="*70, log_file, print_to_console=not SILENT_MODE)

if __name__ == "__main__":
    main()