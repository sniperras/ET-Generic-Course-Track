import time
import os
import shutil
from datetime import datetime, timedelta
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.common.exceptions import (
    StaleElementReferenceException,
    ElementNotInteractableException,
)
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
        # Headless (no GUI) + stability flags for silent mode
        chrome_options.add_argument("--headless=new")
        chrome_options.add_argument("--window-size=1920,1080")
        chrome_options.add_argument("--disable-gpu")
        chrome_options.add_argument("--no-sandbox")
        chrome_options.add_argument("--disable-dev-shm-usage")

        self.driver = webdriver.Chrome(options=chrome_options)
        self.wait = WebDriverWait(self.driver, 20)

    # ------------------------------------------------------------------ #
    # Login  (STALE-SAFE)
    # ------------------------------------------------------------------ #
    def _fill_field(self, field_id, value, label, max_attempts=4):
        """Fill a text field, retrying on stale-element / not-interactable."""
        last_exc = None
        for attempt in range(1, max_attempts + 1):
            try:
                field = self.wait.until(
                    EC.element_to_be_clickable((By.ID, field_id))
                )
                self.driver.execute_script(
                    "arguments[0].scrollIntoView({block:'center'});", field
                )
                time.sleep(0.3)

                try:
                    field.clear()
                    field.click()
                    field.send_keys(value)
                    return True
                except (StaleElementReferenceException, ElementNotInteractableException) as e:
                    last_exc = e
                    # Fresh re-acquire + JS fallback
                    try:
                        field = self.wait.until(
                            EC.presence_of_element_located((By.ID, field_id))
                        )
                        self.driver.execute_script(
                            "arguments[0].value = arguments[1];", field, value
                        )
                        return True
                    except StaleElementReferenceException:
                        pass

            except (StaleElementReferenceException, ElementNotInteractableException) as e:
                last_exc = e

            print(f"{label}: attempt {attempt} hit "
                  f"{type(last_exc).__name__ if last_exc else 'error'}, retrying...")
            time.sleep(1.0)

        raise Exception(f"Could not fill {label} after {max_attempts} attempts: {last_exc}")

    def login(self):
        """Login to the website"""
        print("Logging in...")
        self.driver.get(self.login_url)

        self.wait.until(EC.presence_of_element_located((By.ID, "username")))
        time.sleep(1.5)

        self._fill_field("username", self.username, "Username")
        time.sleep(1.2)  # let Moodle JS re-render before touching password
        self._fill_field("password", self.password, "Password")
        time.sleep(0.5)

        login_button = self.wait.until(
            EC.element_to_be_clickable((By.ID, "loginbtn"))
        )
        self.driver.execute_script(
            "arguments[0].scrollIntoView({block:'center'});", login_button
        )
        try:
            login_button.click()
        except Exception:
            print("Login button click failed; trying JS click...")
            self.driver.execute_script("arguments[0].click();", login_button)

        time.sleep(3)
        if "login/index.php" in self.driver.current_url:
            body_text = ""
            try:
                body_text = self.driver.find_element(By.TAG_NAME, "body").text
            except Exception:
                pass
            raise Exception(
                f"Login failed: still on login page. URL={self.driver.current_url}. "
                f"Page snippet: {body_text[:300]}"
            )

        print("Login successful!")

    def navigate_to_report(self):
        """Navigate to the report page"""
        print("Navigating to report page...")
        self.driver.get(self.target_url)
        time.sleep(3)

        if "myreport/report.php" in self.driver.current_url:
            print("Already on report page")
        else:
            try:
                report_link = self.wait.until(
                    EC.element_to_be_clickable((By.LINK_TEXT, "Certificate Report"))
                )
                report_link.click()
                time.sleep(3)
            except Exception:
                self.driver.get(self.report_url)
                time.sleep(3)

        print(f"Current URL: {self.driver.current_url}")

    def select_previous_month(self):
        """Select the month from one month ago"""
        print("Selecting previous month...")

        today = datetime.now()
        first_day_current_month = today.replace(day=1)
        last_month = first_day_current_month - timedelta(days=1)
        previous_month = last_month.month
        previous_month_str = f"{previous_month:02d}"

        print(f"Selecting month: {previous_month} ({previous_month_str})")

        month_select = self.wait.until(
            EC.presence_of_element_located((By.ID, "date7_month"))
        )
        month_select.click()
        time.sleep(1)

        month_option = month_select.find_element(
            By.XPATH, f".//option[@value='{previous_month_str}']"
        )
        month_option.click()
        time.sleep(1)

        print(f"Selected month: {month_option.text}")

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

            dates = re.findall(r'(\d{4}-\d{2}-\d{2})', caption_text)

            if len(dates) >= 2:
                return dates[0], dates[1]

            today = datetime.now()
            first_day_current_month = today.replace(day=1)
            last_month_date = first_day_current_month - timedelta(days=1)
            start_date = last_month_date.replace(day=1).strftime("%Y-%m-%d")
            end_date = last_month_date.strftime("%Y-%m-%d")
            return start_date, end_date

        except Exception as e:
            print(f"Could not extract date range: {e}")
            today = datetime.now()
            first_day_current_month = today.replace(day=1)
            last_month_date = first_day_current_month - timedelta(days=1)
            start_date = last_month_date.replace(day=1).strftime("%Y-%m-%d")
            end_date = last_month_date.strftime("%Y-%m-%d")
            return start_date, end_date

    def download_csv(self):
        """Click the Export (CSV) button and download the file safely"""
        print("Looking for Export CSV button...")

        wait = WebDriverWait(self.driver, 20)

        print("Cleaning download directory...")
        for filename in os.listdir(self.download_dir):
            file_path = os.path.join(self.download_dir, filename)
            try:
                if os.path.isfile(file_path):
                    os.remove(file_path)
            except Exception as e:
                print(f"Could not delete {file_path}: {e}")

        export_button = wait.until(
            EC.element_to_be_clickable((By.XPATH, "//button[contains(text(),'Export')]"))
        )
        self.driver.execute_script("arguments[0].scrollIntoView(true);", export_button)
        try:
            export_button.click()
        except Exception:
            print("Normal click failed, using JavaScript click...")
            self.driver.execute_script("arguments[0].click();", export_button)

        print("CSV download triggered... waiting for file")

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
        latest_file = os.path.join(self.download_dir, downloaded_files[0])
        print(f"Downloaded file detected: {latest_file}")

        start_date, end_date = self.extract_date_range_from_page()
        new_filename = f"Certified_students_from_{start_date}_to_{end_date}_for_course_All.csv"
        new_filename = new_filename.replace(":", "-").replace("/", "-")

        new_filepath = os.path.join(self.download_dir, new_filename)
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
        if self.driver:
            self.driver.quit()


# Run the scraper
if __name__ == "__main__":
    scraper = ETLearningScraper()
    scraper.run()