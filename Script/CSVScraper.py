import time
import os
import shutil
from datetime import datetime, timedelta
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
import re

class ETLearningScraper:
    def __init__(self):
        self.username = "22130"
        self.password = "0920443515@Asm"
        self.login_url = "https://learning1.ethiopianairlines.com/login/index.php"
        self.target_url = "https://learning1.ethiopianairlines.com/mod/url/view.php?id=1153"
        self.report_url = "https://learning1.ethiopianairlines.com/myreport/report.php"
        self.download_dir = r"C:\xampp\htdocs\coursetrack\ECSV"
        self.driver = None
        
        # Ensure download directory exists
        os.makedirs(self.download_dir, exist_ok=True)
        
    def setup_driver(self):
        """Setup Chrome driver with download preferences"""
        chrome_options = Options()
        chrome_options.add_experimental_option("prefs", {
            "download.default_directory": self.download_dir,
            "download.prompt_for_download": False,
            "download.directory_upgrade": True,
            "safebrowsing.enabled": True
        })
        chrome_options.add_argument("--disable-notifications")
        # Uncomment below for headless mode (no GUI)
        chrome_options.add_argument("--headless")
        
        self.driver = webdriver.Chrome(options=chrome_options)
        self.driver.maximize_window()
        self.wait = WebDriverWait(self.driver, 20)
        
    def login(self):
        """Login to the website"""
        print("Logging in...")
        self.driver.get(self.login_url)
        
        # Wait for username field and enter credentials
        username_field = self.wait.until(
            EC.presence_of_element_located((By.ID, "username"))
        )
        username_field.clear()
        username_field.send_keys(self.username)
        
        password_field = self.driver.find_element(By.ID, "password")
        password_field.clear()
        password_field.send_keys(self.password)
        
        # Click login button
        login_button = self.driver.find_element(By.ID, "loginbtn")
        login_button.click()
        
        # Wait for login to complete
        time.sleep(3)
        print("Login successful!")
        
    def navigate_to_report(self):
        """Navigate to the report page"""
        print("Navigating to report page...")
        self.driver.get(self.target_url)
        time.sleep(3)
        
        # Check if redirected to report page
        if "myreport/report.php" in self.driver.current_url:
            print("Already on report page")
        else:
            # Look for report link
            try:
                report_link = self.wait.until(
                    EC.element_to_be_clickable((By.LINK_TEXT, "Certificate Report"))
                )
                report_link.click()
                time.sleep(3)
            except:
                # Try to find any link to the report
                self.driver.get(self.report_url)
                time.sleep(3)
                
        print(f"Current URL: {self.driver.current_url}")
        
    def select_previous_month(self):
        """Select the month from one month ago"""
        print("Selecting previous month...")
        
        # Calculate previous month
        today = datetime.now()
        first_day_current_month = today.replace(day=1)
        last_month = first_day_current_month - timedelta(days=1)
        previous_month = last_month.month
        previous_month_str = f"{previous_month:02d}"
        
        print(f"Selecting month: {previous_month} ({previous_month_str})")
        
        # Find month selector
        month_select = self.wait.until(
            EC.presence_of_element_located((By.ID, "date7_month"))
        )
        
        # Click and select the previous month
        month_select.click()
        time.sleep(1)
        
        # Find option with the previous month
        month_option = month_select.find_element(By.XPATH, f".//option[@value='{previous_month_str}']")
        month_option.click()
        time.sleep(1)
        
        print(f"Selected month: {month_option.text}")
        
        # Click submit button
        submit_button = self.driver.find_element(By.ID, "button4")
        submit_button.click()
        
        print("Form submitted, waiting 15 seconds for data to load...")
        time.sleep(15)
        
    def extract_date_range_from_page(self):
        """Extract date range from the page caption"""
        try:
            caption_element = self.wait.until(
                EC.presence_of_element_located((By.CLASS_NAME, "caption"))
            )
            caption_text = caption_element.text
            print(f"Found caption: {caption_text}")
            
            # Extract dates using regex
            date_pattern = r'(\d{4}-\d{2}-\d{2})'
            dates = re.findall(date_pattern, caption_text)
            
            if len(dates) >= 2:
                start_date = dates[0]
                end_date = dates[1]
                return start_date, end_date
            else:
                # If pattern not found, generate dates based on current month
                today = datetime.now()
                first_day_current_month = today.replace(day=1)
                last_month_date = first_day_current_month - timedelta(days=1)
                start_date = last_month_date.replace(day=1).strftime("%Y-%m-%d")
                end_date = last_month_date.strftime("%Y-%m-%d")
                return start_date, end_date
                
        except Exception as e:
            print(f"Could not extract date range: {e}")
            # Fallback to previous month dates
            today = datetime.now()
            first_day_current_month = today.replace(day=1)
            last_month_date = first_day_current_month - timedelta(days=1)
            start_date = last_month_date.replace(day=1).strftime("%Y-%m-%d")
            end_date = last_month_date.strftime("%Y-%m-%d")
            return start_date, end_date
            
    def download_csv(self):
        """Click the Export (CSV) button and download the file safely"""
        import os
        import time
        import shutil
        from selenium.webdriver.common.by import By
        from selenium.webdriver.support.ui import WebDriverWait
        from selenium.webdriver.support import expected_conditions as EC

        print("Looking for Export CSV button...")

        wait = WebDriverWait(self.driver, 20)

        # ✅ CLEAN DOWNLOAD FOLDER FIRST
        print("Cleaning download directory...")
        for filename in os.listdir(self.download_dir):
            file_path = os.path.join(self.download_dir, filename)
            try:
                if os.path.isfile(file_path):
                    os.remove(file_path)
            except Exception as e:
                print(f"Could not delete {file_path}: {e}")

        # Locate button
        export_button = wait.until(
            EC.element_to_be_clickable((By.XPATH, "//button[contains(text(),'Export')]"))
        )

        self.driver.execute_script("arguments[0].scrollIntoView(true);", export_button)

        try:
            export_button.click()
        except:
            print("Normal click failed, using JavaScript click...")
            self.driver.execute_script("arguments[0].click();", export_button)

        print("CSV download triggered... waiting for file")

        # Wait for download
        def wait_for_download(timeout=30):
            seconds = 0
            while seconds < timeout:
                files = os.listdir(self.download_dir)

                csv_files = [
                    f for f in files
                    if f.endswith('.csv') and not f.endswith('.crdownload')
                ]

                if csv_files:
                    return csv_files

                time.sleep(1)
                seconds += 1

            raise Exception("Download timeout: CSV file not found")

        downloaded_files = wait_for_download()

        # Get the downloaded file (only one should exist now)
        latest_file = os.path.join(self.download_dir, downloaded_files[0])

        print(f"Downloaded file detected: {latest_file}")

        # Extract date range
        start_date, end_date = self.extract_date_range_from_page()

        new_filename = f"Certified_students_from_{start_date}_to_{end_date}_for_course_All.csv"
        new_filename = new_filename.replace(":", "-").replace("/", "-")

        new_filepath = os.path.join(self.download_dir, new_filename)

        # Rename (now guaranteed clean)
        shutil.move(latest_file, new_filepath)

        print(f"CSV saved as: {new_filename}")

        return new_filepath
    def run(self):
        """Main execution flow"""
        try:
            self.setup_driver()
            self.login()
            self.navigate_to_report()
            self.select_previous_month()
            csv_file_path = self.download_csv()
            print(f"CSV successfully downloaded to: {csv_file_path}")
            return csv_file_path
            
        except Exception as e:
            print(f"Error occurred: {str(e)}")
            raise
        finally:
            if self.driver:
                print("Closing browser...")
                self.driver.quit()
                
    def close(self):
        """Close the driver"""
        if self.driver:
            self.driver.quit()

# Run the scraper
if __name__ == "__main__":
    scraper = ETLearningScraper()
    scraper.run()


