"""
Combined Automation Script with Logging
Runs CSVScraper.py first, then FTPUploader.py, then auto_import_to_db.py upon successful completion
Shows real-time output from subprocesses
"""

import subprocess
import sys
import os
from datetime import datetime

# Fix Windows console encoding issues
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

# Create logs directory if it doesn't exist
LOG_DIR = "logs"
os.makedirs(LOG_DIR, exist_ok=True)

def log_message(message, log_file, print_to_console=True):
    """Print and log message"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    formatted_message = f"[{timestamp}] {message}"
    
    if print_to_console:
        print(formatted_message, flush=True)
    
    with open(log_file, 'a', encoding='utf-8') as f:
        f.write(formatted_message + '\n')

def run_script_realtime(script_name, log_file):
    """
    Run a Python script with real-time output streaming
    Returns True if successful, False otherwise
    """
    log_message(f"Starting: {script_name}", log_file)
    log_message("="*60, log_file)
    
    try:
        # Launch the subprocess with real-time output
        process = subprocess.Popen(
            [sys.executable, script_name],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding='utf-8',
            errors='replace',
            cwd=os.path.dirname(os.path.abspath(__file__)),
            bufsize=1,  # Line buffered
            universal_newlines=True
        )
        
        # Read and display output in real-time
        while True:
            # Read a line from stdout
            stdout_line = process.stdout.readline()
            if stdout_line:
                line = stdout_line.strip()
                if line:
                    log_message(f"  {line}", log_file)
            
            # Read a line from stderr
            stderr_line = process.stderr.readline()
            if stderr_line:
                line = stderr_line.strip()
                if line:
                    log_message(f"  ERROR: {line}", log_file)
            
            # Check if process has finished and no more output
            if not stdout_line and not stderr_line and process.poll() is not None:
                break
        
        # Get return code
        return_code = process.poll()
        
        # Log any remaining output
        remaining_stdout, remaining_stderr = process.communicate()
        if remaining_stdout:
            for line in remaining_stdout.split('\n'):
                if line.strip():
                    log_message(f"  {line.strip()}", log_file)
        if remaining_stderr:
            for line in remaining_stderr.split('\n'):
                if line.strip():
                    log_message(f"  ERROR: {line.strip()}", log_file)
        
        # Check if successful
        if return_code == 0:
            log_message(f"[OK] {script_name} completed successfully!", log_file)
            return True
        else:
            log_message(f"[FAILED] {script_name} failed with return code: {return_code}", log_file)
            return False
            
    except FileNotFoundError:
        log_message(f"[ERROR] {script_name} not found in current directory!", log_file)
        log_message(f"  Make sure {script_name} exists in: {os.getcwd()}", log_file)
        return False
    except Exception as e:
        log_message(f"[ERROR] Error running {script_name}: {str(e)}", log_file)
        return False

def run_script_with_live_output(script_name, log_file):
    """
    Alternative method using Popen with better real-time output
    """
    log_message(f"Starting: {script_name}", log_file)
    log_message("="*60, log_file)
    
    try:
        # Launch the subprocess
        process = subprocess.Popen(
            [sys.executable, script_name],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,  # Combine stderr with stdout
            text=True,
            encoding='utf-8',
            errors='replace',
            cwd=os.path.dirname(os.path.abspath(__file__)),
            bufsize=1
        )
        
        # Read and display output line by line in real-time
        for line in iter(process.stdout.readline, ''):
            if line:
                line = line.strip()
                if line:
                    log_message(f"  {line}", log_file)
        
        # Wait for process to complete
        return_code = process.wait()
        
        # Check if successful
        if return_code == 0:
            log_message(f"[OK] {script_name} completed successfully!", log_file)
            return True
        else:
            log_message(f"[FAILED] {script_name} failed with return code: {return_code}", log_file)
            return False
            
    except FileNotFoundError:
        log_message(f"[ERROR] {script_name} not found in current directory!", log_file)
        log_message(f"  Make sure {script_name} exists in: {os.getcwd()}", log_file)
        return False
    except Exception as e:
        log_message(f"[ERROR] Error running {script_name}: {str(e)}", log_file)
        return False

def main():
    """
    Main execution: Run CSVScraper.py, then FTPUploader.py, then auto_import_to_db.py
    """
    # Create log file with timestamp
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    log_file = os.path.join(LOG_DIR, f"automation_{timestamp}.log")
    
    log_message("="*70, log_file)
    log_message("COMBINED AUTOMATION - ET LEARNING", log_file)
    log_message("="*70, log_file)
    
    # Import time for delays
    import time
    
    # Step 1: Run CSV Scraper
    log_message("[STEP 1 OF 3] Starting CSV scraper...", log_file)
    if not run_script_with_live_output("CSVScraper.py", log_file):
        log_message("\n[AUTOMATION FAILED] CSV scraper encountered an error!", log_file)
        log_message(f"Check log file for details: {log_file}", log_file)
        sys.exit(1)
    
    # Brief pause between scripts
    log_message("\nWaiting 3 seconds before starting FTP upload...", log_file)
    time.sleep(3)
    
    # Step 2: Run FTP Uploader
    log_message("\n[STEP 2 OF 3] Starting FTP uploader...", log_file)
    if not run_script_with_live_output("FTPUploader.py", log_file):
        log_message("\n[AUTOMATION FAILED] FTP uploader encountered an error!", log_file)
        log_message(f"Check log file for details: {log_file}", log_file)
        sys.exit(1)
    
    # Brief pause before database import
    log_message("\nWaiting 3 seconds before starting database import...", log_file)
    time.sleep(3)
    
    # Step 3: Run Database Importer
    log_message("\n[STEP 3 OF 3] Starting database import...", log_file)
    if not run_script_with_live_output("auto_import_to_db.py", log_file):
        log_message("\n[AUTOMATION FAILED] Database import encountered an error!", log_file)
        log_message(f"Check log file for details: {log_file}", log_file)
        sys.exit(1)
    
    # Success!
    log_message("\n" + "="*70, log_file)
    log_message("[SUCCESS] AUTOMATION COMPLETED SUCCESSFULLY!", log_file)
    log_message("="*70, log_file)
    log_message("[OK] CSV file scraped from ET Learning website", log_file)
    log_message("[OK] CSV file uploaded to FTP server", log_file)
    log_message("[OK] CSV file imported to database", log_file)
    log_message(f"[OK] Log file saved to: {log_file}", log_file)
    log_message("="*70, log_file)

if __name__ == "__main__":
    main()