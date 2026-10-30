import os
from ftplib import FTP
from datetime import datetime
import glob


class FTPUploader:
    # ------------------------------------------------------------------ #
    # Behavior switches
    # ------------------------------------------------------------------ #
    # Delete all remote .csv files in /ECSV before uploading
    DELETE_REMOTE_BEFORE_UPLOAD = True

    # Upload under a fixed name so STOR overwrites the previous file
    USE_FIXED_REMOTE_NAME = True
    FIXED_REMOTE_NAME = "Certified_students_latest.csv"

    def __init__(self):
        self.ftp_host = "ftp.etairtv.com"
        self.ftp_port = 21
        self.ftp_username = "coursetrack@coursetrack.etairtv.com"
        self.ftp_password = "T!!r4Tda!usbZB4&"

        # Jailed FTP path relative to the subdomain root directory:
        self.remote_dir = "/ECSV"
        self.local_dir = r"C:\xampp\htdocs\coursetrack\ECSV"

        self.ftp = None

    # ------------------------------------------------------------------ #
    # Connection
    # ------------------------------------------------------------------ #
    def connect_ftp(self):
        """Connect to FTP server and cd into /ECSV (creating it if needed)."""
        print("Connecting to FTP server...")
        self.ftp = FTP()
        self.ftp.connect(self.ftp_host, self.ftp_port)
        self.ftp.login(self.ftp_username, self.ftp_password)
        print("FTP connection successful!")

        # Change to remote directory, create if it doesn't exist
        try:
            self.ftp.cwd(self.remote_dir)
        except Exception:
            print(f"Creating remote directory: {self.remote_dir}")
            directories = self.remote_dir.split('/')
            current_path = ''
            for dir_name in directories:
                if dir_name:
                    current_path += '/' + dir_name
                    try:
                        self.ftp.cwd(current_path)
                    except Exception:
                        self.ftp.mkd(current_path)
                        self.ftp.cwd(current_path)

        print(f"Current remote directory: {self.ftp.pwd()}")

    # ------------------------------------------------------------------ #
    # Local file lookup
    # ------------------------------------------------------------------ #
    def get_latest_csv(self):
        """Get the most recently downloaded CSV file locally."""
        csv_files = glob.glob(
            os.path.join(self.local_dir, "Certified_students_from_*.csv")
        )

        # Fallback: any CSV
        if not csv_files:
            csv_files = glob.glob(os.path.join(self.local_dir, "*.csv"))

        if not csv_files:
            raise Exception("No CSV files found in local directory")

        latest_file = max(csv_files, key=os.path.getctime)
        print(f"Found latest CSV: {os.path.basename(latest_file)}")
        return latest_file

    # ------------------------------------------------------------------ #
    # Remote cleanup (optional)
    # ------------------------------------------------------------------ #
    def delete_remote_csvs(self):
        """Delete all existing .csv files in the remote /ECSV directory."""
        print("Cleaning remote CSV files...")
        try:
            files = self.ftp.nlst()
        except Exception as e:
            print(f"Could not list remote files (skipping cleanup): {e}")
            return

        deleted = 0
        for name in files:
            base = os.path.basename(name)
            if base.lower().endswith(".csv"):
                try:
                    self.ftp.delete(name)
                    print(f"Deleted remote file: {base}")
                    deleted += 1
                except Exception as e:
                    print(f"Failed to delete {base}: {e}")

        if deleted == 0:
            print("No remote CSV files to delete.")

    # ------------------------------------------------------------------ #
    # Upload
    # ------------------------------------------------------------------ #
    def upload_file(self, local_file_path):
        """Upload a local file to the remote /ECSV directory."""
        filename = os.path.basename(local_file_path)

        if self.USE_FIXED_REMOTE_NAME:
            remote_name = self.FIXED_REMOTE_NAME
            print(f"Uploading {filename} -> {remote_name} (overwrite mode)...")
        else:
            remote_name = filename
            print(f"Uploading {filename} (keep-name mode)...")

        with open(local_file_path, 'rb') as file:
            self.ftp.storbinary(f'STOR {remote_name}', file)

        print(f"File uploaded successfully: {remote_name}")

        # Verify
        remote_files = self.ftp.nlst()
        remote_basenames = [os.path.basename(f) for f in remote_files]
        if remote_name in remote_basenames:
            print("Upload verified successfully!")
            return True
        else:
            print("Warning: File may not have uploaded correctly")
            return False

    # ------------------------------------------------------------------ #
    # Listing / cleanup helpers
    # ------------------------------------------------------------------ #
    def list_remote_files(self):
        """List files in remote directory."""
        print("\nFiles in remote directory:")
        try:
            files = self.ftp.nlst()
        except Exception as e:
            print(f"  (could not list: {e})")
            return

        for file in files:
            try:
                size = self.ftp.size(file)
                print(f"  - {os.path.basename(file)} ({size} bytes)")
            except Exception:
                print(f"  - {os.path.basename(file)}")

    def cleanup_old_files(self, keep_latest=5):
        """Clean up old local CSV files (keeps the N most recent)."""
        csv_files = glob.glob(
            os.path.join(self.local_dir, "Certified_students_from_*.csv")
        )

        if len(csv_files) > keep_latest:
            csv_files.sort(key=os.path.getctime)
            files_to_delete = csv_files[:-keep_latest]

            for file_path in files_to_delete:
                try:
                    os.remove(file_path)
                    print(f"Deleted old local file: {os.path.basename(file_path)}")
                except Exception as e:
                    print(f"Could not delete {file_path}: {e}")

    # ------------------------------------------------------------------ #
    # Close
    # ------------------------------------------------------------------ #
    def close_ftp(self):
        """Close FTP connection."""
        if not self.ftp:
            return
        try:
            self.ftp.quit()
        except Exception:
            try:
                self.ftp.close()
            except Exception:
                pass
        print("FTP connection closed")

    # ------------------------------------------------------------------ #
    # Main flow
    # ------------------------------------------------------------------ #
    def run(self):
        """Main execution for FTP upload."""
        try:
            self.connect_ftp()

            # Optional remote cleanup
            if self.DELETE_REMOTE_BEFORE_UPLOAD:
                self.delete_remote_csvs()

            latest_csv = self.get_latest_csv()
            success = self.upload_file(latest_csv)

            if success:
                self.list_remote_files()
                # Optional: clean up old LOCAL files
                # self.cleanup_old_files()

            return success

        except Exception as e:
            print(f"FTP upload error: {str(e)}")
            raise
        finally:
            self.close_ftp()


# ---------------------------------------------------------------------- #
# Run FTP uploader
# ---------------------------------------------------------------------- #
if __name__ == "__main__":
    uploader = FTPUploader()
    try:
        uploader.run()
        print("\nSUCCESS! FTP upload completed.")
    except Exception as e:
        print(f"\nFAILED: {str(e)}")