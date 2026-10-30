import time
import os
import shutil
from datetime import datetime, timedelta
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait, Select
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

    # ------------------------------------------------------------------ #
    # Driver setup
    # ------------------------------------------------------------------ #
    def setup_driver(self):
        chrome_options = Options()
        chrome_options.add_experimental_option("prefs", {
            "download.default_directory": self.download_dir,
            "download.prompt_for_download": False,
            "download.directory_upgrade": True,
            "safebrowsing.enabled": True
        })
        chrome_options.add_argument("--disable-notifications")
        # Headless (no GUI) — keep this enabled for silent operation
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
        """
        Fill a text field safely.

        Re-acquires the element on every attempt so a DOM re-render
        (StaleElementReferenceException) doesn't abort the whole login.
        Falls back to setting .value via JavaScript if send_keys fails.
        """
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
                    # Try JS fallback with a fresh element
                    try:
                        field = self.wait.until(
                            EC.presence_of_element_located((By.ID, field_id))
                        )
                        self.driver.execute_script(
                            "arguments[0].value = arguments[1];", field, value
                        )
                        return True
                    except StaleElementReferenceException:
                        pass  # will retry at outer loop

            except StaleElementReferenceException as e:
                last_exc = e
            except ElementNotInteractableException as e:
                last_exc = e

            print(f"{label}: attempt {attempt} hit "
                  f"{type(last_exc).__name__ if last_exc else 'error'}, retrying...")
            time.sleep(1.0)

        raise Exception(f"Could not fill {label} after {max_attempts} attempts: {last_exc}")

    def login(self):
        """Login to the website — stale-element-safe."""
        print("Logging in...")
        self.driver.get(self.login_url)

        # Wait for the username field to appear
        self.wait.until(EC.presence_of_element_located((By.ID, "username")))
        time.sleep(1.5)

        # Fill username
        self._fill_field("username", self.username, "Username")

        # Give Moodle's JS a beat — this is where the DOM re-renders
        time.sleep(1.2)

        # Fill password — re-acquired fresh inside _fill_field
        self._fill_field("password", self.password, "Password")

        time.sleep(0.5)

        # Submit
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

        # Verify login succeeded
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

    # ------------------------------------------------------------------ #
    # Navigation
    # ------------------------------------------------------------------ #
    def navigate_to_report(self):
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

    # ------------------------------------------------------------------ #
    # Helpers
    # ------------------------------------------------------------------ #
    @staticmethod
    def _months_back(target_dt, months):
        month = target_dt.month - months
        year = target_dt.year
        while month <= 0:
            month += 12
            year -= 1
        return year, month

    # ------------------------------------------------------------------ #
    # Month selection in 'Exam report by date' form
    # ------------------------------------------------------------------ #
    def select_previous_month(self):
        print("Selecting month for 'Exam report by date' form...")

        today = datetime.now()
        first_day_current_month = today.replace(day=1)

        year, month = self._months_back(first_day_current_month, 2)
        target_month_str = f"{month:02d}"
        target_year_str = str(year)

        print(f"Target month: {year}-{target_month_str}")

        exam_form = self.wait.until(
            EC.presence_of_element_located(
                (By.XPATH, "//form[@action='quiz_report_page.php']")
            )
        )

        month_select = exam_form.find_element(By.ID, "date3_month")
        Select(month_select).select_by_value(target_month_str)

        try:
            year_select = exam_form.find_element(By.ID, "date3_year")
            Select(year_select).select_by_value(target_year_str)
        except Exception as e:
            print(f"Could not set year (non-fatal): {e}")

        submit_button = exam_form.find_element(By.ID, "button2")
        self.driver.execute_script("arguments[0].scrollIntoView(true);", submit_button)
        try:
            submit_button.click()
        except Exception:
            self.driver.execute_script("arguments[0].click();", submit_button)

        print("Exam form submitted, waiting 15 seconds...")
        time.sleep(15)

    # ------------------------------------------------------------------ #
    # Date range extraction (fallback = 2 months back)
    # ------------------------------------------------------------------ #
    def extract_date_range_from_page(self):
        try:
            caption_element = self.wait.until(
                EC.presence_of_element_located((By.CLASS_NAME, "caption"))
            )
            caption_text = caption_element.text
            print(f"Found caption: {caption_text}")

            dates = re.findall(r'(\d{4}-\d{2}-\d{2})', caption_text)
            if len(dates) >= 2:
                return dates[0], dates[1]

            return self._fallback_date_range()

        except Exception as e:
            print(f"Could not extract date range: {e}")
            return self._fallback_date_range()

    def _fallback_date_range(self):
        today = datetime.now()
        first_day_current_month = today.replace(day=1)
        year, month = self._months_back(first_day_current_month, 2)

        start_date = f"{year}-{month:02d}-01"
        if month == 12:
            next_month_first = datetime(year + 1, 1, 1)
        else:
            next_month_first = datetime(year, month + 1, 1)
        last_day = next_month_first - timedelta(days=1)
        end_date = last_day.strftime("%Y-%m-%d")
        return start_date, end_date

    # ------------------------------------------------------------------ #
    # CSV download
    # ------------------------------------------------------------------ #
    def download_csv(self):
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
            EC.element_to_be_clickable(
                (By.XPATH, "//button[contains(text(),'Export')]")
            )
        )
        self.driver.execute_script("arguments[0].scrollIntoView(true);", export_button)
        try:
            export_button.click()
        except Exception:
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
        new_filename = (
            f"Certified_students_from_{start_date}_to_{end_date}_for_course_All.csv"
        ).replace(":", "-").replace("/", "-")

        new_filepath = os.path.join(self.download_dir, new_filename)
        shutil.move(latest_file, new_filepath)

        print(f"CSV saved as: {new_filename}")
        return new_filepath

    # ------------------------------------------------------------------ #
    # Main flow
    # ------------------------------------------------------------------ #
    def run(self):
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


if __name__ == "__main__":
    scraper = ETLearningScraper()
    scraper.run()