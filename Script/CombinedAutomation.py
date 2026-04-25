"""
Combined Automation Script with Logging
Runs CSVScraper.py first, then FTPUploader.py upon successful completion
"""

import subprocess
import sys
import os
from datetime import datetime

# Create logs directory if it doesn't exist
LOG_DIR = "logs"
os.makedirs(LOG_DIR, exist_ok=True)

def log_message(message, log_file):
    """Print and log message"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    formatted_message = f"[{timestamp}] {message}"
    print(formatted_message)
    
    with open(log_file, 'a', encoding='utf-8') as f:
        f.write(formatted_message + '\n')

def run_script(script_name, log_file):
    """
    Run a Python script and return True if successful, False otherwise
    """
    log_message(f"Starting: {script_name}", log_file)
    log_message("="*60, log_file)
    
    try:
        # Run the script
        result = subprocess.run(
            [sys.executable, script_name],
            capture_output=True,
            text=True,
            cwd=os.path.dirname(os.path.abspath(__file__))
        )
        
        # Log the output
        if result.stdout:
            for line in result.stdout.split('\n'):
                if line.strip():
                    log_message(f"  {line}", log_file)
        
        if result.stderr:
            log_message(f"  ERROR OUTPUT: {result.stderr}", log_file)
        
        # Check if successful
        if result.returncode == 0:
            log_message(f"✓ {script_name} completed successfully!", log_file)
            return True
        else:
            log_message(f"✗ {script_name} failed with return code: {result.returncode}", log_file)
            return False
            
    except FileNotFoundError:
        log_message(f"✗ Error: {script_name} not found in current directory!", log_file)
        log_message(f"  Make sure {script_name} exists in: {os.getcwd()}", log_file)
        return False
    except Exception as e:
        log_message(f"✗ Error running {script_name}: {str(e)}", log_file)
        return False

def main():
    """
    Main execution: Run CSVScraper.py then FTPUploader.py
    """
    # Create log file with timestamp
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    log_file = os.path.join(LOG_DIR, f"automation_{timestamp}.log")
    
    log_message("="*70, log_file)
    log_message("COMBINED AUTOMATION - ET LEARNING", log_file)
    log_message("="*70, log_file)
    
    # Step 1: Run CSV Scraper
    log_message("[STEP 1 OF 2] Starting CSV scraper...", log_file)
    if not run_script("CSVScraper.py", log_file):
        log_message("\n❌ AUTOMATION FAILED: CSV scraper encountered an error!", log_file)
        log_message(f"Check log file for details: {log_file}", log_file)
        sys.exit(1)
    
    # Brief pause between scripts
    log_message("\nWaiting 3 seconds before starting FTP upload...", log_file)
    import time
    time.sleep(3)
    
    # Step 2: Run FTP Uploader
    log_message("\n[STEP 2 OF 2] Starting FTP uploader...", log_file)
    if not run_script("FTPUploader.py", log_file):
        log_message("\n❌ AUTOMATION FAILED: FTP uploader encountered an error!", log_file)
        log_message(f"Check log file for details: {log_file}", log_file)
        sys.exit(1)
    
    # Success!
    log_message("\n" + "="*70, log_file)
    log_message("✅✅✅ AUTOMATION COMPLETED SUCCESSFULLY! ✅✅✅", log_file)
    log_message("="*70, log_file)
    log_message("✓ CSV file scraped from ET Learning website", log_file)
    log_message("✓ CSV file uploaded to FTP server", log_file)
    log_message(f"✓ Log file saved to: {log_file}", log_file)
    log_message("="*70, log_file)

if __name__ == "__main__":
    main()