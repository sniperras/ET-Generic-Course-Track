import os
from ftplib import FTP
from datetime import datetime
import glob

class FTPUploader:
    def __init__(self):
        self.ftp_host = "ftp.etairtv.com"
        self.ftp_port = 21
        self.ftp_username = "coursetrack@coursetrack.etairtv.com"
        self.ftp_password = "T!!r4Tda!usbZB4&"  # Direct password string
        
        # Jailed FTP path relative to the subdomain root directory:
        self.remote_dir = "/ECSV" 
        self.local_dir = r"C:\xampp\htdocs\coursetrack\ECSV"
        
    def connect_ftp(self):
        """Connect to FTP server"""
        print("Connecting to FTP server...")
        self.ftp = FTP()
        self.ftp.connect(self.ftp_host, self.ftp_port)
        self.ftp.login(self.ftp_username, self.ftp_password)
        print("FTP connection successful!")
        
        # Change to remote directory, create if doesn't exist
        try:
            self.ftp.cwd(self.remote_dir)
        except:
            print(f"Creating remote directory: {self.remote_dir}")
            # Create directory structure
            directories = self.remote_dir.split('/')
            current_path = ''
            for dir_name in directories:
                if dir_name:
                    current_path += '/' + dir_name
                    try:
                        self.ftp.cwd(current_path)
                    except:
                        self.ftp.mkd(current_path)
                        self.ftp.cwd(current_path)
            
        print(f"Current remote directory: {self.ftp.pwd()}")
        
    def get_latest_csv(self):
        """Get the most recently downloaded CSV file"""
        csv_files = glob.glob(os.path.join(self.local_dir, "Certified_students_from_*.csv"))
        
        # Also check for any CSV files if pattern doesn't match
        if not csv_files:
            csv_files = glob.glob(os.path.join(self.local_dir, "*.csv"))
        
        if not csv_files:
            raise Exception("No CSV files found in local directory")
        
        # Get the latest file based on creation time
        latest_file = max(csv_files, key=os.path.getctime)
        print(f"Found latest CSV: {os.path.basename(latest_file)}")
        return latest_file
        
    def upload_file(self, local_file_path):
        """Upload file to FTP server"""
        filename = os.path.basename(local_file_path)
        
        print(f"Uploading {filename} to FTP...")
        
        # Open file in binary mode and upload
        with open(local_file_path, 'rb') as file:
            self.ftp.storbinary(f'STOR {filename}', file)
            
        print(f"File uploaded successfully: {filename}")
        
        # Verify upload
        remote_files = self.ftp.nlst()
        if filename in remote_files:
            print("Upload verified successfully!")
            return True
        else:
            print("Warning: File may not have uploaded correctly")
            return False
            
    def list_remote_files(self):
        """List files in remote directory"""
        print("\nFiles in remote directory:")
        files = self.ftp.nlst()
        for file in files:
            # Get file size if possible
            try:
                size = self.ftp.size(file)
                print(f"  - {file} ({size} bytes)")
            except:
                print(f"  - {file}")
                
    def cleanup_old_files(self, keep_latest=5):
        """Clean up old files from local directory (optional)"""
        csv_files = glob.glob(os.path.join(self.local_dir, "Certified_students_from_*.csv"))
        
        if len(csv_files) > keep_latest:
            # Sort by creation time, oldest first
            csv_files.sort(key=os.path.getctime)
            files_to_delete = csv_files[:-keep_latest]
            
            for file_path in files_to_delete:
                os.remove(file_path)
                print(f"Deleted old file: {os.path.basename(file_path)}")
                
    def close_ftp(self):
        """Close FTP connection"""
        try:
            self.ftp.quit()
        except:
            self.ftp.close()
        print("FTP connection closed")
        
    def run(self):
        """Main execution for FTP upload"""
        try:
            self.connect_ftp()
            latest_csv = self.get_latest_csv()
            success = self.upload_file(latest_csv)
            if success:
                self.list_remote_files()
                # Optional: Clean up old local files
                # self.cleanup_old_files()
            return success
            
        except Exception as e:
            print(f"FTP upload error: {str(e)}")
            raise
        finally:
            self.close_ftp()

# Run FTP uploader
if __name__ == "__main__":
    uploader = FTPUploader()
    try:
        uploader.run()
        print("\nSUCCESS! FTP upload completed.")
    except Exception as e:
        print(f"\nFAILED: {str(e)}")