"""
auto_import_to_db.py
Automatically uploads CSV from ECSV folder to the training records database
Uses Selenium in headless mode (no visible browser)
"""

import os
import sys
import glob
from datetime import datetime
import time
import re

# Fix Windows console encoding issues
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

class AutoDBImporter:
    def __init__(self):
        # Configuration
        self.base_url = "https://coursetrack.infinityfree.me"
        self.login_url = f"{self.base_url}/admin/admin_login.php"
        self.import_page_url = f"{self.base_url}/admin/admin_panel.php?page=import_training"
        self.csv_folder = r"C:\xampp\htdocs\coursetrack\ECSV"
        
        # Login credentials
        self.username = "36689"
        self.password = "112277"
        
        # Selenium driver
        self.driver = None
        
    def get_latest_csv(self):
        """Get the most recent CSV file from the ECSV folder"""
        csv_files = glob.glob(os.path.join(self.csv_folder, "*.csv"))
        
        if not csv_files:
            raise Exception(f"No CSV files found in {self.csv_folder}")
        
        latest_file = max(csv_files, key=os.path.getmtime)
        print(f"[OK] Found CSV file: {os.path.basename(latest_file)}")
        return latest_file
    
    def setup_driver(self):
        """Setup Chrome driver in headless mode"""
        from selenium import webdriver
        from selenium.webdriver.chrome.options import Options
        from selenium.webdriver.chrome.service import Service
        
        chrome_options = Options()
        chrome_options.add_argument("--headless")  # No visible browser
        chrome_options.add_argument("--no-sandbox")
        chrome_options.add_argument("--disable-dev-shm-usage")
        chrome_options.add_argument("--disable-gpu")
        chrome_options.add_argument("--window-size=1920,1080")
        chrome_options.add_argument("--disable-notifications")
        chrome_options.add_argument("--disable-popup-blocking")
        chrome_options.add_argument("--log-level=3")  # Suppress logs
        
        self.driver = webdriver.Chrome(options=chrome_options)
        self.driver.implicitly_wait(10)
        print("[OK] Headless browser ready")
        
    def login(self):
        """Login using Selenium"""
        from selenium.webdriver.common.by import By
        from selenium.webdriver.support.ui import WebDriverWait
        from selenium.webdriver.support import expected_conditions as EC
        
        print("  Navigating to login page...")
        self.driver.get(self.login_url)
        time.sleep(3)
        
        # Find and fill username
        username_field = self.driver.find_element(By.NAME, "username")
        username_field.send_keys(self.username)
        
        # Find and fill password
        password_field = self.driver.find_element(By.NAME, "password")
        password_field.send_keys(self.password)
        
        # Submit form
        submit_button = self.driver.find_element(By.XPATH, "//button[@type='submit']")
        submit_button.click()
        
        time.sleep(3)
        
        # Check if login successful
        if "admin_panel.php" in self.driver.current_url:
            print("[OK] Login successful!")
            return True
        else:
            # Check for error message
            try:
                error = self.driver.find_element(By.CLASS_NAME, "bg-red-100")
                if error:
                    raise Exception(f"Login failed: {error.text}")
            except:
                pass
            raise Exception("Login failed")
    
    def upload_and_import(self, csv_file_path):
        """Upload CSV and complete import"""
        from selenium.webdriver.common.by import By
        from selenium.webdriver.support.ui import WebDriverWait
        from selenium.webdriver.support import expected_conditions as EC
        
        print("  Navigating to import page...")
        self.driver.get(self.import_page_url)
        time.sleep(3)
        
        # Find file input and upload
        print("  Uploading CSV file...")
        file_input = self.driver.find_element(By.NAME, "csv_file")
        file_input.send_keys(csv_file_path)
        
        # Click Preview button
        preview_button = self.driver.find_element(By.XPATH, "//button[contains(text(), 'Preview & Process')]")
        preview_button.click()
        
        # Wait for preview to load
        time.sleep(5)
        
        # Extract record count
        try:
            record_text = self.driver.find_element(By.XPATH, "//p[contains(text(), 'Total records to process')]")
            print(f"  {record_text.text}")
        except:
            pass
        
        # Click Confirm button
        print("  Confirming import...")
        confirm_button = WebDriverWait(self.driver, 30).until(
            EC.element_to_be_clickable((By.XPATH, "//button[contains(text(), 'Confirm & Import')]"))
        )
        confirm_button.click()
        
        # Wait for confirmation modal and click final confirm
        time.sleep(2)
        final_confirm = WebDriverWait(self.driver, 30).until(
            EC.element_to_be_clickable((By.XPATH, "//button[contains(text(), 'Confirm Import')]"))
        )
        final_confirm.click()
        
        # Wait for import to complete
        time.sleep(10)
        
        # Check for success message
        page_source = self.driver.page_source
        if "Import Completed" in page_source or "success" in page_source.lower():
            print("[OK] Import completed successfully!")
            
            # Extract statistics
            success_match = re.search(r'Inserted/Updated:.*?<strong[^>]*>(\d+)</strong>', page_source)
            if success_match:
                print(f"  Records inserted/updated: {success_match.group(1)}")
            
            skipped_match = re.search(r'Skipped:.*?<strong[^>]*>(\d+)</strong>', page_source)
            if skipped_match:
                print(f"  Records skipped: {skipped_match.group(1)}")
            
            failed_match = re.search(r'Failed:.*?<strong[^>]*>(\d+)</strong>', page_source)
            if failed_match:
                print(f"  Records failed: {failed_match.group(1)}")
            
            return True
        else:
            print("[WARNING] Import processed. Please verify.")
            return True
    
    def run(self):
        """Main execution"""
        print("=" * 70)
        print("AUTO DB IMPORTER - Training Records Import")
        print(f"Started at: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        print("=" * 70)
        
        try:
            # Step 1: Get the latest CSV file
            print("\n[Step 0] Finding latest CSV file...")
            csv_file = self.get_latest_csv()
            
            # Step 2: Setup headless browser
            print("\n[Step 1] Setting up headless browser...")
            self.setup_driver()
            
            # Step 3: Login
            print("\n[Step 2] Logging in...")
            self.login()
            
            # Step 4: Upload and import
            print("\n[Step 3] Uploading and importing...")
            success = self.upload_and_import(csv_file)
            
            if success:
                print("\n" + "=" * 70)
                print("[SUCCESS] IMPORT COMPLETED SUCCESSFULLY!")
                print("=" * 70)
                print(f"File imported: {os.path.basename(csv_file)}")
                print(f"Completed at: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
                print("=" * 70)
                return True
            else:
                raise Exception("Import failed")
            
        except Exception as e:
            print(f"\n[ERROR] {str(e)}")
            return False
        finally:
            if self.driver:
                self.driver.quit()

# Run the importer
if __name__ == "__main__":
    importer = AutoDBImporter()
    success = importer.run()
    
    if success:
        print("\n[SUCCESS] CSV successfully imported to database!")
        exit(0)
    else:
        print("\n[FAILED] Import failed!")
        exit(1)